<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\msauth\MicrosoftOAuthController;
use App\Http\Controllers\dashboard\DashboardController;
use App\Http\Controllers\dashboard\ReportsController;
use App\Http\Controllers\data_management\FacultyController;
use App\Http\Controllers\data_management\CourseController;
use App\Http\Controllers\data_management\ScheduleController;
use App\Http\Controllers\data_management\EvaluationController;
use App\Http\Controllers\pages\AccountSettingsAccount;
use App\Http\Controllers\user_management\UserController as UserManagementController;
use App\Http\Controllers\user_management\AuditLogsController;
use App\Http\Controllers\organization\DepartmentOrgChartController;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Landing Page
Route::get('/', function () {
    if (Auth::check()) {
        return strtolower((string) Auth::user()?->role) === 'student'
            ? redirect()->route('student.evaluation-access')
            : redirect()->route('dashboard');
    }
    return view('auth.login');
})->name('login');

// Microsoft OAuth Routes
Route::get('/auth/microsoft/redirect', [MicrosoftOAuthController::class, 'redirect'])
    ->name('microsoft.redirect');
Route::get('/auth/microsoft/callback', [MicrosoftOAuthController::class, 'callback'])
    ->name('microsoft.callback');

Route::get('/maintenance', function () {
    if (!Cache::get('maintenance.enabled', false)) {
        return Auth::check()
            ? redirect()->route(strtolower((string) Auth::user()?->role) === 'student' ? 'student.evaluation-access' : 'dashboard')
            : redirect()->route('login');
    }

    return response()->view('content.pages.pages-misc-under-maintenance', [], 503);
})->name('maintenance.page');

Route::get('/maintenance/status', function () {
    $user = Auth::user();

    return response()->json([
        'enabled' => Cache::get('maintenance.enabled', false),
        'is_admin' => $user && $user->role === 'Admin',
        'redirect_url' => route('maintenance.page'),
    ]);
})->name('maintenance.status');

Route::middleware(['auth', 'access.level:forms'])->group(function () {
    Route::get('/evaluation/{faculty}/{year}/{semester}/{token}', [EvaluationController::class, 'showForm'])->name('evaluation.form');
    Route::get('/eval/{token}', [EvaluationController::class, 'showForm'])->name('evaluation.form.short');
    Route::post('/evaluation/{token}/submit', [EvaluationController::class, 'submitResponse'])->name('evaluation.submit');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/student/evaluation-access', function () {
        return view('content.student.evaluation-access');
    })->name('student.evaluation-access')->middleware('access.level:forms');

    Route::get('/profile', [AccountSettingsAccount::class, 'profile'])->name('profile');

    // Dashboard Routes
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard')->middleware('access.level:dashboard');
    
    // Reports Module Routes
    Route::get('/reports', [ReportsController::class, 'index'])->name('reports')->middleware('access.level:reports');
    Route::get('/reports/lazy-sections', [ReportsController::class, 'getLazySections'])->name('reports.lazy.sections')->middleware('access.level:reports');
    Route::get('/reports/metric-details', [ReportsController::class, 'getMetricDetails'])->name('reports.metric.details')->middleware('access.level:reports');
    Route::get('/reports/department-faculties', [ReportsController::class, 'getDepartmentFaculties'])->name('reports.department.faculties')->middleware('access.level:reports');
    Route::get('/reports/department-faculties/export', [ReportsController::class, 'exportDepartmentFaculties'])->name('reports.department.export')->middleware('access.level:reports');
    Route::get('/department-org-chart', [DepartmentOrgChartController::class, 'index'])->name('department-org-chart')->middleware('access.level:reports');
    Route::post('/department-org-chart/clean-departments', [DepartmentOrgChartController::class, 'cleanDepartments'])->name('department-org-chart.clean')->middleware('admin');
    
    // Faculty Management Routes
    Route::get('/data-management/faculties', [FacultyController::class, 'index'])->name('dm.faculties')->middleware('access.level:faculties');
    Route::get('/data-management/faculties/list', [FacultyController::class, 'list'])->name('dm.faculties.list')->middleware('access.level:faculties');
    Route::get('/data-management/faculties/deleted', [FacultyController::class, 'deletedList'])->name('dm.faculties.deleted')->middleware('access.level:faculties');
    Route::post('/data-management/faculties/restore/{id}', [FacultyController::class, 'restore'])->name('dm.faculties.restore')->middleware('access.level:faculties');
    Route::post('/faculties/search-employee', [FacultyController::class, 'searchEmployeeNo'])->name('faculties.search-employee')->middleware('access.level:faculties');
    Route::post('/faculties/import', [FacultyController::class, 'import'])->name('faculties.import')->middleware('access.level:faculties');
    Route::post('/faculties/convert-import-template', [FacultyController::class, 'convertImportTemplate'])->name('faculties.convert-import-template')->middleware('access.level:faculties');
    Route::post('/faculties/import-all', [FacultyController::class, 'importAll'])->name('faculties.import-all')->middleware('access.level:faculties');
    Route::post('/data-management/faculties', [FacultyController::class, 'store'])->name('dm.faculties.store')->middleware('access.level:faculties');
    Route::put('/data-management/faculties/{faculty}', [FacultyController::class, 'update'])->name('dm.faculties.update')->middleware('access.level:faculties');
    Route::delete('/data-management/faculties/{faculty}', [FacultyController::class, 'destroy'])->name('dm.faculties.destroy')->middleware('access.level:faculties');
    Route::post('/data-management/faculties/bulk-delete', [FacultyController::class, 'bulkDestroy'])->name('dm.faculties.bulk-destroy')->middleware('access.level:faculties');

    // Courses
    Route::get('/data-management/courses', [CourseController::class, 'index'])->name('dm.courses')->middleware('access.level:courses');
    
    Route::get('/data-management/minor-courses', [CourseController::class, 'minorSubject'])->name('dm.minor.courses')->middleware('access.level:courses');
    Route::get('/data-management/minor-courses/list', [CourseController::class, 'minorCoursesList'])->name('dm.minor.courses.list')->middleware('access.level:courses');
    Route::get('/data-management/minor-courses/{id}/handlers', [CourseController::class, 'minorCourseHandlers'])->name('dm.minor.courses.handlers')->middleware('access.level:courses');
    Route::get('/data-management/minor-courses-assign/list', [CourseController::class, 'minorAssignmentsList'])->name('dm.minor.courses.assign.list')->middleware('access.level:courses');
    Route::get('/data-management/minor-courses/show/{id}', [CourseController::class, 'showMinorCourse'])->middleware('access.level:courses');
    Route::get('/data-management/minor-assign-courses/show/{id}', [CourseController::class, 'showMinorAssignment'])->middleware('access.level:courses');
    Route::post('/data-management/save-update-minors/{id}', [CourseController::class, 'SaveUpdateMinorCourse'])->middleware('access.level:courses');
    Route::post('/data-management/save-update-minor-assign/{id}', [CourseController::class, 'saveUpdateMinorAssign'])->middleware('access.level:courses');
    Route::put('/data-management/delete-minors-course', [CourseController::class, 'deletMinorCourse'])->middleware('access.level:courses');
    Route::put('/data-management/delete-assign-minors-course', [CourseController::class, 'deletAssignMinorCourse'])->middleware('access.level:courses');
    Route::post('/data-management/add-minor-course/', [CourseController::class, 'saveAddMinorCourse'])->middleware('access.level:courses');
     Route::post('/data-management/save-add-minor-assign/', [CourseController::class, 'SaveAddMinorCourseAssign'])->middleware('access.level:courses');
    
    
    Route::post('/data-management/courses/import', [CourseController::class, 'import'])->name('dm.courses.import')->middleware('access.level:courses');
    Route::post('/data-management/courses', [CourseController::class, 'storeCourse'])->name('dm.courses.store')->middleware('access.level:courses');
    Route::get('/data-management/courses/deleted/{type?}', [CourseController::class, 'deletedCourses'])->name('dm.courses.deleted')->middleware('admin');
    Route::post('/data-management/courses/restore/{id}', [CourseController::class, 'restoreCourse'])->name('dm.courses.restore')->middleware('admin');
    Route::put('/data-management/courses/{course}', [CourseController::class, 'updateCourse'])->name('dm.courses.update')->middleware('access.level:courses');
    Route::delete('/data-management/courses/{course}', [CourseController::class, 'destroyCourse'])->name('dm.courses.destroy')->middleware('access.level:courses');
    Route::post('/data-management/courses/bulk-delete', [CourseController::class, 'bulkDestroyCourses'])->name('dm.courses.bulk-destroy')->middleware('access.level:courses');
    Route::post('/data-management/faculty-courses', [CourseController::class, 'storeFacultyCourse'])->name('dm.faculty-courses.store')->middleware('access.level:courses');
    Route::get('/data-management/faculty-courses/deleted/{type?}', [CourseController::class, 'deletedFacultyCourses'])->name('dm.faculty-courses.deleted')->middleware('admin');
    Route::post('/data-management/faculty-courses/restore/{id}', [CourseController::class, 'restoreFacultyCourse'])->name('dm.faculty-courses.restore')->middleware('admin');
    Route::put('/data-management/faculty-courses/{facultyCourse}', [CourseController::class, 'updateFacultyCourse'])->name('dm.faculty-courses.update')->middleware('access.level:courses');
    Route::delete('/data-management/faculty-courses/{facultyCourse}', [CourseController::class, 'destroyFacultyCourse'])->name('dm.faculty-courses.destroy')->middleware('access.level:courses');
    Route::post('/data-management/faculty-courses/bulk-delete', [CourseController::class, 'bulkDestroyFacultyCourses'])->name('dm.faculty-courses.bulk-destroy')->middleware('access.level:courses');

    // Schedules
    Route::get('/data-management/schedules', [ScheduleController::class, 'index'])->name('dm.schedules')->middleware('access.level:schedules');
    Route::post('/data-management/schedules/import', [ScheduleController::class, 'import'])->name('dm.schedules.import')->middleware('access.level:schedules');
    Route::post('/data-management/schedules', [ScheduleController::class, 'store'])->name('dm.schedules.store')->middleware('access.level:schedules');
    Route::get('/data-management/schedules/deleted', [ScheduleController::class, 'deletedList'])->name('dm.schedules.deleted')->middleware('admin');
    Route::post('/data-management/schedules/restore/{id}', [ScheduleController::class, 'restore'])->name('dm.schedules.restore')->middleware('admin');
    Route::delete('/data-management/schedules/force-delete/{id}', [ScheduleController::class, 'forceDelete'])->name('dm.schedules.force-delete')->middleware('admin');
    Route::put('/data-management/schedules/{schedule}', [ScheduleController::class, 'update'])->name('dm.schedules.update')->middleware('access.level:schedules');
    Route::delete('/data-management/schedules/{schedule}', [ScheduleController::class, 'destroy'])->name('dm.schedules.destroy')->middleware('access.level:schedules');
    Route::post('/data-management/schedules/bulk-delete', [ScheduleController::class, 'bulkDestroy'])->name('dm.schedules.bulk-destroy')->middleware('access.level:schedules');

    // Evaluations
    Route::get('/data-management/evaluation', [EvaluationController::class, 'index'])->name('dm.evaluation')->middleware('access.level:evaluations');
    Route::post('/data-management/evaluation', [EvaluationController::class, 'store'])->name('dm.evaluation.store')->middleware('access.level:evaluations.manage');
    Route::post('/data-management/evaluation/generate-all', [EvaluationController::class, 'generateAll'])->name('dm.evaluation.generateAll')->middleware('access.level:evaluations.manage');
    Route::get('/data-management/evaluation/deleted', [EvaluationController::class, 'deletedList'])->name('dm.evaluation.deleted')->middleware('admin');
    Route::post('/data-management/evaluation/restore/{id}', [EvaluationController::class, 'restore'])->name('dm.evaluation.restore')->middleware('admin');
    Route::patch('/data-management/evaluation/{evaluation}/toggle', [EvaluationController::class, 'toggleStatus'])->name('dm.evaluation.toggle')->middleware('access.level:evaluations.manage');
    Route::delete('/data-management/evaluation/{evaluation}', [EvaluationController::class, 'destroy'])->name('dm.evaluation.destroy')->middleware('access.level:evaluations.manage');
    Route::delete('/data-management/evaluation', [EvaluationController::class, 'bulkDestroy'])->name('dm.evaluation.bulkDestroy')->middleware('access.level:evaluations.manage');
    Route::get('/data-management/evaluation/{evaluation}/faculty-profile', [EvaluationController::class, 'facultyProfile'])->name('dm.evaluation.faculty-profile')->middleware('access.level:evaluations');
    Route::get('/data-management/evaluation/{evaluation}/responses', [EvaluationController::class, 'viewResponses'])->name('dm.evaluation.responses')->middleware('access.level:responses');
    Route::get('/data-management/evaluation/{evaluation}/responses/export', [EvaluationController::class, 'exportResponses'])->name('dm.evaluation.responses.export')->middleware('access.level:responses');
    Route::get('/data-management/evaluation/qr-links/export', [EvaluationController::class, 'exportQrLinks'])->name('dm.evaluation.qr-links.export')->middleware('access.level:evaluations.qr');
    Route::get('/data-management/evaluation/qr-codes/export-zip', [EvaluationController::class, 'exportQrCodesZip'])->name('dm.evaluation.qr-codes.export-zip')->middleware('access.level:evaluations.qr');
    Route::get('/data-management/evaluation/{evaluation}/qr', [EvaluationController::class, 'showQrCode'])->name('dm.evaluation.qr')->middleware('access.level:evaluations.qr');
    Route::get('/data-management/evaluation/{evaluation}/qr/download', [EvaluationController::class, 'downloadQrCode'])->name('dm.evaluation.qr.download')->middleware('access.level:evaluations.qr');
    Route::get('/data-management/evaluation/{evaluation}/qr-poster', [EvaluationController::class, 'qrPoster'])->name('dm.evaluation.qr.poster')->middleware('access.level:evaluations.qr');
    Route::get('/data-management/evaluation/convert-urls', [EvaluationController::class, 'convertToShortUrls'])->name('dm.evaluation.convert')->middleware('access.level:evaluations.manage');

    // User Management
    Route::get('/user-management/users', [UserManagementController::class, 'index'])->name('um.users')->middleware('admin');
    Route::get('/user-management/users/list', [UserManagementController::class, 'list'])->name('um.users.list')->middleware('admin');
    Route::get('/user-management/users/deleted', [UserManagementController::class, 'deletedList'])->name('um.users.deleted')->middleware('admin');
    Route::post('/user-management/users/restore/{id}', [UserManagementController::class, 'restore'])->name('um.users.restore')->middleware('admin');
    Route::post('/user-management/users', [UserManagementController::class, 'store'])->name('um.users.store')->middleware('admin');
    Route::post('/user-management/users/bulk-access', [UserManagementController::class, 'bulkAccess'])->name('um.users.bulk-access')->middleware('admin');
    Route::put('/user-management/users/{user}', [UserManagementController::class, 'update'])->name('um.users.update')->middleware('admin');
    Route::delete('/user-management/users/{user}', [UserManagementController::class, 'destroy'])->name('um.users.destroy')->middleware('admin');
    Route::post('/user-management/users/bulk-delete', [UserManagementController::class, 'bulkDestroy'])->name('um.users.bulk-destroy')->middleware('admin');

    // Audit Logs Route
    Route::get('/audit-logs/audit-logs', [AuditLogsController::class, 'index'])->name('um.audit-logs')->middleware('admin');

    // Settings
    Route::get('/settings', [AccountSettingsAccount::class, 'index'])->name('settings');
    Route::post('/settings/avatar', [AccountSettingsAccount::class, 'updateAvatar'])->name('settings.avatar.update');
    Route::delete('/settings/avatar', [AccountSettingsAccount::class, 'removeAvatar'])->name('settings.avatar.remove');
    Route::post('/settings/maintenance', [AccountSettingsAccount::class, 'updateMaintenance'])->name('settings.maintenance')->middleware('admin');

    // Session keep-alive for users actively working on long forms
    Route::post('/session/keep-alive', function () {
        return response()->noContent();
    })->name('session.keep-alive');

    // Logout
    Route::post('/logout', function () {
        AuditLogger::log('logout', [
            'module' => 'Security',
            'description' => 'User logged out.',
            'severity' => 'info',
        ]);

        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();
        return redirect('/');
    })->name('logout');
});
