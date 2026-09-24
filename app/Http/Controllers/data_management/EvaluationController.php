<?php

namespace App\Http\Controllers\data_management;

use App\Http\Controllers\Controller;
use App\Exports\EvaluationQrLinksExport;
use Illuminate\Http\Request;
use App\Models\{Evaluation, EvaluationResponse, Schedule, User, FacultyCourse, Faculty};
use Illuminate\Support\Str;
use App\Support\AuditLogger;
use App\Support\BrandedQrCode;
use App\Support\SectionNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Database\QueryException;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;
use ZipArchive;

class EvaluationController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $shouldFilter = $user && $user->role !== 'Admin';
        $department = trim($user?->department ?? '');
        if ($department === '') {
            $department = trim(optional($user?->faculty)->department ?? '');
        }
        $departmentFilters = Faculty::normalizeDepartmentList($department);

        if ($shouldFilter && empty($departmentFilters)) {
            $evaluations = collect();
            $faculties = collect();
            $academicYearOptions = collect();
            $semesterOptions = collect();
            return view('content.data-management.dm-evaluation', compact(
                'evaluations',
                'faculties',
                'academicYearOptions',
                'semesterOptions'
            ));
        }

        $evaluationsQuery = Evaluation::with(['faculty.faculty', 'responses'])->latest();
        $facultiesQuery = $this->getEligibleFacultyProfiles($shouldFilter ? $departmentFilters : null);

        if ($shouldFilter) {
            $departmentFacultyIds = Faculty::forDepartments($departmentFilters)->pluck('user_id');
            $evaluationsQuery->where(function ($query) use ($departmentFacultyIds, $departmentFilters) {
                if ($departmentFacultyIds->isNotEmpty()) {
                    $query->whereIn('faculty_id', $departmentFacultyIds);
                }

                $query->orWhere(function ($snapshotQuery) use ($departmentFilters) {
                    $this->applyDepartmentFilter($snapshotQuery, $departmentFilters, 'faculty_department_snapshot');
                });
            });
        }

        $evaluations = $this->deduplicateEvaluationsByEmployeeTerm($evaluationsQuery->get());
        $faculties = $facultiesQuery
            ->map(function ($faculty) {
                if (!$faculty->user) {
                    return null;
                }

                return (object) [
                    'id' => $faculty->user->id,
                    'name' => $faculty->user->name,
                    'email' => $faculty->user->email,
                ];
            })
            ->filter()
            ->values();

        $academicYearQuery = FacultyCourse::query();

        if ($shouldFilter) {
            $academicYearQuery->whereHas('faculty', function ($query) use ($departmentFilters) {
                $query->forDepartments($departmentFilters);
            });
        }

        $academicYearOptions = $academicYearQuery
            ->whereNotNull('academic_year')
            ->where('academic_year', '!=', '')
            ->distinct()
            ->orderBy('academic_year')
            ->pluck('academic_year')
            ->values();

        // Use a fixed list of valid semesters to prevent duplicates from inconsistent DB values
        $semesterOptions = collect([
            ['value' => '1st',    'label' => '1st Semester'],
            ['value' => '2nd',    'label' => '2nd Semester'],
            ['value' => 'Summer', 'label' => 'Summer'],
        ]);

        return view('content.data-management.dm-evaluation', compact(
            'evaluations',
            'faculties',
            'academicYearOptions',
            'semesterOptions'
        ));
    }

    public function store(Request $request)
    {
        $request->validate([
            'faculty_id' => 'required|exists:users,id',
            'academic_year' => 'required|string',
            'semester' => 'required|in:1st,2nd,Summer'
        ]);

        $normalizedSemester = $this->normalizeSemesterValue($request->semester) ?? $request->semester;
        $normalizedAcademicYear = $this->normalizeAcademicYear($request->academic_year);

        $facultyProfile = Faculty::where('user_id', $request->faculty_id)->first();
        if (!$facultyProfile) {
            return back()->with('error', 'Selected faculty does not have a faculty profile. Please verify the assignment.');
        }
        $this->enforceDepartmentAccess($facultyProfile);

        if (!$this->facultyHasScheduleForTerm($facultyProfile, $normalizedAcademicYear, $normalizedSemester)) {
            return back()->with('error', 'Selected faculty does not have an assigned course with a schedule for the specified academic year and semester.');
        }

        $facultyUserIds = $this->facultyUserIdsForSameEmployee($facultyProfile);
        $existingEvaluation = $this->findExistingEvaluation(
            $facultyUserIds,
            $normalizedAcademicYear,
            $normalizedSemester
        );

        if ($existingEvaluation) {
            return back()->with('error', 'Evaluation form already exists for this faculty in the selected academic year and semester.');
        }

        // Extra safety: direct DB check with normalized semester value
        $directDuplicate = Evaluation::whereIn('faculty_id', $facultyUserIds)
            ->where('academic_year', $normalizedAcademicYear)
            ->where('semester', $normalizedSemester)
            ->exists();

        if ($directDuplicate) {
            return back()->with('error', 'Evaluation form already exists for this faculty in the selected academic year and semester.');
        }

        $formLink = $this->generateUniqueLink($request->faculty_id, $normalizedAcademicYear, $normalizedSemester);
        $user = User::find($request->faculty_id);
        $snapshot = $user ? $this->buildFacultySnapshot($user, $facultyProfile) : [];

        $evaluation = Evaluation::create([
            'faculty_id' => $request->faculty_id,
            'academic_year' => $normalizedAcademicYear,
            'semester' => $normalizedSemester,
            'form_link' => $formLink,
            ...$snapshot,
        ]);
        

        return back()->with('success', 'Evaluation form generated successfully.');
    }

    public function generateAll(Request $request)
    {
        $request->validate([
            'academic_year' => 'required|string',
            'semester' => 'required|in:1st,2nd,Summer'
        ]);

        $normalizedSemester = $this->normalizeSemesterValue($request->semester) ?? $request->semester;

        $user = auth()->user();
        $shouldFilter = $user && $user->role !== 'Admin';
        $department = trim($user?->department ?? '');
        if ($department === '') {
            $department = trim(optional($user?->faculty)->department ?? '');
        }
        $departmentFilters = Faculty::normalizeDepartmentList($department);
        if ($shouldFilter && empty($departmentFilters)) {
            return back()->with('error', 'No department assigned. Please contact the administrator.');
        }

        $faculties = $this->getEligibleFacultyProfiles($shouldFilter ? $departmentFilters : null);
        $processedEmployeeKeys = [];
        $generated = 0;
        $skipped = 0;
        $skippedFaculties = []; // Array to store skipped faculty names

        foreach ($faculties as $facultyProfile) {
            $user = $facultyProfile->user;
            if (!$user) {
                $skipped++;
                $skippedFaculties[] = 'Unknown faculty (missing user record)';
                continue;
            }

            // Check if faculty has assigned course + schedule
            $hasSchedule = $this->facultyHasScheduleForTerm($facultyProfile, $request->academic_year, $normalizedSemester);

            if (!$hasSchedule) {
                $skipped++;
                $skippedFaculties[] = $user->name . ' (No schedule)';
                continue;
            }

            $employeeKey = $this->facultyEmployeeKey($facultyProfile);
            if (isset($processedEmployeeKeys[$employeeKey])) {
                $skipped++;
                $skippedFaculties[] = $user->name . ' (Duplicate employee number)';
                continue;
            }
            $processedEmployeeKeys[$employeeKey] = true;

            // Skip if evaluation already exists (check with findExistingEvaluation + direct DB check)
            $exists = $this->findExistingEvaluation(
                $this->facultyUserIdsForSameEmployee($facultyProfile),
                $request->academic_year,
                $normalizedSemester
            );

            if (!$exists) {
                $exists = Evaluation::whereIn('faculty_id', $this->facultyUserIdsForSameEmployee($facultyProfile))
                    ->where('academic_year', $request->academic_year)
                    ->where('semester', $normalizedSemester)
                    ->exists();
            }

            if ($exists) {
                $skipped++;
                $skippedFaculties[] = $user->name . ' (Already exists)';
                continue;
            }

            // Generate form link
            $formLink = $this->generateUniqueLink($user->id, $request->academic_year, $normalizedSemester);
            $snapshot = $this->buildFacultySnapshot($user, $facultyProfile);

            Evaluation::create([
                'faculty_id' => $user->id,
                'academic_year' => $request->academic_year,
                'semester' => $normalizedSemester,
                'form_link' => $formLink,
                ...$snapshot,
            ]);

            $generated++;
        }

        // Build success message with skipped faculty details
        $noScheduleCount = collect($skippedFaculties)->filter(fn($f) => str_ends_with($f, '(No schedule)'))->count();
        $alreadyExistsCount = collect($skippedFaculties)->filter(fn($f) => str_ends_with($f, '(Already exists)'))->count();

        $message = "Generated {$generated} new evaluation form(s).";

        if ($skipped > 0) {
            $message .= " Skipped {$skipped} faculty member(s)";
            $details = [];
            if ($noScheduleCount > 0) {
                $details[] = "{$noScheduleCount} with no schedule";
            }
            if ($alreadyExistsCount > 0) {
                $details[] = "{$alreadyExistsCount} already have an evaluation";
            }
            $otherCount = $skipped - $noScheduleCount - $alreadyExistsCount;
            if ($otherCount > 0) {
                $details[] = "{$otherCount} other reason(s)";
            }
            if (!empty($details)) {
                $message .= ' (' . implode(', ', $details) . ').';
            } else {
                $message .= '.';
            }
        }

        return back()->with('success', $message);
    }

    protected function getEligibleFacultyProfiles(?array $departments = null)
    {
        $query = Faculty::with(['user:id,name,email'])
            ->whereHas('facultyCourses', function ($courseQuery) {
                $courseQuery->whereHas('schedules');
            });

        if ($departments !== null && !empty($departments)) {
            $query->forDepartments($departments);
        }

        return $query->get()
            ->sortBy(fn (Faculty $faculty) => strtolower($faculty->user->name ?? ''))
            ->unique(fn (Faculty $faculty) => $this->facultyEmployeeKey($faculty))
            ->values();
    }

    protected function facultyEmployeeKey(Faculty $facultyProfile): string
    {
        $employeeNo = $this->normalizeEmployeeNumber($facultyProfile->employee_no);

        return $employeeNo !== ''
            ? 'employee:' . $employeeNo
            : 'faculty:' . $facultyProfile->id;
    }

    protected function normalizeEmployeeNumber(?string $employeeNo): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/', '', trim((string) $employeeNo)));
    }

    protected function facultyUserIdsForSameEmployee(Faculty $facultyProfile): array
    {
        $employeeNo = $this->normalizeEmployeeNumber($facultyProfile->employee_no);
        if ($employeeNo === '') {
            return array_filter([(int) $facultyProfile->user_id]);
        }

        return Faculty::withTrashed()
            ->whereRaw("LOWER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(employee_no, ''), ' ', ''), '-', ''), '.', ''), ',', '')) = ?", [$employeeNo])
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    protected function evaluationEmployeeTermKey(Evaluation $evaluation): string
    {
        $employeeNo = $this->normalizeEmployeeNumber(optional(optional($evaluation->faculty)->faculty)->employee_no);
        $facultyKey = $employeeNo !== ''
            ? 'employee:' . $employeeNo
            : 'faculty:' . $evaluation->faculty_id;

        return implode('|', [
            $facultyKey,
            $this->normalizeAcademicYear($evaluation->academic_year ?? ''),
            $this->normalizeSemesterValue($evaluation->semester) ?? strtolower((string) $evaluation->semester),
        ]);
    }

    protected function deduplicateEvaluationsByEmployeeTerm($evaluations)
    {
        return $evaluations
            ->groupBy(fn (Evaluation $evaluation) => $this->evaluationEmployeeTermKey($evaluation))
            ->map(function ($duplicates) {
                return $duplicates
                    ->sortByDesc(fn (Evaluation $evaluation) => sprintf(
                        '%08d-%d-%s-%08d',
                        $evaluation->responses->count(),
                        $evaluation->is_active ? 1 : 0,
                        optional($evaluation->created_at)->format('YmdHis') ?? '00000000000000',
                        $evaluation->id
                    ))
                    ->first();
            })
            ->sortByDesc(fn (Evaluation $evaluation) => $evaluation->created_at ?? $evaluation->id)
            ->values();
    }

    protected function facultyHasScheduleForTerm(Faculty $facultyProfile, string $academicYear, string $semester): bool
    {
        return Schedule::whereHas('facultyCourse', function ($query) use ($facultyProfile, $academicYear, $semester) {
            $query->where('faculty_id', $facultyProfile->id)
                ->where('academic_year', $academicYear)
                ->where('semester', $semester);
        })->exists();
    }

    protected function formatSemesterLabel(?string $value): string
    {
        $value = trim($value ?? '');
        if ($value === '') {
            return 'Unknown Semester';
        }

        $normalized = strtolower(preg_replace('/[^a-z0-9]+/', '', $value));

        $mapping = [
            '1' => 'First Semester',
            '1st' => 'First Semester',
            'first' => 'First Semester',
            'firstsem' => 'First Semester',
            'firstsemester' => 'First Semester',
            'semester1' => 'First Semester',
            '2' => 'Second Semester',
            '2nd' => 'Second Semester',
            'second' => 'Second Semester',
            'secondsem' => 'Second Semester',
            'secondsemester' => 'Second Semester',
            'semester2' => 'Second Semester',
            'summer' => 'Summer',
            'summersem' => 'Summer',
            'summersemester' => 'Summer',
            'midyear' => 'Summer',
            '3' => 'Summer',
            '3rd' => 'Summer',
        ];

        return $mapping[$normalized] ?? ucfirst($value);
    }

    /**
     * Normalize academic year format to match database storage.
     * Converts "2025 - 2026" to "2025-2026"
     */
    protected function normalizeAcademicYear(string $academicYear): string
    {
        $academicYear = trim($academicYear);
        
        // Remove all spaces and normalize dashes
        $normalized = preg_replace('/\s*-\s*/', '-', $academicYear);  // "2025 - 2026" → "2025-2026"
        $normalized = preg_replace('/\s+/', '', $normalized);          // Remove any remaining spaces
        
        return $normalized;
    }

    protected function normalizeSemesterValue(?string $value): ?string
    {
        $value = trim($value ?? '');
        if ($value === '') {
            return null;
        }

        $normalized = strtolower(preg_replace('/[^a-z0-9]+/', '', $value));

        $mapping = [
            '1' => '1st',
            '1st' => '1st',
            'first' => '1st',
            'firstsem' => '1st',
            'firstsemester' => '1st',
            'semester1' => '1st',
            '2' => '2nd',
            '2nd' => '2nd',
            'second' => '2nd',
            'secondsem' => '2nd',
            'secondsemester' => '2nd',
            'semester2' => '2nd',
            'summer' => 'Summer',
            'summersem' => 'Summer',
            'summersemester' => 'Summer',
            'midyear' => 'Summer',
            '3' => 'Summer',
            '3rd' => 'Summer',
        ];

        return $mapping[$normalized] ?? null;
    }

    protected function findExistingEvaluation(int|array $facultyIds, string $academicYear, string $semester): ?Evaluation
    {
        $normalizedSemester = $this->normalizeSemesterValue($semester);
        if (!$normalizedSemester) {
            return null;
        }

        // Check all possible semester variants that normalize to the same value
        $semesterVariants = collect([
            '1st', 'first', 'firstsem', 'firstsemester', 'semester1', '1',
            '2nd', 'second', 'secondsem', 'secondsemester', 'semester2', '2',
            'Summer', 'summer', 'summersem', 'summersemester', 'midyear', '3', '3rd',
        ])->filter(function ($v) use ($normalizedSemester) {
            return $this->normalizeSemesterValue($v) === $normalizedSemester;
        })->values()->all();

        $facultyIds = collect((array) $facultyIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($facultyIds)) {
            return null;
        }

        return Evaluation::whereIn('faculty_id', $facultyIds)
            ->where('academic_year', $academicYear)
            ->whereIn('semester', $semesterVariants)
            ->first();
    }

    public function toggleStatus(Request $request, Evaluation $evaluation)
    {
        $previousStatus = $evaluation->is_active;
        $evaluation->update(['is_active' => !$evaluation->is_active]);
        $status = $evaluation->is_active ? 'activated' : 'deactivated';

        AuditLogger::log($evaluation->is_active ? 'evaluation_activated' : 'evaluation_deactivated', [
            'module' => 'Evaluation',
            'description' => "Evaluation form {$status} for {$evaluation->resolved_faculty_name} ({$evaluation->academic_year} - {$evaluation->semester}).",
            'target_type' => Evaluation::class,
            'target_id' => $evaluation->id,
            'before_values' => [
                'is_active' => $previousStatus,
            ],
            'after_values' => [
                'faculty_id' => $evaluation->faculty_id,
                'faculty_name' => $evaluation->resolved_faculty_name,
                'academic_year' => $evaluation->academic_year,
                'semester' => $evaluation->semester,
                'is_active' => $evaluation->is_active,
            ],
            'severity' => $evaluation->is_active ? 'info' : 'warning',
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Evaluation form has been {$status}.",
                'data' => [
                    'id' => $evaluation->id,
                    'is_active' => $evaluation->is_active,
                    'status' => $evaluation->is_active ? 'active' : 'inactive',
                    'status_label' => $evaluation->is_active ? 'Active' : 'Inactive',
                    'toggle_label' => $evaluation->is_active ? 'Deactivate' : 'Activate',
                ],
            ]);
        }

        return back()->with('success', "Evaluation form has been {$status}.");
    }

    public function destroy(Request $request, Evaluation $evaluation)
    {
        if (auth()->user()?->role !== 'Admin') {
            abort(403);
        }

        $logValues = [
            'faculty_id' => $evaluation->faculty_id,
            'faculty_name' => $evaluation->resolved_faculty_name,
            'academic_year' => $evaluation->academic_year,
            'semester' => $evaluation->semester,
            'is_active' => $evaluation->is_active,
            'form_link' => $evaluation->form_link,
        ];

        $evaluation->delete();

        AuditLogger::log('evaluation_deleted', [
            'module' => 'Evaluation',
            'description' => "Evaluation form deleted for {$logValues['faculty_name']} ({$logValues['academic_year']} - {$logValues['semester']}).",
            'target_type' => Evaluation::class,
            'target_id' => $evaluation->id,
            'before_values' => $logValues,
            'severity' => 'danger',
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Evaluation form deleted successfully.',
                'data' => [
                    'id' => $evaluation->id,
                ],
            ]);
        }

        return back()->with('success', 'Evaluation form deleted successfully.');
    }

    public function bulkDestroy(Request $request)
    {
        if (auth()->user()?->role !== 'Admin') {
            abort(403);
        }

        $ids = $request->input('ids', []);

        if (!is_array($ids) || empty($ids)) {
            return response()->json([
                'message' => 'No evaluation ids provided.'
            ], 422);
        }

        $evaluations = Evaluation::whereIn('id', $ids)->get();
        $logValues = $evaluations->map(fn ($evaluation) => [
            'id' => $evaluation->id,
            'faculty_id' => $evaluation->faculty_id,
            'faculty_name' => $evaluation->resolved_faculty_name,
            'academic_year' => $evaluation->academic_year,
            'semester' => $evaluation->semester,
            'is_active' => $evaluation->is_active,
        ])->values()->all();

        $deleted = Evaluation::whereIn('id', $ids)->delete();

        AuditLogger::log('evaluation_bulk_deleted', [
            'module' => 'Evaluation',
            'description' => "Bulk deleted {$deleted} evaluation form(s).",
            'target_type' => Evaluation::class,
            'before_values' => [
                'evaluations' => $logValues,
            ],
            'severity' => 'danger',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Selected evaluations deleted successfully.',
            'deleted' => $deleted,
        ]);
    }

    public function deletedList(): JsonResponse
    {
        if (auth()->user()?->role !== 'Admin') {
            abort(403);
        }

        $evaluations = Evaluation::onlyTrashed()
            ->with('faculty')
            ->withCount('responses')
            ->latest('deleted_at')
            ->limit(100)
            ->get()
            ->map(function (Evaluation $evaluation) {
                return [
                    'id' => $evaluation->id,
                    'faculty_name' => $evaluation->resolved_faculty_name,
                    'department' => $evaluation->resolved_faculty_department ?: 'N/A',
                    'academic_year' => $evaluation->academic_year ?: 'N/A',
                    'semester' => $this->formatSemesterLabel($evaluation->semester),
                    'status' => $evaluation->is_active ? 'Active' : 'Inactive',
                    'responses_count' => $evaluation->responses_count ?? 0,
                    'deleted_at' => optional($evaluation->deleted_at)->format('M d, Y h:i A') ?? 'N/A',
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => $evaluations,
        ]);
    }

    public function restore(int $id): JsonResponse
    {
        if (auth()->user()?->role !== 'Admin') {
            abort(403);
        }

        $evaluation = Evaluation::onlyTrashed()->findOrFail($id);
        $logValues = [
            'faculty_id' => $evaluation->faculty_id,
            'faculty_name' => $evaluation->resolved_faculty_name,
            'academic_year' => $evaluation->academic_year,
            'semester' => $evaluation->semester,
            'is_active' => $evaluation->is_active,
            'form_link' => $evaluation->form_link,
        ];

        $evaluation->restore();

        AuditLogger::log('evaluation_restored', [
            'module' => 'Evaluation',
            'description' => "Restored evaluation form for {$logValues['faculty_name']} ({$logValues['academic_year']} - {$logValues['semester']}).",
            'target_type' => Evaluation::class,
            'target_id' => $evaluation->id,
            'after_values' => $logValues,
            'severity' => 'info',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Evaluation form restored successfully.',
            'data' => [
                'id' => $evaluation->id,
            ],
        ]);
    }

    public function downloadQrCode(Evaluation $evaluation)
    {
        $this->enforceEvaluationAccess($evaluation);
        $faculty = $evaluation->faculty;
        $fileName = "evaluation_qr_{$faculty->name}_{$evaluation->academic_year}_{$evaluation->semester}.png";
        $fileName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $fileName);
        $qrLabelLines = [
            $evaluation->resolved_faculty_name ?: optional($faculty)->name,
            'Academic Year: ' . ($evaluation->academic_year ?: 'N/A'),
            'Semester: ' . $this->formatSemesterLabel($evaluation->semester),
        ];

        $qrCodeImage = BrandedQrCode::pngWithLabel($evaluation->form_link, $qrLabelLines, 8);

        AuditLogger::log('evaluation_qr_downloaded', [
            'module' => 'Evaluation',
            'description' => "Downloaded QR code for {$evaluation->resolved_faculty_name} ({$evaluation->academic_year} - {$evaluation->semester}).",
            'target_type' => Evaluation::class,
            'target_id' => $evaluation->id,
            'after_values' => [
                'faculty_id' => $evaluation->faculty_id,
                'faculty_name' => $evaluation->resolved_faculty_name,
                'academic_year' => $evaluation->academic_year,
                'semester' => $evaluation->semester,
                'file_name' => $fileName,
            ],
            'severity' => 'info',
        ]);

        return response($qrCodeImage)
            ->header('Content-Type', 'image/png')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }

    public function showQrCode(Evaluation $evaluation)
    {
        $this->enforceEvaluationAccess($evaluation);
        $qrCodeImage = BrandedQrCode::png($evaluation->form_link, 6);

        AuditLogger::log('evaluation_qr_generated', [
            'module' => 'Evaluation',
            'description' => "Generated QR code preview for {$evaluation->resolved_faculty_name} ({$evaluation->academic_year} - {$evaluation->semester}).",
            'target_type' => Evaluation::class,
            'target_id' => $evaluation->id,
            'after_values' => [
                'faculty_id' => $evaluation->faculty_id,
                'faculty_name' => $evaluation->resolved_faculty_name,
                'academic_year' => $evaluation->academic_year,
                'semester' => $evaluation->semester,
            ],
            'severity' => 'info',
        ]);

        return response($qrCodeImage)->header('Content-Type', 'image/png');
    }

    public function qrPoster(Evaluation $evaluation)
    {
        $this->enforceEvaluationAccess($evaluation);

        $facultyRecord = Faculty::where('user_id', $evaluation->faculty_id)->first();
        if (!$facultyRecord) {
            abort(404, 'Faculty record not found');
        }

        $schedules = Schedule::with(['facultyCourse.course'])
            ->whereHas('facultyCourse', function ($query) use ($facultyRecord, $evaluation) {
                $query->where('faculty_id', $facultyRecord->id)
                    ->where('academic_year', $evaluation->academic_year)
                    ->where('semester', $evaluation->semester);
            })
            ->orderBy('day')
            ->orderBy('time')
            ->get();

        $qrCodeDataUri = BrandedQrCode::pngDataUri($evaluation->form_link, 8);
        $qrLogoDataUri = null;

        AuditLogger::log('evaluation_qr_poster_viewed', [
            'module' => 'Evaluation',
            'description' => "Opened QR poster for {$evaluation->resolved_faculty_name} ({$evaluation->academic_year} - {$evaluation->semester}).",
            'target_type' => Evaluation::class,
            'target_id' => $evaluation->id,
            'after_values' => [
                'faculty_id' => $evaluation->faculty_id,
                'faculty_name' => $evaluation->resolved_faculty_name,
                'academic_year' => $evaluation->academic_year,
                'semester' => $evaluation->semester,
                'schedule_count' => $schedules->count(),
            ],
            'severity' => 'info',
        ]);

        return view('content.data-management.evaluation-files.qr-poster', compact(
            'evaluation',
            'schedules',
            'qrCodeDataUri',
            'qrLogoDataUri'
        ));
    }

    public function facultyProfile(Evaluation $evaluation): JsonResponse
    {
        $this->enforceEvaluationAccess($evaluation);

        $facultyRecord = Faculty::where('user_id', $evaluation->faculty_id)->first();
        $termSchedules = collect();
        if ($facultyRecord) {
            $termSchedules = Schedule::with(['facultyCourse.course'])
                ->whereHas('facultyCourse', function ($query) use ($facultyRecord, $evaluation) {
                    $query->where('faculty_id', $facultyRecord->id)
                        ->where('academic_year', $evaluation->academic_year)
                        ->where('semester', $evaluation->semester);
                })
                ->orderBy('day')
                ->orderBy('time')
                ->get();
        }

        $facultyEvaluationIds = Evaluation::where('faculty_id', $evaluation->faculty_id)->pluck('id');
        $facultyResponses = EvaluationResponse::whereIn('evaluation_id', $facultyEvaluationIds)->get();
        $termResponses = EvaluationResponse::with(['schedule.facultyCourse.course'])
            ->where('evaluation_id', $evaluation->id)
            ->get();

        $activeLinksCount = $evaluation->is_active ? 1 : 0;

        $qrLinks = collect([$evaluation])
            ->map(function (Evaluation $item) {
                return [
                    'id' => $item->id,
                    'academic_year' => $item->academic_year,
                    'semester' => $this->formatSemesterLabel($item->semester),
                    'status' => $item->is_active ? 'Active' : 'Inactive',
                    'form_link' => $item->form_link,
                    'download_url' => route('dm.evaluation.qr.download', $item),
                    'poster_url' => route('dm.evaluation.qr.poster', $item),
                ];
            })
            ->values();

        $courses = $termSchedules
            ->map(function (Schedule $schedule) {
                $assignment = $schedule->facultyCourse;
                $course = optional($assignment)->course;

                return [
                    'course' => trim(($course->class_code ?? 'N/A') . (($course->subject_code ?? '') !== '' ? ' - ' . $course->subject_code : '')),
                    'subject_type' => ($course->subject_type ?? '') === 'minor' ? 'GenEd' : 'Professional',
                    'section' => $assignment->section ?? 'N/A',
                    'schedule' => Schedule::formatScheduleLabel($schedule->day, $schedule->time),
                ];
            })
            ->unique(fn($item) => $item['course'] . '|' . $item['section'] . '|' . $item['schedule'])
            ->values();

        $latestFeedback = $termResponses
            ->filter(fn($response) => trim((string) $response->feedback_comments) !== '')
            ->sortByDesc('created_at')
            ->take(5)
            ->map(function (EvaluationResponse $response) {
                $course = optional(optional($response->schedule)->facultyCourse)->course;

                return [
                    'feedback' => Str::limit((string) $response->feedback_comments, 140),
                    'rating' => $response->effectiveness_rating ? $response->effectiveness_rating . '/4' : 'N/A',
                    'course' => $course->class_code ?? $response->course_code_snapshot ?? 'N/A',
                    'submitted' => optional($response->created_at)->format('M d, Y h:i A') ?? 'N/A',
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'faculty' => [
                'name' => $evaluation->resolved_faculty_name,
                'email' => $evaluation->resolved_faculty_email,
                'department' => $evaluation->resolved_faculty_department ?: 'N/A',
                'program' => $evaluation->resolved_program_label,
                'academic_year' => $evaluation->academic_year,
                'semester' => $this->formatSemesterLabel($evaluation->semester),
            ],
            'metrics' => [
                'active_links' => $activeLinksCount,
                'total_responses' => $termResponses->count(),
                'faculty_total_responses' => $facultyResponses->count(),
                'average_rating' => $termResponses->count()
                    ? number_format((float) $termResponses->avg('effectiveness_rating'), 2)
                    : 'N/A',
                'courses_handled' => $courses->count(),
            ],
            'courses' => $courses,
            'latest_feedback' => $latestFeedback,
            'qr_links' => $qrLinks,
            'actions' => [
                'responses_url' => route('dm.evaluation.responses', $evaluation) . '?from=dm',
                'download_responses_url' => route('dm.evaluation.responses.export', $evaluation) . '?from=dm',
                'download_qr_url' => route('dm.evaluation.qr.download', $evaluation),
                'poster_url' => route('dm.evaluation.qr.poster', $evaluation),
            ],
        ]);
    }

    private function makeQrCodeSvgDataUri(string $url): string
    {
        return BrandedQrCode::svgDataUri($url, 8);
    }

    public function exportQrLinks(Request $request)
    {
        $validated = $request->validate([
            'academic_year' => 'required|string',
            'semester' => 'required|in:1st,2nd,Summer',
        ]);

        $academicYear = $this->normalizeAcademicYear($validated['academic_year']);
        $semester = $this->normalizeSemesterValue($validated['semester']) ?? $validated['semester'];
        $user = auth()->user();
        $isAdmin = $user && $user->role === 'Admin';
        $departmentFilters = $this->resolveDepartmentScope($user);

        $evaluationsQuery = Evaluation::with('faculty')
            ->withCount('responses')
            ->where('academic_year', $academicYear)
            ->where('semester', $semester)
            ->latest();

        if (!$isAdmin) {
            if (empty($departmentFilters)) {
                return back()->with('error', 'No department assigned. Please contact the administrator.');
            }

            $departmentFacultyIds = Faculty::forDepartments($departmentFilters)->pluck('user_id');
            $evaluationsQuery->where(function ($query) use ($departmentFacultyIds, $departmentFilters) {
                if ($departmentFacultyIds->isNotEmpty()) {
                    $query->whereIn('faculty_id', $departmentFacultyIds);
                }

                $query->orWhere(function ($snapshotQuery) use ($departmentFilters) {
                    $this->applyDepartmentFilter($snapshotQuery, $departmentFilters, 'faculty_department_snapshot');
                });
            });
        }

        $rows = $evaluationsQuery->get()
            ->map(function (Evaluation $evaluation) {
                return [
                    $evaluation->resolved_faculty_name,
                    $evaluation->resolved_faculty_department,
                    $evaluation->resolved_program_label,
                    $evaluation->academic_year,
                    $this->formatSemesterLabel($evaluation->semester),
                    $evaluation->form_link,
                ];
            })
            ->values()
            ->all();

        AuditLogger::log('evaluation_qr_links_exported', [
            'module' => 'Evaluation',
            'description' => $isAdmin
                ? 'Exported all evaluation QR links.'
                : 'Exported department evaluation QR links.',
            'after_values' => [
                'department_scope' => $isAdmin ? ['All Departments'] : $departmentFilters,
                'academic_year' => $academicYear,
                'semester' => $semester,
                'exported_count' => count($rows),
            ],
            'severity' => 'info',
        ]);

        $departmentTag = $isAdmin
            ? 'all-departments'
            : Str::slug(implode('-', $departmentFilters));
        $filename = 'evaluation-qr-links-' . $departmentTag . '-' . $academicYear . '-' . $semester . '-' . now()->format('Ymd-His') . '.xlsx';

        return Excel::download(new EvaluationQrLinksExport($rows), $filename);
    }

    public function exportQrCodesZip(Request $request)
    {
        if (!class_exists(ZipArchive::class)) {
            return response()->json([
                'message' => 'ZIP extension is not enabled on this server.',
            ], 500);
        }

        $validated = $request->validate([
            'academic_year' => 'required|string',
            'semester' => 'required|in:1st,2nd,Summer',
        ]);

        $academicYear = $this->normalizeAcademicYear($validated['academic_year']);
        $semester = $this->normalizeSemesterValue($validated['semester']) ?? $validated['semester'];
        $user = auth()->user();
        $isAdmin = $user && $user->role === 'Admin';
        $departmentFilters = $this->resolveDepartmentScope($user);

        $evaluationsQuery = Evaluation::with('faculty')
            ->where('academic_year', $academicYear)
            ->where('semester', $semester)
            ->orderBy('faculty_name_snapshot')
            ->orderBy('id');

        if (!$isAdmin) {
            if (empty($departmentFilters)) {
                return response()->json([
                    'message' => 'No department assigned. Please contact the administrator.',
                ], 403);
            }

            $departmentFacultyIds = Faculty::forDepartments($departmentFilters)->pluck('user_id');
            $evaluationsQuery->where(function ($query) use ($departmentFacultyIds, $departmentFilters) {
                if ($departmentFacultyIds->isNotEmpty()) {
                    $query->whereIn('faculty_id', $departmentFacultyIds);
                }

                $query->orWhere(function ($snapshotQuery) use ($departmentFilters) {
                    $this->applyDepartmentFilter($snapshotQuery, $departmentFilters, 'faculty_department_snapshot');
                });
            });
        }

        $evaluations = $evaluationsQuery->get();
        if ($evaluations->isEmpty()) {
            return response()->json([
                'message' => 'No QR codes found for the selected academic year and semester.',
            ], 404);
        }

        $departmentTag = $isAdmin
            ? 'all-departments'
            : Str::slug(implode('-', $departmentFilters));
        $filename = 'evaluation-qr-codes-' . $departmentTag . '-' . $academicYear . '-' . $semester . '-' . now()->format('Ymd-His') . '.zip';
        $zipPath = storage_path('app/temp/' . $filename);

        if (!is_dir(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0775, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return response()->json([
                'message' => 'Unable to prepare QR code ZIP file.',
            ], 500);
        }

        $usedNames = [];
        foreach ($evaluations as $evaluation) {
            $facultyName = $evaluation->resolved_faculty_name ?: 'Unknown Faculty';
            $baseName = Str::slug($facultyName, '_');
            if ($baseName === '') {
                $baseName = 'faculty_' . $evaluation->id;
            }

            $entryName = $baseName . '_' . $academicYear . '_' . $semester . '.png';
            if (isset($usedNames[$entryName])) {
                $usedNames[$entryName]++;
                $entryName = $baseName . '_' . $academicYear . '_' . $semester . '_' . $usedNames[$entryName] . '.png';
            } else {
                $usedNames[$entryName] = 1;
            }

            $qrCodeImage = BrandedQrCode::pngWithLabel($evaluation->form_link, [
                $facultyName,
                'Academic Year: ' . ($evaluation->academic_year ?: 'N/A'),
                'Semester: ' . $this->formatSemesterLabel($evaluation->semester),
            ], 8);

            $zip->addFromString($entryName, $qrCodeImage);
        }

        $zip->close();

        AuditLogger::log('evaluation_qr_codes_zip_exported', [
            'module' => 'Evaluation',
            'description' => $isAdmin
                ? 'Exported all evaluation QR codes as ZIP.'
                : 'Exported department evaluation QR codes as ZIP.',
            'after_values' => [
                'department_scope' => $isAdmin ? ['All Departments'] : $departmentFilters,
                'academic_year' => $academicYear,
                'semester' => $semester,
                'exported_count' => $evaluations->count(),
                'file_name' => $filename,
            ],
            'severity' => 'info',
        ]);

        return response()->download($zipPath, $filename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    public function showForm($token)
    {
        $evaluation = Evaluation::where('form_link', 'LIKE', "%{$token}%")
            ->where('is_active', true)
            ->firstOrFail();

        // Get the faculty record for this user
        $facultyRecord = \App\Models\Faculty::where('user_id', $evaluation->faculty_id)->first();

        if (!$facultyRecord) {
            abort(404, 'Faculty record not found');
        }

        $schedules = Schedule::with(['facultyCourse.course'])
            ->whereHas('facultyCourse', function ($query) use ($facultyRecord, $evaluation) {
                $query->where('faculty_id', $facultyRecord->id)
                    ->where('academic_year', $evaluation->academic_year)
                    ->where('semester', $evaluation->semester);
            })
            ->orderBy('created_at', 'desc')
            ->get();

        $studentUserId = auth()->id();
        $canSubmitEvaluation = strtolower((string) auth()->user()?->role) === 'student';
        $today = now()->toDateString();
        $evaluatedScheduleIds = $canSubmitEvaluation
            ? EvaluationResponse::where('evaluation_id', $evaluation->id)
                ->where('student_user_id', $studentUserId)
                ->where('submitted_date', $today)
                ->pluck('schedule_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all()
            : [];

        $schedules = $schedules
            ->sortByDesc(fn ($schedule) => in_array((int) $schedule->id, $evaluatedScheduleIds, true))
            ->unique(function ($schedule) {
                $facultyCourse = $schedule->facultyCourse;
                $course = optional($facultyCourse)->course;
                $scheduleLabel = Schedule::formatScheduleLabel($schedule->day, $schedule->time);

                return implode('|', [
                    $course?->id ?? $course?->class_code ?? '',
                    SectionNormalizer::key($facultyCourse?->section ?? ''),
                    $scheduleLabel,
                ]);
            })
            ->sortByDesc('created_at')
            ->values();

        return view('content.data-management.evaluation-files.evaluation-form', compact('evaluation', 'schedules', 'canSubmitEvaluation', 'evaluatedScheduleIds'));
    }

    public function submitResponse(Request $request, $token)
    {
        $evaluation = Evaluation::where('form_link', 'LIKE', "%{$token}%")
            ->where('is_active', true)
            ->firstOrFail();

        if (strtolower((string) $request->user()?->role) !== 'student') {
            return back()
                ->with('error', 'Only students are allowed to submit an evaluation.')
                ->withInput();
        }

        $request->merge([
            'feedback_comments' => $this->normalizeFeedback($request->input('feedback_comments')),
        ]);

        $request->validate([
            'schedule_id' => 'required|exists:schedules,id',
            'effectiveness_rating' => 'required|in:1,2,3,4',
            'feedback_comments' => ['required', 'string', 'min:20', 'max:500', 'regex:/^[\p{L}\p{N}\s.,!?;:\'"\-\(\)‘’“”]+$/u', 'not_regex:/-{3,}/'],
        ], [
            'feedback_comments.min' => 'Feedback must be at least 20 characters.',
            'feedback_comments.max' => 'Feedback must not exceed 500 characters.',
            'feedback_comments.regex' => 'Feedback can only use letters, numbers, spaces, and basic punctuation.',
            'feedback_comments.not_regex' => 'Repeated dash lines are not allowed in feedback.',
        ]);

        $studentUserId = $request->user()?->id;
        $schedule = Schedule::with(['facultyCourse.course'])->findOrFail($request->schedule_id);
        $course = optional($schedule->facultyCourse)->course;

        $ipAddress = $request->ip();
        $scheduleId = (int) $request->schedule_id;
        $equivalentScheduleIds = $this->equivalentScheduleIds($schedule);
        $submittedDate = now()->toDateString();

        $alreadyEvaluated = EvaluationResponse::where('evaluation_id', $evaluation->id)
            ->whereIn('schedule_id', $equivalentScheduleIds)
            ->where('student_user_id', $studentUserId)
            ->where('submitted_date', $submittedDate)
            ->exists();

        if ($alreadyEvaluated) {
            return back()
                ->with('error', 'Already evaluated today')
                ->withInput();
        }

        $cooldownMinutes = 1; // 1 minute cooldown

        // Check if IP is still in cooldown period for this same real course/section/schedule.
        $lastSubmission = EvaluationResponse::whereIn('schedule_id', $equivalentScheduleIds)
            ->where('ip_address', $ipAddress)
            ->orderBy('created_at', 'desc')
            ->first();

        if ($lastSubmission && now()->lt($lastSubmission->created_at->copy()->addMinutes($cooldownMinutes))) {
            $remainingSeconds = now()->diffInSeconds($lastSubmission->created_at->copy()->addMinutes($cooldownMinutes));
            $remainingTime = gmdate("i:s", $remainingSeconds); // Format as MM:SS

            // Return with a specific cooldown error that the frontend can detect
            return back()->with('error', "Please wait {$remainingTime} before submitting another evaluation for this course.")->withInput();
        }

        try {
            EvaluationResponse::create([
                'evaluation_id' => $evaluation->id,
                'schedule_id' => $request->schedule_id,
                'student_user_id' => $studentUserId,
                'submitted_date' => $submittedDate,
                'ip_address' => $ipAddress,
                'effectiveness_rating' => $request->effectiveness_rating,
                'feedback_comments' => $request->input('feedback_comments'),
                'course_code_snapshot' => $course->class_code ?? null,
                'course_name_snapshot' => $course->subject_code ?? null,
                'schedule_time_snapshot' => $schedule->time,
                'schedule_days_snapshot' => $schedule->day,
            ]);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return back()
                    ->with('error', 'Already evaluated today')
                    ->withInput();
            }

            throw $exception;
        }

        return back()->with('success', 'Thank you! Your evaluation has been submitted successfully.');
    }

    private function normalizeFeedback(?string $feedback): string
    {
        $feedback = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', (string) $feedback);
        $feedback = str_replace(["\xC2\xA0", "\u{00A0}"], ' ', $feedback);
        $feedback = preg_replace('/\s+/u', ' ', $feedback);

        return trim($feedback);
    }

    private function equivalentScheduleIds(Schedule $schedule): array
    {
        $facultyCourse = $schedule->facultyCourse;
        if (!$facultyCourse) {
            return [$schedule->id];
        }

        $sectionKey = SectionNormalizer::key($facultyCourse->section);

        return Schedule::with('facultyCourse')
            ->where(function ($query) use ($schedule) {
                $schedule->day === null
                    ? $query->whereNull('day')
                    : $query->where('day', $schedule->day);
            })
            ->where(function ($query) use ($schedule) {
                $schedule->time === null
                    ? $query->whereNull('time')
                    : $query->where('time', $schedule->time);
            })
            ->whereHas('facultyCourse', function ($query) use ($facultyCourse) {
                $query->where('faculty_id', $facultyCourse->faculty_id)
                    ->where('course_id', $facultyCourse->course_id)
                    ->where('academic_year', $facultyCourse->academic_year)
                    ->where('semester', $facultyCourse->semester);
            })
            ->get()
            ->filter(function (Schedule $candidate) use ($sectionKey) {
                return SectionNormalizer::key($candidate->facultyCourse?->section) === $sectionKey;
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->push((int) $schedule->id)
            ->unique()
            ->values()
            ->all();
    }

    private function generateUniqueLink($facultyId, $academicYear, $semester)
    {
        $faculty = User::find($facultyId);
        $facultySlug = Str::slug($faculty->name, '_');
        $yearSlug = str_replace('-', '_', $academicYear);
        $semesterSlug = strtolower($semester);
        $randomToken = Str::random(12);

        return url("/eval/{$randomToken}");
    }

    protected function countUniqueCoursesFromResponses($responses): int
    {
        return $responses->map(function ($response) {
            return $this->makeResponseCourseKey($response);
        })->unique()->count();
    }

    protected function makeResponseCourseKey(EvaluationResponse $response): string
    {
        if (!empty($response->schedule_id)) {
            return 'schedule_' . $response->schedule_id;
        }

        return 'snapshot_' . implode('|', [
            $response->course_code_snapshot ?? '',
            $response->course_name_snapshot ?? '',
            $response->schedule_time_snapshot ?? '',
            $response->schedule_days_snapshot ?? '',
        ]);
    }

    // public function viewResponses(Evaluation $evaluation, Request $request)
    // {
    //     $this->enforceEvaluationAccess($evaluation);
        
    //     // Get filter parameters from query string
    //     $academicYear = $request->get('academic_year', 'all');
    //     $semester = $request->get('semester', 'all');
    //     $subjectType = $request->get('subject_type', 'all');
        
    //     // Validate subject_type
    //     if (!in_array($subjectType, ['all', 'major', 'minor'], true)) {
    //         $subjectType = 'all';
    //     }
        
    //     // Get all responses for this faculty (not just this evaluation)
    //     // This matches the export logic which also uses faculty_id
    //     $responsesQuery = EvaluationResponse::with(['schedule.facultyCourse.course'])
    //         ->whereHas('schedule.facultyCourse', function ($query) use ($evaluation) {
    //             $query->where('faculty_id', $evaluation->faculty_id);
    //         });
        
    //     // Apply academic_year filter if specified
    //     if ($academicYear !== 'all') {
    //         $responsesQuery->whereHas('schedule.facultyCourse', function ($q) use ($academicYear) {
    //             $q->where('academic_year', $academicYear);
    //         });
    //     }
        
    //     // Apply semester filter if specified
    //     // Handle both "Summer" and "2nd" semester since data may be stored inconsistently
    //     if ($semester !== 'all') {
    //         $responsesQuery->whereHas('schedule.facultyCourse', function ($q) use ($semester) {
    //             $q->where('semester', $semester)
    //               ->orWhere(function ($query) use ($semester) {
    //                   // If looking for Summer, also include 2nd semester
    //                   if ($semester === 'Summer') {
    //                       $query->where('semester', '2nd');
    //                   }
    //                   // If looking for 2nd, also include Summer
    //                   elseif ($semester === '2nd') {
    //                       $query->where('semester', 'Summer');
    //                   }
    //               });
    //         });
    //     }
        
    //     // Apply subject_type filter if specified
    //     if ($subjectType !== 'all') {
    //         $responsesQuery->whereHas('schedule.facultyCourse.course', function ($q) use ($subjectType) {
    //             $q->where('subject_type', $subjectType);
    //         });
    //     }
        
    //     $responses = $responsesQuery->latest()->get();

    //     $coursesEvaluatedCount = $this->countUniqueCoursesFromResponses($responses);

    //     return view('content.data-management.evaluation-files.evaluation-responses', compact(
    //         'evaluation',
    //         'responses',
    //         'coursesEvaluatedCount'
    //     ));
    // }
        public function viewResponses(Evaluation $evaluation)
    {
        $this->enforceEvaluationAccess($evaluation);
        
        // Get filter parameters from query string
        $academicYear = request('academic_year', $evaluation->academic_year);
        $semester = request('semester', $evaluation->semester);
        $subjectType = request('subject_type', 'all');
        $department = trim((string) request('department', ''));
        $program = $this->normalizeDentistryProgram(request('program', 'all'));
        if (!$this->shouldApplyDentistryProgramFilter($department, $program, request()->user())) {
            $program = 'all';
        }
        
        \Log::info('ViewResponses - Starting', [
            'evaluation_id' => $evaluation->id,
            'faculty_id' => $evaluation->faculty_id,
            'academic_year' => $academicYear,
            'semester' => $semester,
            'subject_type' => $subjectType,
            'program' => $program
        ]);
        
        // Build query to fetch responses based on evaluation and filter parameters
        $query = EvaluationResponse::with(['schedule.facultyCourse.course', 'evaluation'])
            ->where('evaluation_id', $evaluation->id);
        
        // Filter by academic_year if provided and not 'all'
        if ($academicYear && $academicYear !== 'all') {
            $query->whereHas('evaluation', function ($q) use ($academicYear) {
                $q->where('academic_year', $academicYear);
            });
        }
        
        // Filter by semester if provided and not 'all'
        if ($semester && $semester !== 'all') {
            $query->whereHas('evaluation', function ($q) use ($semester) {
                $q->where('semester', $semester);
            });
        }
        
        // Filter by subject_type if provided and not 'all'
        if ($subjectType && $subjectType !== 'all') {
            $query->whereHas('schedule.facultyCourse.course', function ($q) use ($subjectType) {
                $q->where('subject_type', $subjectType);
            });
        }
        
        $responses = $query->latest()->get();
        $responses = $this->filterResponsesByRequestedDepartment($responses, $department);
        $responses = $this->filterResponsesByDentistryProgram($responses, $department, $program);
        
        \Log::info('ViewResponses - Query result', [
            'evaluation_id' => $evaluation->id,
            'academic_year' => $academicYear,
            'semester' => $semester,
            'subject_type' => $subjectType,
            'program' => $program,
            'count' => $responses->count()
        ]);
        
        // If no responses found with evaluation_id, try by faculty_id + academic_year + semester
        if ($responses->isEmpty() && $evaluation->faculty_id) {
            \Log::info('ViewResponses - Fallback: Attempt by faculty_id + academic_year + semester', [
                'faculty_id' => $evaluation->faculty_id,
                'academic_year' => $academicYear,
                'semester' => $semester
            ]);
            
            $fallbackQuery = EvaluationResponse::with(['schedule.facultyCourse.course', 'evaluation'])
                ->whereHas('evaluation', function ($q) use ($evaluation, $academicYear, $semester) {
                    $q->where('faculty_id', $evaluation->faculty_id)
                      ->where('academic_year', $academicYear)
                      ->where('semester', $semester)
                      ->where('is_active', true);
                });
            
            if ($subjectType && $subjectType !== 'all') {
                $fallbackQuery->whereHas('schedule.facultyCourse.course', function ($q) use ($subjectType) {
                    $q->where('subject_type', $subjectType);
                });
            }
            
            $responses = $fallbackQuery->latest()->get();
            $responses = $this->filterResponsesByRequestedDepartment($responses, $department);
            $responses = $this->filterResponsesByDentistryProgram($responses, $department, $program);
            
            \Log::info('ViewResponses - Fallback result', [
                'count' => $responses->count()
            ]);
        }

        $coursesEvaluatedCount = $this->countUniqueCoursesFromResponses($responses);

        AuditLogger::log('evaluation_responses_viewed', [
            'module' => 'Evaluation',
            'description' => "Viewed responses for {$evaluation->resolved_faculty_name} ({$academicYear} - {$semester}).",
            'target_type' => Evaluation::class,
            'target_id' => $evaluation->id,
            'after_values' => [
                'faculty_id' => $evaluation->faculty_id,
                'faculty_name' => $evaluation->resolved_faculty_name,
                'academic_year' => $academicYear,
            'semester' => $semester,
            'department' => $department,
            'subject_type' => $subjectType,
            'program' => $program,
            'responses_count' => $responses->count(),
                'courses_evaluated_count' => $coursesEvaluatedCount,
            ],
            'severity' => 'info',
        ]);

        return view('content.data-management.evaluation-files.evaluation-responses', compact(
            'evaluation',
            'responses',
            'coursesEvaluatedCount'
        ));
    }

    public function exportResponses(Evaluation $evaluation, Request $request)
    {
        $this->enforceEvaluationAccess($evaluation);

        $academicYear = $request->get('academic_year', $evaluation->academic_year);
        $semester = $request->get('semester', $evaluation->semester);
        $subjectType = $request->get('subject_type', 'all');
        $department = trim((string) $request->get('department', ''));
        $program = $this->normalizeDentistryProgram($request->get('program', 'all'));
        if (!$this->shouldApplyDentistryProgramFilter($department, $program, $request->user())) {
            $program = 'all';
        }
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        \Log::info('ExportResponses - Starting', [
            'evaluation_id' => $evaluation->id,
            'faculty_id' => $evaluation->faculty_id,
            'academic_year' => $academicYear,
            'semester' => $semester,
            'department' => $department,
            'subject_type' => $subjectType,
            'program' => $program,
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);

        // Start with base query - get all responses for this evaluation
        $responsesQuery = EvaluationResponse::with(['schedule.facultyCourse.course', 'evaluation'])
            ->where('evaluation_id', $evaluation->id);

        // Get all responses first (before date filtering)
        $allResponsesBeforeDateFilter = (clone $responsesQuery)->orderBy('created_at')->get();
        
        \Log::info('ExportResponses - All responses before date filter', [
            'evaluation_id' => $evaluation->id,
            'total_count' => $allResponsesBeforeDateFilter->count(),
            'first_response_created_at' => $allResponsesBeforeDateFilter->first()?->created_at
        ]);

        // If no responses found with evaluation_id, try by faculty_id + academic_year + semester (fallback)
        if ($allResponsesBeforeDateFilter->isEmpty() && $evaluation->faculty_id) {
            \Log::info('ExportResponses - Fallback: Attempt by faculty_id + academic_year + semester', [
                'faculty_id' => $evaluation->faculty_id,
                'academic_year' => $academicYear,
                'semester' => $semester
            ]);
            
            $responsesQuery = EvaluationResponse::with(['schedule.facultyCourse.course', 'evaluation'])
                ->whereHas('evaluation', function ($q) use ($evaluation, $academicYear, $semester) {
                    $q->where('faculty_id', $evaluation->faculty_id)
                      ->where('academic_year', $academicYear)
                      ->where('semester', $semester)
                      ->where('is_active', true);
                });
            
            $allResponsesBeforeDateFilter = (clone $responsesQuery)->orderBy('created_at')->get();
            
            \Log::info('ExportResponses - Fallback result', [
                'count' => $allResponsesBeforeDateFilter->count()
            ]);
        }

        // Apply date filters only if provided
        $start = null;
        $end = null;

        if ($startDate || $endDate) {
            if ($startDate) {
                try {
                    $start = Carbon::parse($startDate)->startOfDay();
                    $responsesQuery->where('created_at', '>=', $start);
                } catch (\Exception $e) {
                    // Invalid date, skip
                }
            }

            if ($endDate) {
                try {
                    $end = Carbon::parse($endDate)->endOfDay();
                    $responsesQuery->where('created_at', '<=', $end);
                } catch (\Exception $e) {
                    // Invalid date, skip
                }
            }
        }

        // Get all responses first
        $allResponses = $responsesQuery->orderBy('created_at')->get();

        \Log::info('ExportResponses - All responses fetched', [
            'evaluation_id' => $evaluation->id,
            'total_count' => $allResponses->count(),
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);

        // Apply academic_year filter in PHP if needed
        if ($academicYear && $academicYear !== 'all') {
            $allResponses = $allResponses->filter(function ($response) use ($academicYear) {
                return $response->evaluation && $response->evaluation->academic_year === $academicYear;
            })->values();
            
            \Log::info('ExportResponses - After academic_year filter', [
                'academic_year' => $academicYear,
                'count' => $allResponses->count()
            ]);
        }

        // Apply semester filter in PHP if needed
        if ($semester && $semester !== 'all') {
            $allResponses = $allResponses->filter(function ($response) use ($semester) {
                return $response->evaluation && $response->evaluation->semester === $semester;
            })->values();
            
            \Log::info('ExportResponses - After semester filter', [
                'semester' => $semester,
                'count' => $allResponses->count()
            ]);
        }

        // Apply subject_type filter in PHP if needed
        if ($subjectType && $subjectType !== 'all') {
            $beforeSubjectFilter = $allResponses->count();
            $allResponses = $allResponses->filter(function ($response) use ($subjectType) {
                $courseSubjectType = $this->resolveResponseSubjectType($response);
                \Log::debug('ExportResponses - Checking subject_type', [
                    'response_id' => $response->id,
                    'course_subject_type' => $courseSubjectType,
                    'filter_subject_type' => $subjectType,
                    'match' => $courseSubjectType === $subjectType
                ]);
                return $courseSubjectType === $subjectType;
            })->values();
            
            \Log::info('ExportResponses - After subject_type filter', [
                'subject_type' => $subjectType,
                'before_count' => $beforeSubjectFilter,
                'after_count' => $allResponses->count()
            ]);
        }

        $responses = $allResponses
            ->filter(fn (EvaluationResponse $response) => $this->responseHasActiveCourseLink($response))
            ->pipe(fn ($collection) => $this->filterResponsesByRequestedDepartment($collection, $department))
            ->pipe(fn ($collection) => $this->filterResponsesByDentistryProgram($collection, $department, $program))
            ->values();

        $facultySlug = Str::slug($evaluation->resolved_faculty_name, '_');
        $dateTag = now()->format('Ymd_His');
        $filename = "evaluation_responses_{$facultySlug}_{$dateTag}.csv";

        return response()->streamDownload(function () use ($responses, $evaluation, $department) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Date',
                'Faculty Name',
                'Faculty Department',
                'Academic Year',
                'Semester',
                'Subject Type',
                'Course Code',
                'Section',
                'Course Name',
                'Effectiveness Rating',
                'Effectiveness Text',
                'Feedback Comments',
            ]);

            foreach ($responses as $response) {
                $exportDepartment = $department !== '' && strtolower($department) !== 'all'
                    ? implode(', ', Faculty::normalizeDepartmentList($department))
                    : $this->resolveResponseDepartmentForExport($response, $evaluation);

                fputcsv($handle, [
                    $response->created_at ? $response->created_at->format('M d, Y') : '',
                    $evaluation->resolved_faculty_name,
                    $exportDepartment,
                    $evaluation->academic_year,
                    $this->formatSemesterLabel($evaluation->semester),
                    $this->formatResponseSubjectType($response),
                    $response->resolved_course_code,
                    $response->schedule?->facultyCourse?->section ?? '',
                    $response->resolved_course_name,
                    $response->effectiveness_rating,
                    $response->effectiveness_text,
                    $response->feedback_comments,
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function responseHasActiveCourseLink(EvaluationResponse $response): bool
    {
        return $response->schedule !== null
            && $response->schedule->facultyCourse !== null
            && $response->schedule->facultyCourse->course !== null;
    }

    private function normalizeDentistryProgram(?string $program): string
    {
        $value = strtolower(trim((string) ($program ?? 'all')));

        if ($value === 'dmd') {
            $value = 'ddm';
        }

        return in_array($value, ['ddm', 'msd'], true) ? $value : 'all';
    }

    private function filterResponsesByDentistryProgram($responses, string $department, string $program)
    {
        $program = $this->normalizeDentistryProgram($program);
        if (!$this->shouldApplyDentistryProgramFilter($department, $program, request()->user())) {
            return $responses;
        }

        $prefixes = $program === 'all' ? ['DDM', 'MSD'] : [strtoupper($program)];

        return $responses
            ->filter(function (EvaluationResponse $response) use ($prefixes) {
                $facultyCourse = $response->schedule?->facultyCourse;
                $course = $facultyCourse?->course;
                $values = [
                    $facultyCourse?->section,
                    $course?->class_code,
                    $course?->subject_code,
                    $response->course_code_snapshot,
                    $response->course_name_snapshot,
                    $response->resolved_course_code,
                    $response->resolved_course_name,
                ];

                foreach ($values as $value) {
                    $normalized = strtoupper(preg_replace('/\s+/', '', (string) ($value ?? '')));
                    foreach ($prefixes as $prefix) {
                        if ($normalized !== '' && str_starts_with($normalized, $prefix)) {
                            return true;
                        }
                    }
                }

                return false;
            })
            ->values();
    }

    private function shouldApplyDentistryProgramFilter(string $department, string $program, $user = null): bool
    {
        if ($department !== 'College of Dentistry') {
            return false;
        }

        if ($this->normalizeDentistryProgram($program) !== 'all') {
            return true;
        }

        return $this->isDentistryProgramRestrictedUser($user ?? request()->user());
    }

    private function isDentistryProgramRestrictedUser($user = null): bool
    {
        if (!$user || $user->role === 'Admin') {
            return false;
        }

        $jobTitle = strtolower(trim((string) ($user->job_title ?? $user->faculty?->job_title ?? '')));

        return in_array($jobTitle, ['dean', 'vice dean'], true);
    }

    private function filterResponsesByRequestedDepartment($responses, string $department)
    {
        $selectedDepartments = Faculty::normalizeDepartmentList($department);

        if (empty($selectedDepartments) || in_array('all', array_map('strtolower', $selectedDepartments), true)) {
            return $responses;
        }

        return $responses
            ->filter(fn (EvaluationResponse $response) => $this->responseMatchesRequestedDepartment($response, $selectedDepartments))
            ->values();
    }

    private function responseMatchesRequestedDepartment(EvaluationResponse $response, array $selectedDepartments): bool
    {
        $facultyCourse = $response->schedule?->facultyCourse;
        $assignmentDepartments = Faculty::normalizeDepartmentList($facultyCourse?->department ?? '');

        if (!empty($assignmentDepartments)) {
            if (collect($assignmentDepartments)->intersect($selectedDepartments)->isNotEmpty()) {
                return true;
            }
        }

        $snapshotDepartments = Faculty::normalizeDepartmentList($response->evaluation?->resolved_faculty_department ?? '');

        return collect($snapshotDepartments)->intersect($selectedDepartments)->isNotEmpty();
    }

    private function resolveResponseDepartmentForExport(EvaluationResponse $response, Evaluation $evaluation): string
    {
        $assignmentDepartments = Faculty::normalizeDepartmentList($response->schedule?->facultyCourse?->department ?? '');

        if (!empty($assignmentDepartments)) {
            return implode(', ', $assignmentDepartments);
        }

        return $evaluation->resolved_faculty_department;
    }

    private function formatResponseSubjectType(EvaluationResponse $response): string
    {
        $subjectType = $this->resolveResponseSubjectType($response);

        return match ($subjectType) {
            'major' => 'Professional Course',
            'minor' => 'Minor Course',
            default => 'N/A',
        };
    }

    private function resolveResponseSubjectType(EvaluationResponse $response): string
    {
        $course = $response->schedule?->facultyCourse?->course;

        return strtolower(trim((string) ($course?->subject_type ?? '')));
    }

    public function convertToShortUrls()
    {
        $evaluations = Evaluation::all();
        $converted = 0;

        foreach ($evaluations as $evaluation) {
            if (strpos($evaluation->form_link, '/evaluation/') !== false) {
                $urlParts = explode('/', $evaluation->form_link);
                $token = end($urlParts);
                $shortUrl = url("/eval/{$token}");
                $evaluation->update(['form_link' => $shortUrl]);
                $converted++;
            }
        }

        return back()->with('success', "Successfully converted {$converted} evaluation URLs to short format for QR code compatibility.");
    }

    public function checkCooldown(Request $request)
    {
        $request->validate([
            'schedule_id' => 'required|exists:schedules,id',
        ]);

        $ipAddress = $request->ip();
        $scheduleId = $request->schedule_id;
        $cooldownMinutes = 1;

        $isInCooldown = EvaluationResponse::isInCooldown($scheduleId, $ipAddress, $cooldownMinutes);
        $remainingSeconds = EvaluationResponse::getRemainingCooldown($scheduleId, $ipAddress, $cooldownMinutes);

        return response()->json([
            'in_cooldown' => $isInCooldown,
            'remaining_seconds' => $remainingSeconds,
            'remaining_time' => $remainingSeconds > 0 ? gmdate("i:s", $remainingSeconds) : '00:00'
        ]);
    }

    private function buildFacultySnapshot(User $user, ?Faculty $facultyProfile = null): array
    {
        $departmentList = array_merge(
            Faculty::normalizeDepartmentList($user->department ?? ''),
            Faculty::normalizeDepartmentList($facultyProfile?->department ?? '')
        );

        $departmentList = array_values(array_unique($departmentList));
        $department = $departmentList === []
            ? null
            : Faculty::serializeDepartmentList($departmentList);

        return [
            'faculty_name_snapshot' => $user->name,
            'faculty_email_snapshot' => $user->email,
            'faculty_department_snapshot' => $department,
        ];
    }

    private function resolveDepartmentScope(?User $user): array
    {
        if (!$user) {
            return [];
        }

        return array_values(array_unique(array_merge(
            Faculty::normalizeDepartmentList($user->department ?? ''),
            Faculty::normalizeDepartmentList(optional($user->faculty)->department ?? '')
        )));
    }

    private function enforceDepartmentAccess(Faculty $facultyProfile): void
    {
        $user = auth()->user();
        if (!$user || $user->role === 'Admin') {
            return;
        }

        $department = trim($user->department ?? '');
        if ($department === '') {
            $department = trim(optional($user?->faculty)->department ?? '');
        }
        $departmentFilters = Faculty::normalizeDepartmentList($department);
        if (empty($departmentFilters)) {
            abort(404);
        }

        $facultyDepartments = Faculty::normalizeDepartmentList($facultyProfile->department ?? '');
        $allowed = collect($departmentFilters)->intersect($facultyDepartments);
        if ($allowed->isEmpty()) {
            abort(404);
        }
    }

    private function enforceEvaluationAccess(Evaluation $evaluation): void
    {
        $user = auth()->user();
        if (!$user || $user->role === 'Admin') {
            return;
        }
        $accessLevels = collect($user->access_level ?? []);
        if ($accessLevels->contains('View All Reports')) {
            return;
        }

        $department = trim($user->department ?? '');
        if ($department === '') {
            $department = trim(optional($user?->faculty)->department ?? '');
        }
        $departmentFilters = Faculty::normalizeDepartmentList($department);
        if (empty($departmentFilters)) {
            abort(404);
        }

        $evaluationDepartments = Faculty::normalizeDepartmentList(
            $evaluation->resolved_faculty_department ?? $evaluation->faculty?->department ?? ''
        );
        $allowed = collect($departmentFilters)->intersect($evaluationDepartments);
        if ($allowed->isEmpty()) {
            abort(404);
        }
    }

    private function applyDepartmentFilter($query, array $departments, string $column): void
    {
        if (empty($departments)) {
            return;
        }

        $query->where(function ($builder) use ($departments, $column) {
            $normalizedColumn = "REPLACE(REPLACE(REPLACE(COALESCE($column, ''), '  ', ' '), ', ', ','), ', ', ',')";

            foreach ($departments as $department) {
                $builder->orWhereRaw("FIND_IN_SET(?, $normalizedColumn)", [$department]);
            }
        });
    }
}
