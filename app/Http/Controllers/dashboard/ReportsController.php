<?php

namespace App\Http\Controllers\dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\{Evaluation, EvaluationResponse, Schedule, User, Faculty, Course, FacultyCourse};
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use App\Support\AuditLogger;
use Carbon\Carbon;
use ZipArchive;

class ReportsController extends Controller
{
    private const OFFICIAL_DEPARTMENTS = [
        'College of Nursing',
        'College of Dentistry',
        'College of Arts and Sciences',
        'College of Medical Technology',
        'College of Medicine',
        'College of Optometry',
        'College of Pharmacy',
        'College of Physical Therapy',
        'Basic Education',
        'School of Business and Management',
        'Institute of Education'
    ];

    private const EXCLUDED_DEPARTMENTS = [
        'Academic Department',
        'Research Ethics Office',
        'Senior High School',
    ];

    public function index(Request $request)
    {
        $excludedDepartments = self::EXCLUDED_DEPARTMENTS;
        $user = $request->user();
        $accessLevels = collect($user?->access_level ?? []);
        $isAdmin = $user && $user->role === 'Admin';
        $isDentistryProgramRestricted = $this->isDentistryProgramRestrictedUser($user);
        $hasReportAccess = $isAdmin
            || $accessLevels->contains('View All Reports')
            || $accessLevels->contains('View Department Reports');

        if (!$hasReportAccess) {
            return response()
                ->view('content.pages.pages-misc-error', [], 404);
        }

        $lockedDepartment = null;
        $lockedDepartments = collect();
        $isDepartmentScoped = false;
        if (!$isAdmin) {
            $lockedDepartment = trim($user->department ?? '');
            $lockedDepartment = $lockedDepartment !== '' ? $lockedDepartment : '__none__';
            $lockedDepartments = collect($this->resolveDepartmentScope($user))
                ->filter(function ($value) {
                    return $value !== '' && $value !== '__none__';
                })
                ->values();
            $isDepartmentScoped = true;
        }

        $selectedDepartment = $request->get('department', 'all');
        if ($selectedDepartment !== 'all') {
            $selectedDepartment = $this->normalizeDepartmentName($selectedDepartment) ?? $selectedDepartment;
        }
        $selectedAcademicYear = $request->get('academic_year', 'all');
        $selectedSemester = $request->get('semester', 'all');
        $selectedSubjectType = $request->get('subject_type', 'all');
        $selectedProgram = $this->normalizeDentistryProgram($request->get('program', 'all'));
        $perPageRaw = $request->get('per_page', 10);
        $perPage = $perPageRaw === 'all' ? 'all' : (int) $perPageRaw;

        // Validate subject_type value
        if (!in_array($selectedSubjectType, ['all', 'major', 'minor'], true)) {
            $selectedSubjectType = 'all';
        }

        if (in_array($selectedDepartment, $excludedDepartments, true)) {
            $selectedDepartment = 'all';
        }

        if ($isDepartmentScoped) {
            if ($lockedDepartments->isEmpty()) {
                $selectedDepartment = '__none__';
            } elseif ($selectedDepartment !== 'all' && !$lockedDepartments->contains($selectedDepartment)) {
                $selectedDepartment = 'all';
            }
        }

        if (!$this->shouldApplyDentistryProgram($selectedDepartment, $lockedDepartments->values()->all(), $user, $selectedProgram)) {
            $selectedProgram = 'all';
        }

        // Get all departments for filter dropdown
        $departments = Evaluation::where('is_active', true)
            ->whereNotNull('faculty_department_snapshot')
            ->pluck('faculty_department_snapshot')
            ->flatMap(function ($department) {
                return $this->normalizeDepartmentList($department);
            })
            ->reject(function ($department) use ($excludedDepartments) {
                return in_array($department, $excludedDepartments, true);
            })
            ->filter(fn ($department) => in_array($department, self::OFFICIAL_DEPARTMENTS, true))
            ->unique()
            ->sortBy(fn ($department) => array_search($department, self::OFFICIAL_DEPARTMENTS, true))
            ->values();
        if ($isDepartmentScoped) {
            $departments = $lockedDepartments
                ->reject(function ($department) use ($excludedDepartments) {
                    return in_array($department, $excludedDepartments, true);
                })
                ->filter(fn ($department) => in_array($department, self::OFFICIAL_DEPARTMENTS, true))
                ->unique()
                ->values();
        }

        // Get academic years and semesters for filters
        // Pull from FacultyCourse instead of Evaluation to ensure consistency with actual response data
        $academicYears = FacultyCourse::distinct()
            ->pluck('academic_year')
            ->sort()
            ->values();
        $semesters = FacultyCourse::distinct()
            ->pluck('semester')
            ->sort()
            ->values();

        // Base query for evaluations with department filtering.
        // Keep this lightweight; response-heavy aggregates are calculated in SQL below.
        $evaluationsQuery = Evaluation::where('is_active', true);

        foreach ($excludedDepartments as $excludedDepartment) {
            $evaluationsQuery->whereRaw(
                "NOT FIND_IN_SET(?, REPLACE(faculty_department_snapshot, ', ', ','))",
                [$excludedDepartment]
            );
        }

        if ($isDepartmentScoped && $lockedDepartments->isNotEmpty()) {
            $this->whereAnyDepartment($evaluationsQuery, $lockedDepartments->all(), 'faculty_department_snapshot');
        } elseif ($isDepartmentScoped && $lockedDepartments->isEmpty()) {
            $evaluationsQuery->whereRaw('0 = 1');
        }

        if ($selectedDepartment !== 'all') {
            $this->whereAnyDepartment($evaluationsQuery, [$selectedDepartment], 'faculty_department_snapshot');
        }

        // Apply academic year and semester filters
        if ($selectedAcademicYear !== 'all') {
            $evaluationsQuery->where('academic_year', $selectedAcademicYear);
        }
        if ($selectedSemester !== 'all') {
            $evaluationsQuery->where('semester', $selectedSemester);
        }

        $reportCacheKey = 'reports.index.v7.' . md5(json_encode([
            'department' => $selectedDepartment,
            'academic_year' => $selectedAcademicYear,
            'semester' => $selectedSemester,
            'subject_type' => $selectedSubjectType,
            'program' => $selectedProgram,
            'dentistry_program_restricted' => $isDentistryProgramRestricted,
            'per_page' => $perPage,
            'locked_departments' => $lockedDepartments->values()->all(),
        ]));

        $reportData = Cache::remember($reportCacheKey, now()->addMinutes(3), function () use (
            $evaluationsQuery,
            $selectedSubjectType,
            $selectedDepartment,
            $selectedAcademicYear,
            $selectedSemester,
            $selectedProgram,
            $perPage,
            $lockedDepartments,
            $excludedDepartments
        ) {
            return [
                'metrics' => $this->calculateMetrics(
                    $evaluationsQuery,
                    $selectedSubjectType,
                    $selectedDepartment,
                    $lockedDepartments->values()->all(),
                    $selectedProgram
                ),
            ];
        });

        $metrics = $reportData['metrics'];
        $departmentBreakdown = collect();
        $recentResponses = collect();
        $facultyRatings = [
            'top_rated' => collect(),
            'low_rated' => collect(),
            'most_evaluated' => collect(),
        ];

        return view('content.dashboard.dashboard-reports', compact(
            'metrics',
            'departments',
            'academicYears',
            'semesters',
            'selectedDepartment',
            'selectedAcademicYear',
            'selectedSemester',
            'selectedSubjectType',
            'selectedProgram',
            'isDentistryProgramRestricted',
            'departmentBreakdown',
            'recentResponses',
            'facultyRatings',
            'perPage',
            'isDepartmentScoped',
            'lockedDepartment'
        ));
    }

    public function getLazySections(Request $request): JsonResponse
    {
        $excludedDepartments = self::EXCLUDED_DEPARTMENTS;
        $user = $request->user();
        $accessLevels = collect($user?->access_level ?? []);
        $isAdmin = $user && $user->role === 'Admin';
        $isDentistryProgramRestricted = $this->isDentistryProgramRestrictedUser($user);
        $hasReportAccess = $isAdmin
            || $accessLevels->contains('View All Reports')
            || $accessLevels->contains('View Department Reports');

        if (!$hasReportAccess) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 404);
        }

        $requestSubjectType = $request->get('subject_type', 'all');
        $requestSubjectType = in_array($requestSubjectType, ['all', 'major', 'minor'], true)
            ? $requestSubjectType
            : 'all';
        $requestPerPageRaw = $request->get('per_page', 10);
        $requestPerPage = $requestPerPageRaw === 'all' ? 'all' : (int) $requestPerPageRaw;

        $allowedDepartments = [];
        if (!$isAdmin) {
            $allowedDepartments = collect($this->resolveDepartmentScope($user))
                ->filter(fn ($value) => $value !== '')
                ->values()
                ->all();

            if (empty($allowedDepartments)) {
                return response()->json([
                    'success' => true,
                    'department_html' => view('content.dashboard.partials.reports-department-breakdown', [
                        'departmentBreakdown' => collect(),
                        'selectedSubjectType' => $requestSubjectType,
                        'perPage' => $requestPerPage,
                    ])->render(),
                    'recent_html' => view('content.dashboard.partials.reports-recent-responses', [
                        'recentResponses' => collect(),
                    ])->render(),
                    'faculty_html' => view('content.dashboard.partials.reports-faculty-ratings', [
                        'facultyRatings' => [
                            'top_rated' => collect(),
                            'low_rated' => collect(),
                            'most_evaluated' => collect(),
                        ],
                    ])->render(),
                ]);
            }
        }

        $department = $request->get('department', 'all');
        if ($department !== 'all') {
            $department = $this->normalizeDepartmentName($department) ?? $department;
        }

        if (in_array($department, $excludedDepartments, true)) {
            $department = 'all';
        }

        if (!$isAdmin && $department !== 'all' && !in_array($department, $allowedDepartments, true)) {
            $department = 'all';
        }

        $academicYear = $request->get('academic_year', 'all');
        $semester = $request->get('semester', 'all');
        $subjectType = $requestSubjectType;
        $program = $this->normalizeDentistryProgram($request->get('program', 'all'));
        if (!$this->shouldApplyDentistryProgram($department, $allowedDepartments, $user, $program)) {
            $program = 'all';
        }
        $perPage = $requestPerPage;

        $cacheKey = 'reports.lazy.sections.v6.' . md5(json_encode([
            'department' => $department,
            'academic_year' => $academicYear,
            'semester' => $semester,
            'subject_type' => $subjectType,
            'program' => $program,
            'dentistry_program_restricted' => $isDentistryProgramRestricted,
            'per_page' => $perPage,
            'allowed_departments' => $allowedDepartments,
        ]));

        $data = Cache::remember($cacheKey, now()->addMinutes(3), function () use (
            $department,
            $academicYear,
            $semester,
            $subjectType,
            $program,
            $perPage,
            $allowedDepartments,
            $excludedDepartments
        ) {
            return [
                'departmentBreakdown' => $this->getDepartmentBreakdown(
                    $department,
                    $academicYear,
                    $semester,
                    $perPage,
                    $allowedDepartments,
                    $excludedDepartments,
                    $subjectType,
                    $program
                ),
                'recentResponses' => $this->getRecentResponses(
                    $department,
                    $academicYear,
                    $semester,
                    10,
                    $allowedDepartments,
                    $excludedDepartments,
                    $subjectType,
                    $program
                ),
                'facultyRatings' => $this->getFacultyRatings(
                    $department,
                    $academicYear,
                    $semester,
                    $allowedDepartments,
                    $excludedDepartments,
                    $subjectType,
                    $program
                ),
            ];
        });

        return response()->json([
            'success' => true,
            'department_html' => view('content.dashboard.partials.reports-department-breakdown', [
                'departmentBreakdown' => $data['departmentBreakdown'],
                'selectedSubjectType' => $subjectType,
                'perPage' => $perPage,
            ])->render(),
            'recent_html' => view('content.dashboard.partials.reports-recent-responses', [
                'recentResponses' => $data['recentResponses'],
            ])->render(),
            'faculty_html' => view('content.dashboard.partials.reports-faculty-ratings', [
                'facultyRatings' => $data['facultyRatings'],
            ])->render(),
        ]);
    }

    public function getMetricDetails(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessLevels = collect($user?->access_level ?? []);
        $isAdmin = $user && $user->role === 'Admin';
        $isDentistryProgramRestricted = $this->isDentistryProgramRestrictedUser($user);
        $hasReportAccess = $isAdmin
            || $accessLevels->contains('View All Reports')
            || $accessLevels->contains('View Department Reports');

        if (!$hasReportAccess) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 404);
        }

        $metric = $request->get('metric', 'total_faculties');
        if (!in_array($metric, ['total_faculties', 'total_responses', 'average_rating', 'courses_evaluated'], true)) {
            return response()->json(['success' => false, 'message' => 'Invalid metric.'], 422);
        }

        $excludedDepartments = self::EXCLUDED_DEPARTMENTS;
        $allowedDepartments = [];
        if (!$isAdmin) {
            $allowedDepartments = collect($this->resolveDepartmentScope($user))
                ->filter(fn($value) => $value !== '')
                ->values()
                ->all();
            if (empty($allowedDepartments)) {
                return response()->json([
                    'success' => true,
                    'title' => $this->metricTitle($metric),
                    'columns' => $this->metricColumns($metric),
                    'items' => [],
                    'meta' => ['total' => 0, 'page' => 1, 'per_page' => 10, 'last_page' => 1],
                ]);
            }
        }

        $department = $request->get('department', 'all');
        if (in_array($department, $excludedDepartments, true)) {
            $department = 'all';
        }
        if (!$isAdmin && $department !== 'all' && !in_array($department, $allowedDepartments, true)) {
            $department = 'all';
        }

        $academicYear = $request->get('academic_year', 'all');
        $semester = $request->get('semester', 'all');
        $subjectType = $request->get('subject_type', 'all');
        if (!in_array($subjectType, ['all', 'major', 'minor'], true)) {
            $subjectType = 'all';
        }
        $program = $this->normalizeDentistryProgram($request->get('program', 'all'));
        if (!$this->shouldApplyDentistryProgram($department, $allowedDepartments, $user, $program)) {
            $program = 'all';
        }

        $page = max(1, (int) $request->get('page', 1));
        $perPage = min(25, max(5, (int) $request->get('per_page', 10)));

        $cacheKey = 'reports.metric.details.v3.' . md5(json_encode([
            'metric' => $metric,
            'department' => $department,
            'academic_year' => $academicYear,
            'semester' => $semester,
            'subject_type' => $subjectType,
            'program' => $program,
            'dentistry_program_restricted' => $isDentistryProgramRestricted,
            'allowed_departments' => $allowedDepartments,
            'page' => $page,
            'per_page' => $perPage,
        ]));

        $result = Cache::remember($cacheKey, now()->addMinutes(3), function () use (
            $metric,
            $department,
            $academicYear,
            $semester,
            $subjectType,
            $program,
            $allowedDepartments,
            $excludedDepartments,
            $page,
            $perPage
        ) {
            return match ($metric) {
                'total_faculties' => $this->buildMetricFacultyRows($department, $academicYear, $semester, $subjectType, $program, $allowedDepartments, $excludedDepartments, $page, $perPage),
                'total_responses' => $this->buildMetricResponseRows($department, $academicYear, $semester, $subjectType, $program, $allowedDepartments, $excludedDepartments, $page, $perPage),
                'average_rating' => $this->buildMetricRatingRows($department, $academicYear, $semester, $subjectType, $program, $allowedDepartments, $excludedDepartments, $page, $perPage),
                'courses_evaluated' => $this->buildMetricCourseRows($department, $academicYear, $semester, $subjectType, $program, $allowedDepartments, $excludedDepartments, $page, $perPage),
            };
        });

        return response()->json([
            'success' => true,
            'title' => $this->metricTitle($metric),
            'columns' => $this->metricColumns($metric),
            'items' => $result['items'],
            'meta' => $result['meta'],
        ]);
    }

    //filter and paginate faculties for the department-faculties modal
    public function getDepartmentFaculties(Request $request)
    {
        // Get all request parameters for filtering and pagination data of every kinds of departments
        $department = $request->get('department'); // Get all request parameters for filtering and pagination data of every kinds of departments
        
        $excludedDepartments = self::EXCLUDED_DEPARTMENTS;
        if (in_array($department, $excludedDepartments, true)) {
            return response()
                ->json(['success' => false, 'message' => 'Unauthorized.'], 404);
        }

        // Get all request parameters for filtering and pagination data of every kinds of departments
        $search = $request->get('search', '');
        $perPage = $request->get('per_page', 10);
        $academicYear = $request->get('academic_year', 'all');
        $semester = $request->get('semester', 'all');
        $subjectType = $request->get('subject_type', 'all');
        if (!in_array($subjectType, ['all', 'major', 'minor'], true)) {
            $subjectType = 'all';
        }
        $program = $this->normalizeDentistryProgram($request->get('program', 'all'));
        if (!$this->shouldApplyDentistryProgram($department, [$department], $request->user(), $program)) {
            $program = 'all';
        }
        $ratingFilter = $request->get('rating_filter', 'all');
        $statusFilter = $request->get('status_filter', 'all');
        $sortKey = $request->get('sort_key', 'name');
        $sortDir = strtolower((string) $request->get('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        // Authorization check: only allow if user has access to reports and the department is within their scope (if not admin)
        $user = $request->user();
        $accessLevels = collect($user?->access_level ?? []);
        $isAdmin = $user && $user->role === 'Admin';
        $hasReportAccess = $isAdmin
            || $accessLevels->contains('View All Reports')
            || $accessLevels->contains('View Department Reports');

        if (!$hasReportAccess) {
            return response()
                ->json(['success' => false, 'message' => 'Unauthorized.'], 404);
        }

        // If not admin, check if the requested department is within the user's allowed departments
        if (!$isAdmin) {
            $allowedDepartments = collect($this->resolveDepartmentScope($user))
                ->filter(function ($value) {
                    return $value !== '';
                });
            if ($allowedDepartments->isEmpty() || !$allowedDepartments->contains($department)) {
                return response()
                    ->json(['success' => false, 'message' => 'Unauthorized.'], 404);
            }
        }

        // Fetch faculties and their evaluations for the requested department
        try {
            $pageSize = $perPage === 'all' ? 1000 : max((int) $perPage, 1);
            $facultyRowsQuery = $this->reportResponseBaseQuery(
                $department,
                $academicYear,
                $semester,
                $subjectType,
                [$department],
                $excludedDepartments,
                $program
            )
                ->selectRaw("
                    MAX(evaluation_responses.evaluation_id) as evaluation_id,
                    COALESCE(faculty_courses.faculty_id, evaluations.faculty_id, evaluations.faculty_name_snapshot) as faculty_group_key,
                    COALESCE(NULLIF(faculty_users.name, ''), NULLIF(users.name, ''), NULLIF(evaluations.faculty_name_snapshot, ''), 'Unknown') as name,
                    ? as department,
                    LOWER(COALESCE(NULLIF(faculty_users.status, ''), NULLIF(users.status, ''), 'active')) as status,
                    COUNT(DISTINCT evaluation_responses.evaluation_id) as evaluation_count,
                    COUNT(DISTINCT evaluation_responses.evaluation_id) as active_evaluations,
                    COUNT(evaluation_responses.id) as response_count,
                    ROUND(AVG(evaluation_responses.effectiveness_rating), 2) as average_rating
                ", [$department])
                ->groupBy('faculty_group_key', 'name', 'department', 'status')
                ->having('response_count', '>', 0);

            if ($search) {
                $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($search)) . '%';
                $facultyRowsQuery->where(function ($query) use ($needle, $department) {
                    $query->whereRaw("COALESCE(NULLIF(faculty_users.name, ''), NULLIF(users.name, ''), NULLIF(evaluations.faculty_name_snapshot, ''), 'Unknown') LIKE ?", [$needle])
                        ->orWhereRaw('? LIKE ?', [$department, $needle]);
                });
            }

            if ($statusFilter !== 'all') {
                $facultyRowsQuery->whereRaw(
                    "LOWER(COALESCE(NULLIF(faculty_users.status, ''), NULLIF(users.status, ''), 'active')) = ?",
                    [strtolower(trim((string) $statusFilter))]
                );
            }

            if ($ratingFilter !== 'all') {
                match ($ratingFilter) {
                    'very_effective' => $facultyRowsQuery->havingRaw('AVG(evaluation_responses.effectiveness_rating) >= 3.5'),
                    'effective' => $facultyRowsQuery->havingRaw('AVG(evaluation_responses.effectiveness_rating) >= 2.5 AND AVG(evaluation_responses.effectiveness_rating) < 3.5'),
                    'somewhat_effective' => $facultyRowsQuery->havingRaw('AVG(evaluation_responses.effectiveness_rating) >= 1.5 AND AVG(evaluation_responses.effectiveness_rating) < 2.5'),
                    'not_effective' => $facultyRowsQuery->havingRaw('AVG(evaluation_responses.effectiveness_rating) > 0 AND AVG(evaluation_responses.effectiveness_rating) < 1.5'),
                    'no_ratings' => $facultyRowsQuery->havingRaw('COUNT(evaluation_responses.id) = 0'),
                    default => null,
                };
            }

            $sortMap = [
                'name' => 'name',
                'evaluations' => 'evaluation_count',
                'responses' => 'response_count',
                'avg_rating' => 'average_rating',
                'status' => 'status',
            ];
            $facultyRowsQuery->orderBy($sortMap[$sortKey] ?? 'name', $sortDir);

            $faculties = $facultyRowsQuery->paginate($pageSize);

            $selectedAcademicYear = $academicYear;
            $selectedSemester = $semester;
            $selectedSubjectType = $subjectType;
            $selectedDepartment = $department;
            $selectedProgram = $program;
            $html = view('content.dashboard.partials.faculty-modal-table', compact('faculties', 'selectedDepartment', 'selectedAcademicYear', 'selectedSemester', 'selectedSubjectType', 'selectedProgram'))->render();
            $pagination = $perPage !== 'all'
                ? $faculties->appends($request->all())->links('pagination::bootstrap-4')->render()
                : '';

            return response()->json([
                'success' => true,
                'html' => $html,
                'pagination' => $pagination,
                'meta' => [
                    'current_page' => $faculties->currentPage(),
                    'last_page' => $faculties->lastPage(),
                    'per_page' => $faculties->perPage(),
                    'total' => $faculties->total(),
                    'from' => $faculties->firstItem(),
                    'to' => $faculties->lastItem(),
                ],
            ]);

            $assignmentFacultyIdsQuery = EvaluationResponse::join('evaluations', 'evaluation_responses.evaluation_id', '=', 'evaluations.id')
                ->join('schedules', 'evaluation_responses.schedule_id', '=', 'schedules.id')
                ->join('faculty_courses', 'schedules.faculty_course_id', '=', 'faculty_courses.id')
                ->join('courses', 'faculty_courses.course_id', '=', 'courses.id')
                ->where('evaluations.is_active', true)
                ->where(function ($query) use ($department) {
                    $query->whereRaw(
                        "FIND_IN_SET(?, REPLACE(COALESCE(faculty_courses.department, ''), ', ', ','))",
                        [$department]
                    )->orWhereRaw(
                        "FIND_IN_SET(?, REPLACE(COALESCE(evaluations.faculty_department_snapshot, ''), ', ', ','))",
                        [$department]
                    );
                });

            if ($academicYear !== 'all') {
                $assignmentFacultyIdsQuery->where('evaluations.academic_year', $academicYear);
            }
            if ($semester !== 'all') {
                $assignmentFacultyIdsQuery->where('evaluations.semester', $semester);
            }
            if ($subjectType !== 'all') {
                $assignmentFacultyIdsQuery->where('courses.subject_type', $subjectType);
            }

            $assignmentFacultyIds = $assignmentFacultyIdsQuery
                ->pluck('faculty_courses.faculty_id')
                ->filter()
                ->unique()
                ->values();

            $faculties = Faculty::with('user')
                ->where(function ($query) use ($department, $assignmentFacultyIds) {
                    $query->forDepartments([$department]);

                    if ($assignmentFacultyIds->isNotEmpty()) {
                        $query->orWhereIn('id', $assignmentFacultyIds);
                    }
                })
                ->get();
            $facultyIds = $faculties->pluck('id')->filter()->values();
            $facultyUserIds = $faculties->pluck('user_id')->filter()->values();

            $baseEvaluationsQuery = Evaluation::where('is_active', true);
            if ($facultyUserIds->isNotEmpty()) {
                $baseEvaluationsQuery->where(function ($query) use ($facultyUserIds, $department) {
                    $query->whereIn('faculty_id', $facultyUserIds)
                        ->orWhere(function ($query) use ($department) {
                            $query->whereNull('faculty_id')
                                ->whereRaw(
                                    "FIND_IN_SET(?, REPLACE(faculty_department_snapshot, ', ', ','))",
                                    [$department]
                                );
                        });
                });
            } else {
                $baseEvaluationsQuery->whereRaw('0 = 1');
            }

            if ($academicYear !== 'all') {
                $baseEvaluationsQuery->where('academic_year', $academicYear);
            }
            if ($semester !== 'all') {
                $baseEvaluationsQuery->where('semester', $semester);
            }

            $evaluationStats = (clone $baseEvaluationsQuery)
                ->select('faculty_id')
                ->selectRaw('COUNT(*) as evaluation_count')
                ->groupBy('faculty_id')
                ->get()
                ->keyBy('faculty_id');

            $latestEvaluationIds = (clone $baseEvaluationsQuery)
                ->select('faculty_id')
                ->selectRaw('MAX(id) as latest_id')
                ->groupBy('faculty_id')
                ->get()
                ->keyBy('faculty_id');

            $ratingStatsQuery = EvaluationResponse::join('evaluations', 'evaluation_responses.evaluation_id', '=', 'evaluations.id')
                ->join('schedules', 'evaluation_responses.schedule_id', '=', 'schedules.id')
                ->join('faculty_courses', 'schedules.faculty_course_id', '=', 'faculty_courses.id')
                ->join('courses', 'faculty_courses.course_id', '=', 'courses.id')
                ->where(function ($query) use ($department) {
                    $query->whereRaw(
                        "FIND_IN_SET(?, REPLACE(COALESCE(faculty_courses.department, ''), ', ', ','))",
                        [$department]
                    )->orWhereRaw(
                        "FIND_IN_SET(?, REPLACE(COALESCE(evaluations.faculty_department_snapshot, ''), ', ', ','))",
                        [$department]
                    );
                })
                ->where('evaluations.is_active', true);
            if ($facultyIds->isNotEmpty()) {
                $ratingStatsQuery->whereIn('faculty_courses.faculty_id', $facultyIds);
            } else {
                $ratingStatsQuery->whereRaw('0 = 1');
            }

            if ($academicYear !== 'all') {
                $ratingStatsQuery->where('evaluations.academic_year', $academicYear);
            }
            if ($semester !== 'all') {
                $ratingStatsQuery->where('evaluations.semester', $semester);
            }

            // Apply subject_type filter via schedule → faculty_course → course
            if ($subjectType !== 'all') {
                $ratingStatsQuery->where('courses.subject_type', $subjectType);
            }

            $ratingStats = $ratingStatsQuery
                ->select('faculty_courses.faculty_id as faculty_id')
                ->selectRaw('COUNT(DISTINCT evaluation_responses.evaluation_id) as matching_evaluation_count')
                ->selectRaw('COUNT(evaluation_responses.id) as response_count')
                ->selectRaw('AVG(evaluation_responses.effectiveness_rating) as average_rating')
                ->selectRaw('MAX(evaluation_responses.evaluation_id) as latest_response_evaluation_id')
                ->groupBy('faculty_courses.faculty_id')
                ->get()
                ->keyBy('faculty_id');

            // --- Resolve NULL faculty_id responses by matching faculty_name_snapshot to faculty user names ---
            // Responses from evaluations where faculty_id IS NULL are grouped under key "" above.
            // We re-attribute them to the correct faculty by matching faculty_name_snapshot → user name.
            $nullKeyStats = $ratingStats->get('') ?? $ratingStats->get(null);
            if ($nullKeyStats !== null) {
                // Fetch all NULL-faculty_id evaluations for this department/year/semester
                $nullEvalQuery = Evaluation::where('is_active', true)
                    ->whereNull('faculty_id');
                if ($academicYear !== 'all') {
                    $nullEvalQuery->where('academic_year', $academicYear);
                }
                if ($semester !== 'all') {
                    $nullEvalQuery->where('semester', $semester);
                }
                $nullEvals = $nullEvalQuery->get();

                // Build a name → faculty_user_id map from the faculties we already loaded
                $nameToFacultyId = $faculties->mapWithKeys(function ($faculty) {
                    $name = strtolower(trim($faculty->user?->name ?? ''));
                    return $name !== '' ? [$name => $faculty->user_id] : [];
                });

                // For each NULL eval, find the matching faculty and accumulate stats
                $nullAccumulator = []; // faculty_user_id => [total_rating, count, latest_evaluation_id]
                foreach ($nullEvals as $nullEval) {
                    $snapshotName = strtolower(trim($nullEval->faculty_name_snapshot ?? ''));
                    $matchedFacultyId = $nameToFacultyId->get($snapshotName);
                    if (!$matchedFacultyId) {
                        continue;
                    }

                    $respQuery = EvaluationResponse::with(['evaluation', 'schedule.facultyCourse.course'])
                        ->where('evaluation_id', $nullEval->id);
                    if ($subjectType !== 'all') {
                        $respQuery->whereHas('schedule.facultyCourse.course', function ($q) use ($subjectType) {
                            $q->where('subject_type', $subjectType);
                        });
                    }
                    $resps = $respQuery->get()
                        ->filter(fn ($response) => $this->responseHandledByDepartment($response, $department))
                        ->values();

                    if ($resps->isEmpty()) {
                        continue;
                    }

                    if (!isset($nullAccumulator[$matchedFacultyId])) {
                        $nullAccumulator[$matchedFacultyId] = ['sum' => 0, 'count' => 0, 'latest_evaluation_id' => null];
                    }
                    $nullAccumulator[$matchedFacultyId]['sum'] += $resps->sum('effectiveness_rating');
                    $nullAccumulator[$matchedFacultyId]['count'] += $resps->count();
                    $latestResponse = $resps->sortByDesc('created_at')->first();
                    if ($latestResponse && (!$nullAccumulator[$matchedFacultyId]['latest_evaluation_id']
                        || $latestResponse->evaluation_id > $nullAccumulator[$matchedFacultyId]['latest_evaluation_id'])) {
                        $nullAccumulator[$matchedFacultyId]['latest_evaluation_id'] = $latestResponse->evaluation_id;
                    }
                }

                // Merge accumulated NULL-eval stats into $ratingStats
                foreach ($nullAccumulator as $fId => $acc) {
                    if ($acc['count'] === 0) {
                        continue;
                    }
                    $existing = $ratingStats->get($fId);
                    if ($existing) {
                        $totalCount = $existing->response_count + $acc['count'];
                        $totalSum = ($existing->average_rating * $existing->response_count) + $acc['sum'];
                        $existing->response_count = $totalCount;
                        $existing->average_rating = $totalCount > 0 ? $totalSum / $totalCount : 0;
                        $existing->latest_response_evaluation_id = $acc['latest_evaluation_id']
                            ?? $existing->latest_response_evaluation_id
                            ?? null;
                    } else {
                        $ratingStats->put($fId, (object) [
                            'faculty_id' => $fId,
                            'response_count' => $acc['count'],
                            'average_rating' => $acc['sum'] / $acc['count'],
                            'latest_response_evaluation_id' => $acc['latest_evaluation_id'],
                        ]);
                    }
                }

                // Remove the NULL key entry — it's now been redistributed
                $ratingStats->forget('');
                $ratingStats->forget(null);
            }

            $facultyRows = $faculties->map(function ($faculty) use ($evaluationStats, $ratingStats, $latestEvaluationIds) {
                $facultyId = $faculty->user_id;
                $evalStat = $facultyId ? $evaluationStats->get($facultyId) : null;
                $ratingStat = $ratingStats->get($faculty->id);
                $latestEvaluation = $facultyId ? $latestEvaluationIds->get($facultyId) : null;

                $average = $ratingStat ? (float) $ratingStat->average_rating : 0;
                return (object) [
                    'evaluation_id' => $ratingStat?->latest_response_evaluation_id ?? $latestEvaluation?->latest_id,
                    'name' => $faculty->user?->name ?? 'Unknown',
                    'email' => $faculty->user?->email,
                    'job_title' => $faculty->job_title ?? $faculty->user?->job_title,
                    'department' => $faculty->department ?? $faculty->user?->department,
                    'status' => strtolower((string) ($faculty->user?->status ?? 'active')),
                    'evaluation_count' => (int) ($ratingStat?->matching_evaluation_count ?? $evalStat?->evaluation_count ?? 0),
                    'active_evaluations' => (int) ($ratingStat?->matching_evaluation_count ?? $evalStat?->evaluation_count ?? 0),
                    'response_count' => (int) ($ratingStat?->response_count ?? 0),
                    'average_rating' => $average > 0 ? round($average, 2) : 0,
                ];
            })->values();

            // Only show faculty who have at least one response under the current filters
            $facultyRows = $facultyRows->filter(function ($faculty) {
                return (int) ($faculty->response_count ?? 0) > 0;
            })->values();

            $matchedResponses = $this->getFilteredReportResponses(
                $department,
                $academicYear,
                $semester,
                $subjectType,
                [$department],
                $excludedDepartments
            );

            if ($matchedResponses->isNotEmpty()) {
                $facultyRows = $this->buildFacultyRowsFromResponses($matchedResponses, $department);
            }

            if ($search) {
                $needle = strtolower(trim($search));
                $facultyRows = $facultyRows->filter(function ($faculty) use ($needle) {
                    return str_contains(strtolower($faculty->name ?? ''), $needle)
                        || str_contains(strtolower($faculty->department ?? ''), $needle);
                })->values();
            }

            if ($ratingFilter !== 'all') {
                $facultyRows = $facultyRows->filter(function ($faculty) use ($ratingFilter) {
                    $average = (float) ($faculty->average_rating ?? 0);
                    $bucket = $this->getAverageRatingBucket($average);
                    return $bucket === $ratingFilter;
                })->values();
            }

            if ($statusFilter !== 'all') {
                $statusNeedle = strtolower(trim((string) $statusFilter));
                $facultyRows = $facultyRows->filter(function ($faculty) use ($statusNeedle) {
                    return strtolower((string) ($faculty->status ?? '')) === $statusNeedle;
                })->values();
            }

            $allowedSorts = ['name', 'evaluations', 'responses', 'avg_rating', 'status'];
            if (!in_array($sortKey, $allowedSorts, true)) {
                $sortKey = 'name';
            }

            $sorter = function ($faculty) use ($sortKey) {
                switch ($sortKey) {
                    case 'evaluations':
                        return (int) ($faculty->evaluation_count ?? 0);
                    case 'responses':
                        return (int) ($faculty->response_count ?? 0);
                    case 'avg_rating':
                        return (float) ($faculty->average_rating ?? 0);
                    case 'status':
                        return strtolower((string) ($faculty->status ?? ''));
                    case 'name':
                    default:
                        return strtolower((string) ($faculty->name ?? ''));
                }
            };

            $facultyRows = $sortDir === 'desc'
                ? $facultyRows->sortByDesc($sorter)->values()
                : $facultyRows->sortBy($sorter)->values();

            $totalRows = $facultyRows->count();
            $usePagination = $perPage !== 'all';
            $pageSize = $usePagination ? max((int) $perPage, 1) : max($totalRows, 1);
            $faculties = $this->paginateCollection($facultyRows, $pageSize);

            // Generate the HTML and pagination
            $selectedAcademicYear = $academicYear;
            $selectedSemester = $semester;
            $selectedSubjectType = $subjectType;
            $selectedDepartment = $department;
            $html = view('content.dashboard.partials.faculty-modal-table', compact('faculties', 'selectedDepartment', 'selectedAcademicYear', 'selectedSemester', 'selectedSubjectType'))->render();
            $pagination = $usePagination
                ? $faculties->appends($request->all())->links('pagination::bootstrap-4')->render()
                : '';

            // Add debugging information
            \Log::info('Faculty Modal Response', [
                'department' => $department,
                'faculty_count' => $faculties->total(),
                'total_pages' => $faculties->lastPage(),
                'current_page' => $faculties->currentPage(),
                'has_html' => !empty($html),
                'has_pagination' => !empty($pagination)
            ]);

            return response()->json([
                'success' => true,
                'html' => $html,
                'pagination' => $pagination,
                'meta' => [
                    'current_page' => $faculties->currentPage(),
                    'last_page' => $faculties->lastPage(),
                    'per_page' => $faculties->perPage(),
                    'total' => $faculties->total(),
                    'from' => $faculties->firstItem(),
                    'to' => $faculties->lastItem()
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Faculty Modal Error', [
                'error' => $e->getMessage(),
                'department' => $department,
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to load faculty data',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function exportDepartmentFaculties(Request $request)
    {
        $department = $this->normalizeDepartmentName($request->get('department')) ?? trim((string) $request->get('department'));
        $excludedDepartments = self::EXCLUDED_DEPARTMENTS;

        if (!$department || in_array($department, $excludedDepartments, true)) {
            return redirect()->back()->with('error', 'Invalid department selection.');
        }

        $user = $request->user();
        $accessLevels = collect($user?->access_level ?? []);
        $isAdmin = $user && $user->role === 'Admin';
        $hasReportAccess = $isAdmin
            || $accessLevels->contains('View All Reports')
            || $accessLevels->contains('View Department Reports');

        if (!$hasReportAccess) {
            return response()->view('content.pages.pages-misc-error', [], 404);
        }

        if (!$isAdmin) {
            $allowedDepartments = collect($this->resolveDepartmentScope($user))
                ->filter(function ($value) {
                    return $value !== '';
                });
            if ($allowedDepartments->isEmpty() || !$allowedDepartments->contains($department)) {
                return response()->view('content.pages.pages-misc-error', [], 404);
            }
        }

        $academicYear = $request->get('academic_year', 'all');
        $semester = $request->get('semester', 'all');
        $subjectType = $request->get('subject_type', 'all');
        if (!in_array($subjectType, ['all', 'major', 'minor'], true)) {
            $subjectType = 'all';
        }
        $program = $this->normalizeDentistryProgram($request->get('program', 'all'));
        if (!$this->shouldApplyDentistryProgram($department, [$department], $user, $program)) {
            $program = 'all';
        }
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        $start = null;
        $end = null;

        if ($startDate) {
            try {
                $start = Carbon::parse($startDate)->startOfDay();
            } catch (\Exception $e) {
                $start = null;
            }
        }

        if ($endDate) {
            try {
                $end = Carbon::parse($endDate)->endOfDay();
            } catch (\Exception $e) {
                $end = null;
            }
        }

        if ($start && $end && $end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        $evaluationsQuery = Evaluation::where('is_active', true);
        $this->whereAnyDepartment($evaluationsQuery, [$department], 'faculty_department_snapshot');

        if ($academicYear !== 'all') {
            $evaluationsQuery->where('academic_year', $academicYear);
        }

        if ($semester !== 'all') {
            $evaluationsQuery->where('semester', $semester);
        }

        $evaluationCount = (clone $evaluationsQuery)->count();
        if ($evaluationCount === 0) {
            return redirect()->back()->with('error', 'No evaluations found for the selected filters.');
        }

        $responsesQuery = $this->reportResponseBaseQuery(
            $department,
            $academicYear,
            $semester,
            $subjectType,
            [$department],
            $excludedDepartments,
            $program
        )->selectRaw("
            evaluation_responses.id,
            evaluation_responses.effectiveness_rating,
            evaluation_responses.feedback_comments,
            evaluation_responses.created_at,
            COALESCE(NULLIF(faculty_users.name, ''), NULLIF(users.name, ''), NULLIF(evaluations.faculty_name_snapshot, ''), 'Unknown') as faculty_name,
            evaluations.academic_year,
            evaluations.semester,
            COALESCE(courses.subject_type, 'N/A') as subject_type,
            COALESCE(NULLIF(courses.class_code, ''), NULLIF(evaluation_responses.course_code_snapshot, ''), 'N/A') as course_code,
            COALESCE(faculty_courses.section, '') as section,
            COALESCE(NULLIF(courses.subject_code, ''), NULLIF(evaluation_responses.course_name_snapshot, ''), 'N/A') as course_name
        ");

        if ($start) {
            $responsesQuery->where('evaluation_responses.created_at', '>=', $start);
        }
        if ($end) {
            $responsesQuery->where('evaluation_responses.created_at', '<=', $end);
        }

        $responses = $responsesQuery
            ->orderBy('faculty_name')
            ->orderBy('evaluation_responses.created_at')
            ->get();

        // Generate filename
        $departmentSlug = preg_replace('/[^A-Za-z0-9]+/', '_', ucwords(strtolower($department)));
        $departmentSlug = trim($departmentSlug, '_');
        $dateTag = now()->format('Y-m-d_H-i-s');
        $subjectTag = $subjectType !== 'all' ? '_' . ucfirst($subjectType) : '';
        $programTag = $program !== 'all' ? '_' . strtoupper($program) : '';
        $csvFilename = "Evaluation_Responses_{$departmentSlug}{$programTag}{$subjectTag}_{$dateTag}.csv";

        // Prepare data for export
        $headers = [
            'DATE',
            'FACULTY NAME',
            'FACULTY DEPARTMENT',
            'ACADEMIC YEAR',
            'SEMESTER',
            'SUBJECT TYPE',
            'COURSE CODE',
            'SECTION',
            'COURSE NAME',
            'EFFECTIVENESS',
            'EFFECTIVENESS RATING',
            'FEEDBACK COMMENTS',
        ];

        $data = [];
        if ($responses->isEmpty()) {
            $data[] = [
                '',
                'No matching responses found',
                $department,
                $academicYear === 'all' ? 'All Academic Years' : $academicYear,
                $semester === 'all' ? 'All Semesters' : $this->formatSemesterLabel($semester),
                $subjectType === 'major' ? 'Professional Course' : ($subjectType === 'minor' ? 'Minor Course' : 'All Subject Types'),
                '',
                '',
                'No responses matched the selected department, academic year, semester, and subject type.',
                '',
                '',
                '',
            ];
        }

        foreach ($responses as $response) {
            $subjectTypeValue = match (strtolower((string) $response->subject_type)) {
                'major' => 'Professional Course',
                'minor' => 'Minor Course',
                default => 'N/A',
            };

            $facultyName = $response->faculty_name ?? 'Unknown';
            
            // Fix encoding issues - convert from ISO-8859-1 to UTF-8 if needed
            $facultyName = iconv('UTF-8', 'UTF-8//IGNORE', $facultyName);
            
            // Remove extra spaces
            $facultyName = preg_replace('/\s+/', ' ', trim($facultyName));
            
            $data[] = [
                $response->created_at ? Carbon::parse($response->created_at)->format('M d, Y') : '',
                $facultyName,
                $department,
                $response->academic_year ?? '',
                $this->formatSemesterLabel($response->semester ?? ''),
                $subjectTypeValue,
                $response->course_code,
                $response->section,
                $response->course_name,
                $this->formatEffectivenessText((int) $response->effectiveness_rating),
                $response->effectiveness_rating,
                $response->feedback_comments,
            ];
        }

        AuditLogger::log('report_excel_exported', [
            'module' => 'Reports',
            'description' => "Exported report responses for {$department}.",
            'after_values' => [
                'department' => $department,
                'academic_year' => $academicYear,
                'semester' => $semester,
                'subject_type' => $subjectType,
                'program' => $this->formatDentistryProgramLabel($program),
                'start_date' => $start?->toDateString(),
                'end_date' => $end?->toDateString(),
                'evaluation_count' => $evaluationCount,
                'response_count' => $responses->count(),
                'file_name' => $csvFilename,
            ],
            'severity' => 'info',
        ]);

        return response()->streamDownload(function () use ($headers, $data) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers);

            foreach ($data as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $csvFilename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function calculateMetrics($evaluationsQuery, string $subjectType = 'all', string $department = 'all', array $allowedDepartments = [], string $program = 'all')
    {
        $evaluationIdsQuery = (clone $evaluationsQuery)->select('evaluations.id');

        $totalEvaluations = (clone $evaluationsQuery)->count();
        $totalFaculties = DB::query()
            ->fromSub(
                (clone $evaluationsQuery)
                    ->leftJoin('users', 'users.id', '=', 'evaluations.faculty_id')
                    ->selectRaw("COALESCE(NULLIF(users.name, ''), NULLIF(evaluations.faculty_name_snapshot, ''), 'Unknown') as faculty_name")
                    ->distinct(),
                'report_faculties'
            )
            ->count('faculty_name');

        $responsesQuery = $this->reportResponseBaseQuery(
            $department,
            'all',
            'all',
            $subjectType,
            $allowedDepartments,
            [],
            $program
        )->whereIn('evaluation_responses.evaluation_id', $evaluationIdsQuery);

        $responseStats = (clone $responsesQuery)
            ->selectRaw('
                COUNT(evaluation_responses.id) as total_responses,
                AVG(evaluation_responses.effectiveness_rating) as average_rating,
                COUNT(DISTINCT COALESCE(schedules.id, evaluation_responses.course_code_snapshot)) as courses_evaluated,
                SUM(CASE WHEN TRIM(COALESCE(evaluation_responses.feedback_comments, "")) <> "" THEN 1 ELSE 0 END) as responses_with_feedback
            ')
            ->first();

        $totalResponses = (int) ($responseStats->total_responses ?? 0);
        $ratingDistribution = (clone $responsesQuery)
            ->select('evaluation_responses.effectiveness_rating')
            ->selectRaw('COUNT(*) as rating_count')
            ->groupBy('evaluation_responses.effectiveness_rating')
            ->pluck('rating_count', 'evaluation_responses.effectiveness_rating');

        return [
            'total_evaluations' => $totalEvaluations,
            'active_evaluations' => $totalEvaluations,
            'total_responses' => $totalResponses,
            'total_faculties' => $totalFaculties,
            'average_rating' => $totalResponses > 0 ? round((float) ($responseStats->average_rating ?? 0), 2) : 0,
            'courses_evaluated' => (int) ($responseStats->courses_evaluated ?? 0),
            'responses_with_feedback' => (int) ($responseStats->responses_with_feedback ?? 0),
            'rating_distribution' => $ratingDistribution,
        ];
    }

    private function getAverageRatingBucket(float $average): string
    {
        if ($average >= 3.5) {
            return 'very_effective';
        }
        if ($average >= 2.5) {
            return 'effective';
        }
        if ($average >= 1.5) {
            return 'somewhat_effective';
        }
        if ($average > 0) {
            return 'not_effective';
        }
        return 'no_ratings';
    }

    private function getDepartmentBreakdown($selectedDepartment, $academicYear, $semester, $perPage = null, array $allowedDepartments = [], array $excludedDepartments = [], string $subjectType = 'all', string $program = 'all')
    {
        if (in_array($selectedDepartment, $excludedDepartments, true)) {
            return collect();
        }
        $facultyDepartmentCounts = $this->buildFacultyDepartmentCounts($allowedDepartments, $excludedDepartments);
        $departments = $selectedDepartment !== 'all'
            ? [$selectedDepartment]
            : self::OFFICIAL_DEPARTMENTS;

        if (!empty($allowedDepartments)) {
            $departments = array_values(array_intersect($departments, $allowedDepartments));
        }
        if (!empty($excludedDepartments)) {
            $departments = array_values(array_diff($departments, $excludedDepartments));
        }

        $breakdown = collect($departments)->map(function ($department) use ($academicYear, $semester, $subjectType, $program, $allowedDepartments, $excludedDepartments, $facultyDepartmentCounts) {
            $evaluationQuery = Evaluation::where('is_active', true);
            $this->applyReportEvaluationFilters($evaluationQuery, $department, $academicYear, $semester, [], $excludedDepartments);

            $responseQuery = $this->reportResponseBaseQuery(
                $department,
                $academicYear,
                $semester,
                $subjectType,
                $allowedDepartments,
                $excludedDepartments,
                $department === 'College of Dentistry' ? $program : 'all'
            );

            $stats = (clone $responseQuery)
                ->selectRaw('COUNT(evaluation_responses.id) as response_count, AVG(evaluation_responses.effectiveness_rating) as average_rating')
                ->first();

            $splitRows = (clone $responseQuery)
                ->select('courses.subject_type')
                ->selectRaw('COUNT(evaluation_responses.id) as response_count, AVG(evaluation_responses.effectiveness_rating) as average_rating')
                ->groupBy('courses.subject_type')
                ->get()
                ->keyBy('subject_type');

            return [
                'department' => $department,
                'faculty_count' => $facultyDepartmentCounts[$department] ?? 0,
                'total_evaluations' => (clone $evaluationQuery)->count(),
                'active_evaluations' => (clone $evaluationQuery)->count(),
                'total_responses' => (int) ($stats->response_count ?? 0),
                'average_rating' => (int) ($stats->response_count ?? 0) > 0 ? round((float) ($stats->average_rating ?? 0), 2) : 0,
                'major_responses' => (int) ($splitRows->get('major')?->response_count ?? 0),
                'minor_responses' => (int) ($splitRows->get('minor')?->response_count ?? 0),
                'major_avg_rating' => $splitRows->get('major') ? round((float) $splitRows->get('major')->average_rating, 2) : 0,
                'minor_avg_rating' => $splitRows->get('minor') ? round((float) $splitRows->get('minor')->average_rating, 2) : 0,
            ];
        })->filter(function ($department) {
            return ($department['active_evaluations'] ?? 0) > 0 || ($department['total_responses'] ?? 0) > 0;
        });

        return $breakdown->sortByDesc('total_responses')->values();
    }

    private function getRecentResponses($department, $academicYear, $semester, $limit = 10, array $allowedDepartments = [], array $excludedDepartments = [], string $subjectType = 'all', string $program = 'all')
    {
        return $this->reportResponseBaseQuery($department, $academicYear, $semester, $subjectType, $allowedDepartments, $excludedDepartments, $program)
            ->selectRaw("
                evaluation_responses.id,
                evaluation_responses.effectiveness_rating,
                evaluation_responses.created_at,
                COALESCE(NULLIF(courses.class_code, ''), NULLIF(evaluation_responses.course_code_snapshot, ''), 'N/A') as resolved_course_code
            ")
            ->orderByDesc('evaluation_responses.created_at')
            ->limit($limit)
            ->get()
            ->map(function ($response) {
                $response->created_at = Carbon::parse($response->created_at);
                return $response;
            });
    }
    private function getFacultyRatings($department, $academicYear, $semester, array $allowedDepartments = [], array $excludedDepartments = [], string $subjectType = 'all', string $program = 'all')
    {
        $ratingsCollection = $this->reportResponseBaseQuery($department, $academicYear, $semester, $subjectType, $allowedDepartments, $excludedDepartments, $program)
            ->selectRaw("
                COALESCE(faculty_courses.faculty_id, evaluations.faculty_id, evaluations.faculty_name_snapshot) as faculty_group_key,
                COALESCE(NULLIF(faculty_users.name, ''), NULLIF(users.name, ''), NULLIF(evaluations.faculty_name_snapshot, ''), 'Unknown') as faculty_name,
                COALESCE(NULLIF(faculty_courses.department, ''), NULLIF(faculties.department, ''), NULLIF(evaluations.faculty_department_snapshot, ''), 'No department') as department,
                AVG(evaluation_responses.effectiveness_rating) as average_rating,
                COUNT(evaluation_responses.id) as total_responses,
                COUNT(DISTINCT COALESCE(schedules.id, evaluation_responses.course_code_snapshot)) as courses_count
            ")
            ->groupBy('faculty_group_key', 'faculty_name', 'department')
            ->having('total_responses', '>', 0)
            ->get()
            ->map(function ($row) use ($department) {
                return [
                    'faculty_name' => $row->faculty_name ?: 'Unknown',
                    'department' => $department !== 'all' ? $department : ($row->department ?: 'No department'),
                    'average_rating' => round((float) $row->average_rating, 2),
                    'total_responses' => (int) $row->total_responses,
                    'courses_count' => (int) $row->courses_count,
                ];
            });

        return [
            'top_rated' => $ratingsCollection->sortByDesc('average_rating')->values()->take(5),
            'low_rated' => $ratingsCollection->sortBy('average_rating')->values()->take(5),
            'most_evaluated' => $ratingsCollection->sortByDesc('total_responses')->values()->take(5)
        ];
    }

    private function getFilteredReportResponses(
        string $department,
        string $academicYear,
        string $semester,
        string $subjectType,
        array $allowedDepartments = [],
        array $excludedDepartments = []
    ) {
        $evaluationsQuery = Evaluation::where('is_active', true);

        if ($academicYear !== 'all') {
            $evaluationsQuery->where('academic_year', $academicYear);
        }
        if ($semester !== 'all') {
            $evaluationsQuery->where('semester', $semester);
        }

        foreach ($excludedDepartments as $excludedDepartment) {
            $evaluationsQuery->whereRaw(
                "NOT FIND_IN_SET(?, REPLACE(faculty_department_snapshot, ', ', ','))",
                [$excludedDepartment]
            );
        }

        if ($department !== 'all') {
            $this->whereAnyDepartment($evaluationsQuery, [$department], 'faculty_department_snapshot');
        } elseif (!empty($allowedDepartments)) {
            $this->whereAnyDepartment($evaluationsQuery, $allowedDepartments, 'faculty_department_snapshot');
        }

        return EvaluationResponse::with(['evaluation', 'schedule.facultyCourse.faculty.user', 'schedule.facultyCourse.course'])
            ->whereIn('evaluation_id', (clone $evaluationsQuery)->select('id'))
            ->get()
            ->filter(fn ($response) => $this->responseMatchesResolvedSubjectType($response, $subjectType))
            ->filter(function ($response) use ($department, $allowedDepartments) {
                if ($department !== 'all') {
                    return $this->responseHandledByDepartment($response, $department);
                }

                if (empty($allowedDepartments)) {
                    return true;
                }

                foreach ($allowedDepartments as $allowedDepartment) {
                    if ($this->responseHandledByDepartment($response, $allowedDepartment)) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }

    private function buildFacultyRowsFromResponses($responses, string $department)
    {
        return $responses
            ->groupBy(function ($response) {
                $facultyCourse = optional($response->schedule)->facultyCourse;
                $facultyId = optional($facultyCourse)->faculty_id;
                if ($facultyId) {
                    return 'faculty:' . $facultyId;
                }

                $userId = optional($response->evaluation)->faculty_id;
                if ($userId) {
                    return 'user:' . $userId;
                }

                return 'name:' . strtolower(trim((string) optional($response->evaluation)->resolved_faculty_name));
            })
            ->map(function ($facultyResponses) use ($department) {
                $firstResponse = $facultyResponses->sortByDesc('created_at')->first();
                $evaluation = $firstResponse?->evaluation;
                $facultyCourse = optional($firstResponse?->schedule)->facultyCourse;
                $faculty = optional($facultyCourse)->faculty;
                $facultyUser = optional($faculty)->user;
                $facultyName = trim((string) ($facultyUser?->name ?? $evaluation?->resolved_faculty_name ?? 'Unknown'));
                $status = strtolower((string) ($facultyUser?->status ?? 'active'));
                $evaluationCount = $facultyResponses->pluck('evaluation_id')->unique()->count();
                $average = (float) $facultyResponses->avg('effectiveness_rating');

                return (object) [
                    'evaluation_id' => $firstResponse?->evaluation_id,
                    'name' => $facultyName === '' ? 'Unknown' : $facultyName,
                    'department' => $department !== 'all'
                        ? $department
                        : ($facultyCourse?->department ?: $faculty?->department ?: $evaluation?->resolved_faculty_department ?: 'No department'),
                    'status' => $status,
                    'evaluation_count' => $evaluationCount,
                    'active_evaluations' => $evaluationCount,
                    'response_count' => $facultyResponses->count(),
                    'average_rating' => $average > 0 ? round($average, 2) : 0,
                ];
            })
            ->values();
    }

    private function getFacultyKey($evaluation): string
    {
        $facultyId = data_get($evaluation, 'faculty_id');
        if ($facultyId) {
            return 'id:' . $facultyId;
        }
        $name = strtolower(trim((string) data_get($evaluation, 'resolved_faculty_name', '')));
        return $name === '' ? 'unknown' : 'name:' . $name;
    }

    private function metricTitle(string $metric): string
    {
        return match ($metric) {
            'total_faculties' => 'Total Faculties',
            'total_responses' => 'Total Responses',
            'average_rating' => 'Average Rating Details',
            'courses_evaluated' => 'Courses Evaluated',
            default => 'Metric Details',
        };
    }

    private function metricColumns(string $metric): array
    {
        return match ($metric) {
            'total_faculties' => ['Faculty', 'Department', 'Evaluations', 'Responses', 'Average Rating'],
            'total_responses' => ['Faculty', 'Course', 'Rating', 'Feedback', 'Submitted'],
            'average_rating' => ['Faculty', 'Department', 'Average Rating', 'Responses', 'Courses'],
            'courses_evaluated' => ['Course', 'Subject Type', 'Responses', 'Average Rating', 'Faculty Handlers'],
            default => [],
        };
    }

    private function reportResponseBaseQuery(string $department, string $academicYear, string $semester, string $subjectType, array $allowedDepartments = [], array $excludedDepartments = [], string $program = 'all')
    {
        $query = DB::table('evaluation_responses')
            ->join('evaluations', 'evaluations.id', '=', 'evaluation_responses.evaluation_id')
            ->leftJoin('users', 'users.id', '=', 'evaluations.faculty_id')
            ->leftJoin('schedules', 'schedules.id', '=', 'evaluation_responses.schedule_id')
            ->leftJoin('faculty_courses', 'faculty_courses.id', '=', 'schedules.faculty_course_id')
            ->leftJoin('courses', 'courses.id', '=', 'faculty_courses.course_id')
            ->leftJoin('faculties', 'faculties.id', '=', 'faculty_courses.faculty_id')
            ->leftJoin('users as faculty_users', 'faculty_users.id', '=', 'faculties.user_id')
            ->where('evaluations.is_active', true)
            ->whereNull('evaluations.deleted_at')
            ->where(function ($inner) {
                $inner->whereNull('schedules.id')->orWhereNull('schedules.deleted_at');
            })
            ->where(function ($inner) {
                $inner->whereNull('faculty_courses.id')->orWhereNull('faculty_courses.deleted_at');
            })
            ->where(function ($inner) {
                $inner->whereNull('courses.id')->orWhereNull('courses.deleted_at');
            });

        if ($academicYear !== 'all') {
            $query->where('evaluations.academic_year', $academicYear)
                ->where(function ($inner) use ($academicYear) {
                    $inner->whereNull('faculty_courses.id')
                        ->orWhere('faculty_courses.academic_year', $academicYear);
                });
        }

        if ($semester !== 'all') {
            $query->where('evaluations.semester', $semester)
                ->where(function ($inner) use ($semester) {
                    $inner->whereNull('faculty_courses.id')
                        ->orWhere('faculty_courses.semester', $semester);
                });
        }

        if ($subjectType !== 'all') {
            $query->where('courses.subject_type', $subjectType);
        }

        if ($this->shouldApplyDentistryProgram($department, $allowedDepartments, null, $program)) {
            $this->applyDentistryProgramFilter($query, $program);
        }

        foreach ($excludedDepartments as $excludedDepartment) {
            $this->whereResponseNotHandledByDepartment($query, $excludedDepartment);
        }

        if ($department !== 'all') {
            $this->whereResponseHandledByAnyDepartment($query, [$department]);
        } elseif (!empty($allowedDepartments)) {
            $this->whereResponseHandledByAnyDepartment($query, $allowedDepartments);
        }

        return $query;
    }

    private function applyReportEvaluationFilters($query, string $department, string $academicYear, string $semester, array $allowedDepartments = [], array $excludedDepartments = []): void
    {
        $query->where('is_active', true);

        if ($academicYear !== 'all') {
            $query->where('academic_year', $academicYear);
        }
        if ($semester !== 'all') {
            $query->where('semester', $semester);
        }

        foreach ($excludedDepartments as $excludedDepartment) {
            $query->whereRaw(
                "NOT FIND_IN_SET(?, REPLACE(faculty_department_snapshot, ', ', ','))",
                [$excludedDepartment]
            );
        }

        if ($department !== 'all') {
            $this->whereAnyDepartment($query, [$department], 'faculty_department_snapshot');
        } elseif (!empty($allowedDepartments)) {
            $this->whereAnyDepartment($query, $allowedDepartments, 'faculty_department_snapshot');
        }
    }

    private function whereResponseHandledByAnyDepartment($query, array $departments): void
    {
        $aliases = collect($departments)
            ->flatMap(fn ($department) => $this->departmentAliases((string) $department))
            ->map(fn ($department) => $this->normalizeDepartmentName($department) ?? trim((string) $department))
            ->filter()
            ->unique()
            ->values();

        if ($aliases->isEmpty()) {
            $query->whereRaw('0 = 1');
            return;
        }

        $query->where(function ($builder) use ($aliases) {
            foreach ($aliases as $department) {
                $builder->orWhereRaw(
                    "FIND_IN_SET(?, REPLACE(COALESCE(NULLIF(faculty_courses.department, ''), evaluations.faculty_department_snapshot, ''), ', ', ','))",
                    [$department]
                );
            }
        });
    }

    private function whereResponseNotHandledByDepartment($query, string $department): void
    {
        $aliases = collect($this->departmentAliases($department))
            ->map(fn ($alias) => $this->normalizeDepartmentName($alias) ?? trim((string) $alias))
            ->filter()
            ->unique()
            ->values();

        foreach ($aliases as $alias) {
            $query->whereRaw(
                "NOT FIND_IN_SET(?, REPLACE(COALESCE(NULLIF(faculty_courses.department, ''), evaluations.faculty_department_snapshot, ''), ', ', ','))",
                [$alias]
            );
        }
    }

    private function metricEvaluationQuery(string $department, string $academicYear, string $semester, array $allowedDepartments, array $excludedDepartments)
    {
        $query = Evaluation::where('is_active', true);

        if ($academicYear !== 'all') {
            $query->where('academic_year', $academicYear);
        }
        if ($semester !== 'all') {
            $query->where('semester', $semester);
        }

        foreach ($excludedDepartments as $excludedDepartment) {
            $query->whereRaw(
                "NOT FIND_IN_SET(?, REPLACE(faculty_department_snapshot, ', ', ','))",
                [$excludedDepartment]
            );
        }

        if (!empty($allowedDepartments)) {
            $query->where(function ($inner) use ($allowedDepartments) {
                foreach ($allowedDepartments as $allowedDepartment) {
                    $inner->orWhereRaw(
                        "FIND_IN_SET(?, REPLACE(faculty_department_snapshot, ', ', ','))",
                        [$allowedDepartment]
                    );
                }
            });
        }

        if ($department !== 'all') {
            $query->whereRaw(
                "FIND_IN_SET(?, REPLACE(faculty_department_snapshot, ', ', ','))",
                [$department]
            );
        }

        return $query;
    }

    private function metricResponseQuery(string $department, string $academicYear, string $semester, string $subjectType, array $allowedDepartments, array $excludedDepartments)
    {
        $query = EvaluationResponse::with(['evaluation', 'schedule.facultyCourse.course'])
            ->whereHas('evaluation', function ($evaluationQuery) use ($department, $academicYear, $semester, $allowedDepartments, $excludedDepartments) {
                $this->applyMetricEvaluationFilters($evaluationQuery, $department, $academicYear, $semester, $allowedDepartments, $excludedDepartments);
            });

        if ($subjectType !== 'all') {
            $query->whereHas('schedule.facultyCourse.course', function ($courseQuery) use ($subjectType) {
                $courseQuery->where('subject_type', $subjectType);
            });
        }

        return $query;
    }

    private function applyMetricEvaluationFilters($query, string $department, string $academicYear, string $semester, array $allowedDepartments, array $excludedDepartments): void
    {
        $query->where('is_active', true);

        if ($academicYear !== 'all') {
            $query->where('academic_year', $academicYear);
        }
        if ($semester !== 'all') {
            $query->where('semester', $semester);
        }
        foreach ($excludedDepartments as $excludedDepartment) {
            $query->whereRaw(
                "NOT FIND_IN_SET(?, REPLACE(faculty_department_snapshot, ', ', ','))",
                [$excludedDepartment]
            );
        }
        if (!empty($allowedDepartments)) {
            $query->where(function ($inner) use ($allowedDepartments) {
                foreach ($allowedDepartments as $allowedDepartment) {
                    $inner->orWhereRaw(
                        "FIND_IN_SET(?, REPLACE(faculty_department_snapshot, ', ', ','))",
                        [$allowedDepartment]
                    );
                }
            });
        }
        if ($department !== 'all') {
            $query->whereRaw(
                "FIND_IN_SET(?, REPLACE(faculty_department_snapshot, ', ', ','))",
                [$department]
            );
        }
    }

    private function buildMetricFacultyRows(string $department, string $academicYear, string $semester, string $subjectType, string $program, array $allowedDepartments, array $excludedDepartments, int $page, int $perPage): array
    {
        $responseCountSql = $subjectType === 'all'
            ? 'COUNT(evaluation_responses.id)'
            : "SUM(CASE WHEN courses.subject_type = ? THEN 1 ELSE 0 END)";
        $averageSql = $subjectType === 'all'
            ? 'AVG(evaluation_responses.effectiveness_rating)'
            : "AVG(CASE WHEN courses.subject_type = ? THEN evaluation_responses.effectiveness_rating ELSE NULL END)";

        $bindings = $subjectType === 'all' ? [] : [$subjectType, $subjectType];

        $query = DB::table('evaluations')
            ->leftJoin('users', 'users.id', '=', 'evaluations.faculty_id')
            ->leftJoin('evaluation_responses', 'evaluation_responses.evaluation_id', '=', 'evaluations.id')
            ->leftJoin('schedules', 'schedules.id', '=', 'evaluation_responses.schedule_id')
            ->leftJoin('faculty_courses', 'faculty_courses.id', '=', 'schedules.faculty_course_id')
            ->leftJoin('courses', 'courses.id', '=', 'faculty_courses.course_id')
            ->selectRaw("
                evaluations.faculty_id,
                COALESCE(NULLIF(users.name, ''), NULLIF(evaluations.faculty_name_snapshot, ''), 'Unknown') as faculty_name,
                COALESCE(NULLIF(users.department, ''), NULLIF(evaluations.faculty_department_snapshot, ''), 'No department') as department,
                COUNT(DISTINCT evaluations.id) as evaluation_count,
                {$responseCountSql} as response_count,
                {$averageSql} as average_rating
            ", $bindings);

        $this->applyMetricEvaluationFiltersToQuery($query, $department, $academicYear, $semester, $allowedDepartments, $excludedDepartments);
        if ($this->shouldApplyDentistryProgram($department, $allowedDepartments, null, $program)) {
            $this->applyDentistryProgramFilter($query, $program);
        }

        $paginator = $query
            ->groupBy(
                'evaluations.faculty_id',
                'users.name',
                'evaluations.faculty_name_snapshot',
                'users.department',
                'evaluations.faculty_department_snapshot'
            )
            ->orderBy('faculty_name')
            ->simplePaginate($perPage, ['*'], 'page', $page);

        return $this->formatMetricPaginator($paginator, function ($row) {
            $responseCount = (int) ($row->response_count ?? 0);
            return [
                'cells' => [
                    $row->faculty_name ?: 'Unknown',
                    $row->department ?: 'No department',
                    (string) $row->evaluation_count,
                    (string) $responseCount,
                    $responseCount > 0 ? round((float) $row->average_rating, 2) . '/4.0' : 'N/A',
                ],
            ];
        });
    }

    private function buildMetricResponseRows(string $department, string $academicYear, string $semester, string $subjectType, string $program, array $allowedDepartments, array $excludedDepartments, int $page, int $perPage): array
    {
        $query = $this->metricResponseBaseQuery($department, $academicYear, $semester, $subjectType, $allowedDepartments, $excludedDepartments, $program)
            ->selectRaw("
                evaluation_responses.id,
                COALESCE(NULLIF(users.name, ''), NULLIF(evaluations.faculty_name_snapshot, ''), 'Unknown') as faculty_name,
                COALESCE(NULLIF(courses.class_code, ''), NULLIF(evaluation_responses.course_code_snapshot, ''), 'N/A') as course_code,
                evaluation_responses.effectiveness_rating,
                evaluation_responses.feedback_comments,
                evaluation_responses.created_at
            ")
            ->orderByDesc('evaluation_responses.created_at');

        $paginator = $query->simplePaginate($perPage, ['*'], 'page', $page);

        return $this->formatMetricPaginator($paginator, function ($row) {
            return [
                'cells' => [
                    $row->faculty_name ?: 'Unknown',
                    $row->course_code ?: 'N/A',
                    (string) $row->effectiveness_rating . '/4',
                    Str::limit((string) ($row->feedback_comments ?: 'No feedback'), 90),
                    $row->created_at ? Carbon::parse($row->created_at)->format('M d, Y h:i A') : 'N/A',
                ],
            ];
        });
    }

    private function buildMetricRatingRows(string $department, string $academicYear, string $semester, string $subjectType, string $program, array $allowedDepartments, array $excludedDepartments, int $page, int $perPage): array
    {
        $query = $this->metricResponseBaseQuery($department, $academicYear, $semester, $subjectType, $allowedDepartments, $excludedDepartments, $program)
            ->selectRaw("
                evaluations.faculty_id,
                COALESCE(NULLIF(users.name, ''), NULLIF(evaluations.faculty_name_snapshot, ''), 'Unknown') as faculty_name,
                COALESCE(NULLIF(users.department, ''), NULLIF(evaluations.faculty_department_snapshot, ''), 'No department') as department,
                AVG(evaluation_responses.effectiveness_rating) as average_rating,
                COUNT(evaluation_responses.id) as response_count,
                COUNT(DISTINCT evaluation_responses.schedule_id) as course_count
            ")
            ->groupBy(
                'evaluations.faculty_id',
                'users.name',
                'evaluations.faculty_name_snapshot',
                'users.department',
                'evaluations.faculty_department_snapshot'
            )
            ->having('response_count', '>', 0)
            ->orderByDesc('average_rating');

        $paginator = $query->simplePaginate($perPage, ['*'], 'page', $page);

        return $this->formatMetricPaginator($paginator, function ($row) {
            return [
                'cells' => [
                    $row->faculty_name ?: 'Unknown',
                    $row->department ?: 'No department',
                    round((float) $row->average_rating, 2) . '/4.0',
                    (string) $row->response_count,
                    (string) $row->course_count,
                ],
            ];
        });
    }

    private function buildMetricCourseRows(string $department, string $academicYear, string $semester, string $subjectType, string $program, array $allowedDepartments, array $excludedDepartments, int $page, int $perPage): array
    {
        $query = $this->metricResponseBaseQuery($department, $academicYear, $semester, $subjectType, $allowedDepartments, $excludedDepartments, $program)
            ->selectRaw("
                COALESCE(courses.id, CONCAT('snapshot:', COALESCE(evaluation_responses.course_code_snapshot, 'N/A'))) as course_key,
                TRIM(CONCAT(
                    COALESCE(NULLIF(courses.class_code, ''), NULLIF(evaluation_responses.course_code_snapshot, ''), 'N/A'),
                    CASE WHEN COALESCE(courses.subject_code, evaluation_responses.course_name_snapshot, '') <> ''
                        THEN CONCAT(' - ', COALESCE(courses.subject_code, evaluation_responses.course_name_snapshot))
                        ELSE ''
                    END
                )) as course_label,
                COALESCE(courses.subject_type, 'N/A') as subject_type,
                COUNT(evaluation_responses.id) as response_count,
                AVG(evaluation_responses.effectiveness_rating) as average_rating,
                GROUP_CONCAT(DISTINCT COALESCE(NULLIF(users.name, ''), NULLIF(evaluations.faculty_name_snapshot, '')) ORDER BY users.name SEPARATOR ', ') as handlers
            ")
            ->groupBy(
                'courses.id',
                'courses.class_code',
                'courses.subject_code',
                'courses.subject_type',
                'evaluation_responses.course_code_snapshot',
                'evaluation_responses.course_name_snapshot'
            )
            ->orderByDesc('response_count');

        $paginator = $query->simplePaginate($perPage, ['*'], 'page', $page);

        return $this->formatMetricPaginator($paginator, function ($row) {
            $subjectType = match ($row->subject_type) {
                'major' => 'Professional',
                'minor' => 'GenEd',
                default => 'N/A',
            };

            return [
                'cells' => [
                    $row->course_label ?: 'N/A',
                    $subjectType,
                    (string) $row->response_count,
                    $row->response_count > 0 ? round((float) $row->average_rating, 2) . '/4.0' : 'N/A',
                    $row->handlers ?: 'N/A',
                ],
            ];
        });
    }

    private function metricResponseBaseQuery(string $department, string $academicYear, string $semester, string $subjectType, array $allowedDepartments, array $excludedDepartments, string $program = 'all')
    {
        $query = DB::table('evaluation_responses')
            ->join('evaluations', 'evaluations.id', '=', 'evaluation_responses.evaluation_id')
            ->leftJoin('users', 'users.id', '=', 'evaluations.faculty_id')
            ->leftJoin('schedules', 'schedules.id', '=', 'evaluation_responses.schedule_id')
            ->leftJoin('faculty_courses', 'faculty_courses.id', '=', 'schedules.faculty_course_id')
            ->leftJoin('courses', 'courses.id', '=', 'faculty_courses.course_id');

        $this->applyMetricEvaluationFiltersToQuery($query, $department, $academicYear, $semester, $allowedDepartments, $excludedDepartments);

        if ($subjectType !== 'all') {
            $query->where('courses.subject_type', $subjectType);
        }

        if ($this->shouldApplyDentistryProgram($department, $allowedDepartments, null, $program)) {
            $this->applyDentistryProgramFilter($query, $program);
        }

        return $query;
    }

    private function applyMetricEvaluationFiltersToQuery($query, string $department, string $academicYear, string $semester, array $allowedDepartments, array $excludedDepartments): void
    {
        $query->where('evaluations.is_active', true);

        if ($academicYear !== 'all') {
            $query->where('evaluations.academic_year', $academicYear);
        }
        if ($semester !== 'all') {
            $query->where('evaluations.semester', $semester);
        }

        foreach ($excludedDepartments as $excludedDepartment) {
            $query->whereRaw(
                "NOT FIND_IN_SET(?, REPLACE(evaluations.faculty_department_snapshot, ', ', ','))",
                [$excludedDepartment]
            );
        }

        if (!empty($allowedDepartments)) {
            $query->where(function ($inner) use ($allowedDepartments) {
                foreach ($allowedDepartments as $allowedDepartment) {
                    $inner->orWhereRaw(
                        "FIND_IN_SET(?, REPLACE(evaluations.faculty_department_snapshot, ', ', ','))",
                        [$allowedDepartment]
                    );
                }
            });
        }

        if ($department !== 'all') {
            $query->whereRaw(
                "FIND_IN_SET(?, REPLACE(evaluations.faculty_department_snapshot, ', ', ','))",
                [$department]
            );
        }
    }

    private function formatMetricPaginator($paginator, callable $mapper): array
    {
        $items = collect($paginator->items())->map($mapper)->values();
        $page = $paginator->currentPage();
        $perPage = $paginator->perPage();
        $from = $items->isEmpty() ? 0 : (($page - 1) * $perPage) + 1;
        $to = $items->isEmpty() ? 0 : $from + $items->count() - 1;
        $hasMore = method_exists($paginator, 'hasMorePages') ? $paginator->hasMorePages() : false;

        return [
            'items' => $items,
            'meta' => [
                'total' => null,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => $hasMore ? $page + 1 : $page,
                'has_more' => $hasMore,
                'from' => $from,
                'to' => $to,
            ],
        ];
    }

    private function buildFacultyDepartmentCounts(array $allowedDepartments = [], array $excludedDepartments = []): array
    {
        $faculties = Faculty::with('user:id,department')->get();
        $counts = [];

        foreach ($faculties as $faculty) {
            $raw = trim((string) ($faculty->department ?? $faculty->user?->department ?? ''));
            if ($raw === '') {
                continue;
            }
            $departments = Faculty::normalizeDepartmentList($raw);
            foreach ($departments as $department) {
                if (in_array($department, $excludedDepartments, true)) {
                    continue;
                }
                if (!empty($allowedDepartments) && !in_array($department, $allowedDepartments, true)) {
                    continue;
                }
                $counts[$department] = ($counts[$department] ?? 0) + 1;
            }
        }

        return $counts;
    }

    private function normalizeDepartmentList(?string $departments): array
    {
        $departments = trim((string) ($departments ?? ''));
        if ($departments === '') {
            return [];
        }

        $decoded = json_decode($departments, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $items = $decoded;
        } else {
            $items = preg_split('/\s*,\s*/', $departments);
        }
        $normalized = array_map(function ($value) {
            $value = preg_replace('/\s+/', ' ', trim((string) $value));
            return $this->normalizeDepartmentName($value) ?? $value;
        }, $items);
        $filtered = array_filter($normalized, static fn ($value) => $value !== '');
        return array_values(array_unique($filtered));
    }

    private function formatSemesterLabel(?string $semester): string
    {
        $value = trim((string) ($semester ?? ''));
        $normalized = strtolower($value);

        return match ($normalized) {
            '1st', 'first', 'first semester', '1st semester' => '1st Semester',
            '2nd', 'second', 'second semester', '2nd semester' => '2nd Semester',
            'summer', 'summer semester' => 'Summer',
            default => $value,
        };
    }

    private function formatEffectivenessText(int $rating): string
    {
        return match ($rating) {
            4 => 'Very Effective',
            3 => 'Effective',
            2 => 'Somewhat Effective',
            1 => 'Not Effective',
            default => 'Unknown',
        };
    }

    private function responseHandledByDepartment($response, string $department): bool
    {
        $facultyCourse = optional($response->schedule)->facultyCourse;
        $assignmentDepartments = $this->normalizeDepartmentList(optional($facultyCourse)->department ?? '');
        $selectedAliases = collect($this->departmentAliases($department))
            ->map(fn ($value) => $this->normalizeDepartmentName($value) ?? $value)
            ->unique()
            ->values()
            ->all();

        if (!empty($assignmentDepartments)) {
            $assignmentAliases = collect($assignmentDepartments)
                ->flatMap(fn ($value) => $this->departmentAliases($value))
                ->map(fn ($value) => $this->normalizeDepartmentName($value) ?? $value)
                ->unique()
                ->values();

            if ($assignmentAliases->intersect($selectedAliases)->isNotEmpty()) {
                return true;
            }
        }

        $snapshotDepartments = $this->normalizeDepartmentList(optional($response->evaluation)->faculty_department_snapshot ?? '');
        if (count($snapshotDepartments) !== 1) {
            return false;
        }

        $snapshotAliases = collect($snapshotDepartments)
            ->flatMap(fn ($value) => $this->departmentAliases($value))
            ->map(fn ($value) => $this->normalizeDepartmentName($value) ?? $value)
            ->unique()
            ->values();

        return $snapshotAliases->intersect($selectedAliases)->isNotEmpty();
    }

    private function responseMatchesResolvedSubjectType($response, string $subjectType): bool
    {
        if ($subjectType === 'all') {
            return true;
        }

        return $this->resolveResponseSubjectType($response) === $subjectType;
    }

    private function resolveResponseSubjectType($response): string
    {
        $course = optional(optional($response->schedule)->facultyCourse)->course;

        return strtolower(trim((string) ($course->subject_type ?? '')));
    }

    private function formatResolvedResponseSubjectType($response): string
    {
        return match ($this->resolveResponseSubjectType($response)) {
            'major' => 'Professional Course',
            'minor' => 'Minor Course',
            default => 'N/A',
        };
    }

    private function normalizeDentistryProgram(?string $program): string
    {
        $value = strtolower(trim((string) ($program ?? 'all')));

        if ($value === 'dmd') {
            $value = 'ddm';
        }

        return in_array($value, ['ddm', 'msd'], true) ? $value : 'all';
    }

    private function formatDentistryProgramLabel(string $program): string
    {
        return match ($this->normalizeDentistryProgram($program)) {
            'ddm' => 'DDM',
            'msd' => 'MSD',
            default => 'All Programs',
        };
    }

    private function shouldApplyDentistryProgram(string $department, array $allowedDepartments = [], ?User $user = null, string $program = 'all'): bool
    {
        if (!$this->isDentistryScope($department, $allowedDepartments)) {
            return false;
        }

        if ($this->normalizeDentistryProgram($program) !== 'all') {
            return true;
        }

        return $this->isDentistryProgramRestrictedUser($user ?? request()->user());
    }

    private function isDentistryScope(string $department, array $allowedDepartments = []): bool
    {
        if ($department === 'College of Dentistry') {
            return true;
        }

        if ($department !== 'all') {
            return false;
        }

        $normalizedAllowedDepartments = collect($allowedDepartments)
            ->map(fn ($value) => $this->normalizeDepartmentName((string) $value) ?? trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        return $normalizedAllowedDepartments->count() === 1
            && $normalizedAllowedDepartments->first() === 'College of Dentistry';
    }

    private function isDentistryProgramRestrictedUser(?User $user): bool
    {
        if (!$user || $user->role === 'Admin') {
            return false;
        }

        $jobTitle = strtolower(trim((string) ($user->job_title ?? $user->faculty?->job_title ?? '')));

        return in_array($jobTitle, ['dean', 'vice dean'], true);
    }

    private function applyDentistryProgramFilter($query, string $program): void
    {
        $program = $this->normalizeDentistryProgram($program);
        $prefixes = $program === 'all' ? ['DDM', 'MSD'] : [strtoupper($program)];

        $query->where(function ($builder) use ($prefixes) {
            foreach ($prefixes as $prefix) {
                $builder->orWhere(function ($inner) use ($prefix) {
                    $inner
                        ->whereRaw("UPPER(REPLACE(COALESCE(faculty_courses.section, ''), ' ', '')) LIKE ?", [$prefix . '%'])
                        ->orWhereRaw("UPPER(REPLACE(COALESCE(courses.class_code, ''), ' ', '')) LIKE ?", [$prefix . '%'])
                        ->orWhereRaw("UPPER(REPLACE(COALESCE(courses.subject_code, ''), ' ', '')) LIKE ?", [$prefix . '%'])
                        ->orWhereRaw("UPPER(REPLACE(COALESCE(evaluation_responses.course_code_snapshot, ''), ' ', '')) LIKE ?", [$prefix . '%'])
                        ->orWhereRaw("UPPER(REPLACE(COALESCE(evaluation_responses.course_name_snapshot, ''), ' ', '')) LIKE ?", [$prefix . '%']);
                });
            }
        });
    }

    private function normalizeDepartmentName(?string $department): ?string
    {
        $department = preg_replace('/\s+/', ' ', trim((string) ($department ?? '')));

        if ($department === '') {
            return null;
        }

        $map = [
            'nursing' => 'College of Nursing',
            'con' => 'College of Nursing',
            'bs nursing' => 'College of Nursing',
            'bsn' => 'College of Nursing',
            'bachelor of science in nursing' => 'College of Nursing',
            'dentistry' => 'College of Dentistry',
            'cod' => 'College of Dentistry',
            'ddm' => 'College of Dentistry',
            'dmd' => 'College of Dentistry',
            'dds' => 'College of Dentistry',
            'msd' => 'College of Dentistry',
            'msdo' => 'College of Dentistry',
            'master of science in dentistry' => 'College of Dentistry',
            'master of science in dentistry with specialization in orthodontics' => 'College of Dentistry',
            'cas' => 'College of Arts and Sciences',
            'arts and sciences' => 'College of Arts and Sciences',
            'bachelor of arts in communication' => 'College of Arts and Sciences',
            'communication' => 'College of Arts and Sciences',
            'psychology' => 'College of Arts and Sciences',
            'bs psych' => 'College of Arts and Sciences',
            'bspsych' => 'College of Arts and Sciences',
            'ab communication' => 'College of Arts and Sciences',
            'bachelor of science in psychology' => 'College of Arts and Sciences',
            'bsit' => 'College of Arts and Sciences',
            'cas bs in information technology' => 'College of Arts and Sciences',
            'information technology' => 'College of Arts and Sciences',
            'cmt' => 'College of Medical Technology',
            'bs mt' => 'College of Medical Technology',
            'bsmt' => 'College of Medical Technology',
            'medical technology' => 'College of Medical Technology',
            'bachelor of science in medical technology' => 'College of Medical Technology',
            'medicine' => 'College of Medicine',
            'com' => 'College of Medicine',
            'college of medicine' => 'College of Medicine',
            'optometry' => 'College of Optometry',
            'coo' => 'College of Optometry',
            'pharmacy' => 'College of Pharmacy',
            'cop' => 'College of Pharmacy',
            'physical therapy' => 'College of Physical Therapy',
            'pt' => 'College of Physical Therapy',
            'cpt' => 'College of Physical Therapy',
            'bed' => 'Basic Education',
            'bedd' => 'Basic Education',
            'bed d' => 'Basic Education',
            'beded' => 'Basic Education',
            'basic education department' => 'Basic Education',
            'institute of education' => 'Institute of Education',
            'ioe' => 'Institute of Education',
            'ied' => 'Institute of Education',
            'education institute' => 'Institute of Education',
            'business' => 'School of Business and Management',
            'business and management' => 'School of Business and Management',
            'school of business' => 'School of Business and Management',
            'school and business management' => 'School of Business and Management',
            'school and business' => 'School of Business and Management',
            'sbm' => 'School of Business and Management',
            'bsba' => 'School of Business and Management',
        ];

        $mappedDepartment = $map[$this->departmentKey($department)] ?? null;
        if ($mappedDepartment !== null) {
            return $mappedDepartment;
        }

        foreach (self::OFFICIAL_DEPARTMENTS as $officialDepartment) {
            if (strcasecmp($department, $officialDepartment) === 0) {
                return $officialDepartment;
            }
        }

        return null;
    }

    private function departmentAliases(string $department): array
    {
        $officialDepartment = $this->normalizeDepartmentName($department) ?? $department;

        $aliases = [
            'College of Nursing' => ['College of Nursing', 'Nursing', 'CON', 'BS Nursing', 'BSN', 'BACHELOR OF SCIENCE IN NURSING'],
            'College of Dentistry' => ['College of Dentistry', 'Dentistry', 'COD', 'DDM', 'DMD', 'DDS', 'MSD', 'MSDO', 'Master of Science in Dentistry', 'Master of Science in Dentistry with specialization in Orthodontics'],
            'College of Arts and Sciences' => ['College of Arts and Sciences', 'CAS', 'Arts and Sciences', 'BACHELOR OF ARTS IN COMMUNICATION', 'Communication', 'Psychology', 'BS Psych', 'BSPSYCH', 'AB Communication', 'BACHELOR OF SCIENCE IN PSYCHOLOGY', 'BSIT', 'CAS-BS IN INFORMATION TECHNOLOGY', 'Information Technology'],
            'College of Medical Technology' => ['College of Medical Technology', 'CMT', 'BS MT', 'BSMT', 'Medical Technology', 'CMT - BS IN MEDICAL TECHNOLOGY', 'BACHELOR OF SCIENCE IN MEDICAL TECHNOLOGY'],
            'College of Medicine' => ['College of Medicine', 'Medicine', 'COM', 'COLLEGE OF MEDICINE'],
            'College of Optometry' => ['College of Optometry', 'Optometry', 'COO'],
            'College of Pharmacy' => ['College of Pharmacy', 'Pharmacy', 'COP'],
            'College of Physical Therapy' => ['College of Physical Therapy', 'Physical Therapy', 'PT', 'CPT'],
            'Basic Education' => ['Basic Education', 'BED', 'BEDD', 'BEdD', 'Bed D', 'BEDED', 'Basic Education Department'],
            'Institute of Education' => ['Institute of Education', 'IOE', 'IED', 'BSED', 'PHD', 'MAED CI', 'MAED ADM '],
            'School of Business and Management' => ['School of Business and Management', 'Business', 'Business and Management', 'School of Business', 'SBM', 'BSBA'],
        ];

        return array_values(array_unique($aliases[$officialDepartment] ?? [$officialDepartment]));
    }

    private function departmentKey(string $department): string
    {
        $key = strtolower($department);
        $key = preg_replace('/[^a-z0-9]+/', ' ', $key);
        return trim(preg_replace('/\s+/', ' ', $key));
    }

    private function whereAnyDepartment($query, array $departments, string $column): void
    {
        $aliases = collect($departments)
            ->flatMap(fn ($department) => $this->departmentAliases((string) $department))
            ->filter()
            ->unique()
            ->values();

        if ($aliases->isEmpty()) {
            $query->whereRaw('0 = 1');
            return;
        }

        $query->where(function ($builder) use ($aliases, $column) {
            foreach ($aliases as $department) {
                $builder->orWhereRaw(
                    "FIND_IN_SET(?, REPLACE($column, ', ', ','))",
                    [$department]
                );
            }
        });
    }

    private function resolveDepartmentScope(?User $user): array
    {
        if (!$user) {
            return [];
        }

        $departmentList = array_merge(
            $this->normalizeDepartmentList($user->department ?? ''),
            $this->normalizeDepartmentList($user->faculty?->department ?? '')
        );

        return array_values(array_unique($departmentList));
    }

    private function paginateCollection($items, int $perPage)
    {
        $page = request()->get('page', 1);
        $page = is_numeric($page) ? (int) $page : 1;
        $offset = max($page - 1, 0) * $perPage;

        $total = $items->count();
        $pagedItems = $items->slice($offset, $perPage)->values();

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $pagedItems,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }
}
