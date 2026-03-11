<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\ClassesController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\GuardianController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/
Route::get('/courses', [CourseController::class, 'index']); // Public: List all active courses
Route::get('/subjects', [SubjectController::class, 'index']); // Public: List all active subjects
Route::post('/course/enrollment', [CourseController::class, 'courseEnroll']); // Public: Enroll in a course
Route::post('/subject/enrollment', [SubjectController::class, 'subjectEnroll']); // Public: Enroll in a subject
Route::get('/courses/{courseId}/subjects', [SubjectController::class, 'subjectsByCourse']); // Public: List subjects by course
Route::get('/courses/{courseId}/subjects/{department}', [SubjectController::class, 'subjectsByCourseAndDepartment']); // Public: List subjects by course and department
Route::post('payments', [PaymentController::class, 'store']); // Public: Process payment

/*
|--------------------------------------------------------------------------
| Student Public Routes
|--------------------------------------------------------------------------
*/
Route::prefix('students')->group(function () {
    Route::post('/register', [StudentController::class, 'store']); // Registration
    Route::post('/verify-email', [StudentController::class, 'verifyEmail']); // Email verification
    Route::post('/resend-email-verification', [StudentController::class, 'resendEmailVerification']); // Resend Email Verification
    Route::post('/verify-phone', [StudentController::class, 'verifyPhoneOtp']); // Phone OTP verification
    Route::post('/resend-phone-otp', [StudentController::class, 'resendPhoneOtp']); // Resend Phone Verification
    Route::post('/biodata', [StudentController::class, 'biodata']); // Biodata completion (NO AUTH REQUIRED, but verification enforced)
    Route::post('/enroll-course', [CourseController::class, 'courseEnroll']); // Course enrollment
    Route::post('/login', [StudentController::class, 'login']); // Login Method
});

/*
|--------------------------------------------------------------------------
| Student Protected Routes
|--------------------------------------------------------------------------
*/
Route::prefix('students')->middleware(['auth:sanctum', 'auth:student'])->group(function () {
    Route::get('/courses', [CourseController::class, 'getActiveCourses']); // Get Active Courses and Subject 
    Route::get('/payments', [PaymentController::class, 'myPayments']); // Listing out all payments
    Route::get('/schedule', [ClassesController::class, 'studentSchedule']); // Get student schedule (classes and sessions)
});

/*
|--------------------------------------------------------------------------
| Staff Registration & Verification
|--------------------------------------------------------------------------
*/
Route::prefix('staffs')->group(function () {
    // Login (restricted until verified)
    Route::post('/login', [StaffController::class, 'login']);

    // // Registration (Admin only — enforced in controller)
    // Route::post('/register', [StaffController::class, 'store']);

    // Email verification
    Route::get('/verify-email', [StaffController::class, 'verifyEmail']);
    Route::post('/resend-email-verification', [StaffController::class, 'resendEmailVerification']);

    // Phone OTP verification
    Route::post('/verify-phone', [StaffController::class, 'verifyPhoneOtp']);
    Route::post('/resend-phone-otp', [StaffController::class, 'resendPhoneOtp']);

    Route::middleware('auth:staff')->group(function () {
        // Logout
        Route::post('/logout', [StaffController::class, 'logout']);
    });

});

/*
|--------------------------------------------------------------------------
| Guardian Registration & Verification
|--------------------------------------------------------------------------
*/
Route::prefix('guardians')->group(function () {
    Route::post('/register', [GuardianController::class, 'store']);
    Route::post('/verify-email', [GuardianController::class, 'verifyEmail']);

    Route::post('/verify-phone', [GuardianController::class, 'verifyPhoneOtp']);
    Route::post('/resend-phone-otp', [GuardianController::class, 'resendPhoneOtp']);

    Route::post('/resend-email', [GuardianController::class, 'resendEmailVerification']);
});

/*
 * Admin Only Protected Routes (enforced in controller)
 */
Route::prefix('admin')->middleware(['auth:sanctum', 'auth:staff', 'staff.role:admin'])->group(function () {
    // Registration 
    Route::post('/register', [StaffController::class, 'store']);

    // Course Management
    Route::post('/courses', [CourseController::class, 'store']);
    Route::put('/courses/update/{id}', [CourseController::class, 'update']);
    Route::delete('/courses/destroy/{id}', [CourseController::class, 'destroy']);
    Route::post('/courses/restore/{id}', [CourseController::class, 'restore']);

    // Subject Management
    Route::get('/subjects/all', [SubjectController::class, 'allSubjects']); // View all subjects (including inactive)
    Route::post('/subjects', [SubjectController::class, 'store']); // Create new subject
    Route::put('/subjects/update/{id}', [SubjectController::class, 'update']); // Update subject
    Route::delete('/subjects/destroy/{id}', [SubjectController::class, 'destroy']); // Soft delete subject
    Route::post('/subjects/restore/{id}', [SubjectController::class, 'restore']); // Restore soft-deleted subject

    // Classes Management
    Route::prefix('classes')->group(function () {
        // Create class schedule and sessions
        Route::post('/schedule/sessions', [ClassesController::class, 'createScheduleAndSessions']);

        // Get all classes
        Route::get('/', [ClassesController::class, 'index']);

        // Create class
        Route::post('/create', [ClassesController::class, 'store']);

        // Get single class
        Route::get('/{id}', [ClassesController::class, 'show']);

        // Update class
        Route::put('/{id}', [ClassesController::class, 'update']);
        Route::patch('/{id}', [ClassesController::class, 'update']);

        // Soft delete
        Route::delete('/{id}', [ClassesController::class, 'destroy']);

        // Restore soft deleted class
        Route::post('/{id}/restore', [ClassesController::class, 'restore']);

        // Permanently delete
        Route::delete('/{id}/force-delete', [ClassesController::class, 'forceDelete']);

        // Update status
        Route::patch('/{id}/status', [ClassesController::class, 'updateStatus']);

        // Attach staff
        Route::post('/{id}/staff', [ClassesController::class, 'attachStaff']);

        // Detach staff
        Route::delete('/{classId}/staff/{staffId}', [ClassesController::class, 'detachStaff']);
    });

});




