<?php

namespace App\Http\Controllers\data_management;

use App\Http\Controllers\Controller;
use App\Models\Schedule;
use App\Models\Faculty;
use App\Models\FacultyCourse;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Imports\ScheduleImport;
use Maatwebsite\Excel\Facades\Excel;

class ScheduleController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $shouldFilter = $user && $user->role !== 'Admin';
        $departmentFilters = $this->resolveDepartmentScope($user);

        if ($shouldFilter && empty($departmentFilters)) {
            $schedules = collect();
            $facultyCourses = collect();
        } else {
            $schedulesQuery = Schedule::select(['id', 'faculty_course_id', 'time', 'day', 'status', 'created_at'])
                ->with([
                    'facultyCourse:id,faculty_id,course_id,section,academic_year,semester',
                    'facultyCourse.faculty:id,user_id,department,job_title',
                    'facultyCourse.faculty.user:id,name,email',
                    'facultyCourse.course:id,class_code,subject_code,subject_type',
                ])
                ->orderBy('day', 'asc')
                ->orderBy('time', 'asc');
            $facultyCoursesQuery = FacultyCourse::select([
                    'id',
                    'faculty_id',
                    'course_id',
                    'section',
                    'academic_year',
                    'semester',
                ])
                ->with([
                    'faculty:id,user_id,department,job_title',
                    'faculty.user:id,name,email',
                    'course:id,class_code,subject_code,subject_type',
                ]);

            if ($shouldFilter) {
                $schedulesQuery->whereHas('facultyCourse.faculty', function ($query) use ($departmentFilters) {
                    $query->forDepartments($departmentFilters);
                });
                $facultyCoursesQuery->whereHas('faculty', function ($query) use ($departmentFilters) {
                    $query->forDepartments($departmentFilters);
                });
            }

            $schedules = $schedulesQuery->get();
            $facultyCourses = $facultyCoursesQuery->get();
        }

        return view('content.data-management.dm-schedules', compact('schedules', 'facultyCourses'));
    }

    public function import(Request $request)
    {
        $this->authorizeAdminOnly();

        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx|max:10240'
        ]);

        $import = new ScheduleImport();

        Excel::import($import, $request->file('file'));

        $errors = $import->getErrors();
        $errorCount = count($errors);

        // OPTIONAL: count success (if needed later you can extend)
        $message = $errorCount > 0
            ? "Import completed with {$errorCount} errors."
            : "Schedules imported successfully.";

        return back()->with([
            'success' => $message,
            'errors' => $errors
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAdminOnly();

        $validated = $request->validate([
            'faculty_course_id' => 'required|exists:faculty_courses,id',
            'time' => 'nullable|string', // e.g. "07:00a - 08:30a"
            'day' => 'nullable|array',
            'day.*' => 'in:M,T,W,TH,F,S,SU',
        ]);

        $validated['day'] = !empty($validated['day'] ?? []) ? implode('', $validated['day']) : null; // e.g. ["T","TH"] => "TTH"
        $validated['time'] = $this->normalizeTimeInput((string) ($validated['time'] ?? ''));

        $exists = Schedule::where('faculty_course_id', $validated['faculty_course_id'])
            ->where('time', $validated['time'])
            ->where('day', $validated['day'])
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'This schedule already exists for the selected course, time, and day(s).'
            ], 422);
        }

        $schedule = Schedule::create($validated);
        $schedule->load([
            'facultyCourse.course',
            'facultyCourse.faculty.user',
        ]);
        return response()->json([
            'success' => true,
            'message' => 'Schedule created successfully',
            'data' => $schedule
        ]);
    }

    public function update(Request $request, Schedule $schedule): JsonResponse
    {
        $this->authorizeScheduleDepartmentAccess($schedule);

        $validated = $request->validate([
            'faculty_course_id' => 'required|exists:faculty_courses,id',
            'time' => 'nullable|string', // e.g. "07:00a - 08:30a"
            'day' => 'nullable|array',
            'day.*' => 'in:M,T,W,TH,F,S,SU', // Validate that each day is one of the allowed values
            'subject_type' => 'nullable|string|in:major,minor',
        ]);

        $subjectType = auth()->user()?->role === 'Admin'
            ? ($validated['subject_type'] ?? null)
            : null;
        unset($validated['subject_type']);

        $validated['day'] = !empty($validated['day'] ?? []) ? implode('', $validated['day']) : null;
        $validated['time'] = $this->normalizeTimeInput((string) ($validated['time'] ?? ''));
        $targetFacultyCourse = FacultyCourse::with(['course', 'faculty.user'])->findOrFail($validated['faculty_course_id']);
        $this->authorizeFacultyCourseDepartmentAccess($targetFacultyCourse);
        $originalFacultyCourse = $schedule->facultyCourse;
        $subjectTypeChanged = $subjectType !== null
            && $targetFacultyCourse->course
            && $targetFacultyCourse->course->subject_type !== $subjectType;

        if ($subjectType !== null) {
            $targetFacultyCourse = $this->resolveFacultyCourseForScheduleSubjectType($targetFacultyCourse, $subjectType);
            $validated['faculty_course_id'] = $targetFacultyCourse->id;
        }

        $existingSchedule = Schedule::where('faculty_course_id', $validated['faculty_course_id'])
            ->where('time', $validated['time'])
            ->where('day', $validated['day'])
            ->where('id', '!=', $schedule->id)
            ->first();

        if ($existingSchedule) {
            $schedule->delete();
            $this->deleteEmptyFacultyCourse($originalFacultyCourse);

            $existingSchedule->load([
                'facultyCourse.course',
                'facultyCourse.faculty.user',
            ]);

            return response()->json([
                'success' => true,
                'message' => $subjectTypeChanged
                    ? 'Subject type correction matched an existing schedule, so the duplicate was merged.'
                    : 'Matching schedule already existed, so the duplicate was merged.',
                'merged' => true,
                'subject_type_updated' => $subjectTypeChanged,
                'removed_id' => $schedule->id,
                'data' => $existingSchedule,
            ]);
        }

        $schedule->update($validated);
        $this->deleteEmptyFacultyCourse($originalFacultyCourse);
        $schedule->load([
            'facultyCourse.course',
            'facultyCourse.faculty.user',
        ]);
        return response()->json([
            'success' => true,
            'message' => $subjectTypeChanged
                ? 'Schedule updated and subject type corrected successfully.'
                : 'Schedule updated successfully!',
            'subject_type_updated' => $subjectTypeChanged,
            'data' => $schedule
        ]);
    }

    public function destroy(Schedule $schedule): JsonResponse
    {
        $this->authorizeAdminOnly();

        $schedule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Schedule deleted successfully!'
        ]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $this->authorizeAdminOnly();

        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:schedules,id',
        ]);

        $ids = collect($validated['ids'])->unique()->values();
        $deleted = Schedule::whereIn('id', $ids)->delete();

        return response()->json([
            'success' => true,
            'message' => $deleted > 1
                ? "{$deleted} schedules deleted successfully!"
                : 'Schedule deleted successfully!',
            'deleted' => $ids,
        ]);
    }

    public function deletedList(): JsonResponse
    {
        $this->authorizeAdminOnly();

        $schedules = Schedule::onlyTrashed()
            ->with([
                'facultyCourse:id,faculty_id,course_id,section,academic_year,semester',
                'facultyCourse.faculty:id,user_id,department,job_title',
                'facultyCourse.faculty.user:id,name,email',
                'facultyCourse.course:id,class_code,subject_code,subject_type',
            ])
            ->latest('deleted_at')
            ->limit(100)
            ->get()
            ->map(function (Schedule $schedule) {
                $facultyCourse = $schedule->facultyCourse;
                $course = $facultyCourse?->course;
                $faculty = $facultyCourse?->faculty;
                $facultyUser = $faculty?->user;

                return [
                    'id' => $schedule->id,
                    'course' => trim(($course?->class_code ?? 'N/A') . ' - ' . ($course?->subject_code ?? '')),
                    'subject_type' => $course?->subject_type ?? 'N/A',
                    'section' => $facultyCourse?->section ?? 'N/A',
                    'faculty_name' => $facultyUser?->name ?? 'N/A',
                    'academic_year' => $facultyCourse?->academic_year ?? 'N/A',
                    'semester' => $facultyCourse?->semester ?? 'N/A',
                    'schedule' => Schedule::formatScheduleLabel($schedule->day, $schedule->time),
                    'status' => $schedule->status ?? 'scheduled',
                    'deleted_at' => optional($schedule->deleted_at)->format('M d, Y h:i A') ?? 'N/A',
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => $schedules,
        ]);
    }

    public function restore(int $id): JsonResponse
    {
        $this->authorizeAdminOnly();

        $schedule = Schedule::onlyTrashed()->findOrFail($id);
        $schedule->restore();

        return response()->json([
            'success' => true,
            'message' => 'Schedule restored successfully.',
            'data' => $schedule,
        ]);
    }

    public function forceDelete(int $id): JsonResponse
    {
        $this->authorizeAdminOnly();

        $schedule = Schedule::onlyTrashed()->findOrFail($id);
        $schedule->forceDelete();

        return response()->json([
            'success' => true,
            'message' => 'Schedule permanently deleted successfully.',
            'deleted_id' => $id,
        ]);
    }

    private function resolveFacultyCourseForScheduleSubjectType(FacultyCourse $facultyCourse, string $subjectType): FacultyCourse
    {
        $facultyCourse->loadMissing('course');
        $course = $facultyCourse->course;

        if (!$course || $course->subject_type === $subjectType) {
            return $facultyCourse;
        }

        $targetCourse = Course::withTrashed()
            ->where('class_code', $course->class_code)
            ->where('subject_code', $course->subject_code)
            ->where('subject_type', $subjectType)
            ->first();

        if (!$targetCourse) {
            $targetCourse = Course::create([
                'class_code' => $course->class_code,
                'subject_code' => $course->subject_code,
                'subject_type' => $subjectType,
            ]);
        } elseif ($targetCourse->trashed()) {
            $targetCourse->restore();
        }

        $targetAssignment = FacultyCourse::withTrashed()
            ->where('faculty_id', $facultyCourse->faculty_id)
            ->where('course_id', $targetCourse->id)
            ->where('section', $facultyCourse->section)
            ->where('academic_year', $facultyCourse->academic_year)
            ->where('semester', $facultyCourse->semester)
            ->first();

        if ($targetAssignment) {
            if ($targetAssignment->trashed()) {
                $targetAssignment->restore();
            }

            return $targetAssignment->fresh();
        }

        return FacultyCourse::create([
            'faculty_id' => $facultyCourse->faculty_id,
            'course_id' => $targetCourse->id,
            'section' => $facultyCourse->section,
            'academic_year' => $facultyCourse->academic_year,
            'semester' => $facultyCourse->semester,
            'department' => $facultyCourse->department,
        ]);
    }

    private function deleteEmptyFacultyCourse(?FacultyCourse $facultyCourse): void
    {
        if (!$facultyCourse || $facultyCourse->trashed()) {
            return;
        }

        if (!$facultyCourse->schedules()->exists()) {
            $facultyCourse->delete();
        }
    }

    /**
     * Ensure stored time uses "-" instead of "to" regardless of user input.
     */
    private function normalizeTimeInput(string $time): ?string
    {
        $normalized = preg_replace('/\s*to\s*/i', ' - ', $time);
        return Schedule::normalizeOpenHourValue($normalized);
    }

    private function authorizeAdminOnly(): void
    {
        if (auth()->user()?->role !== 'Admin') {
            abort(404);
        }
    }

    private function resolveDepartmentScope($user): array
    {
        if (!$user) {
            return [];
        }

        return array_values(array_unique(array_merge(
            Faculty::normalizeDepartmentList($user->department ?? ''),
            Faculty::normalizeDepartmentList(optional($user->faculty)->department ?? '')
        )));
    }

    private function authorizeFacultyDepartmentAccess(Faculty $faculty): void
    {
        $user = auth()->user();
        if (!$user || $user->role === 'Admin') {
            return;
        }

        $departmentFilters = $this->resolveDepartmentScope($user);
        if (empty($departmentFilters)) {
            abort(404);
        }

        $facultyDepartments = array_values(array_unique(array_merge(
            Faculty::normalizeDepartmentList($faculty->department ?? ''),
            Faculty::normalizeDepartmentList(optional($faculty->user)->department ?? '')
        )));

        if (collect($departmentFilters)->intersect($facultyDepartments)->isEmpty()) {
            abort(404);
        }
    }

    private function authorizeFacultyCourseDepartmentAccess(FacultyCourse $facultyCourse): void
    {
        $facultyCourse->loadMissing('faculty.user');
        if (!$facultyCourse->faculty) {
            abort(404);
        }

        $this->authorizeFacultyDepartmentAccess($facultyCourse->faculty);
    }

    private function authorizeScheduleDepartmentAccess(Schedule $schedule): void
    {
        $schedule->loadMissing('facultyCourse.faculty.user');
        if (!$schedule->facultyCourse) {
            abort(404);
        }

        $this->authorizeFacultyCourseDepartmentAccess($schedule->facultyCourse);
    }
}
