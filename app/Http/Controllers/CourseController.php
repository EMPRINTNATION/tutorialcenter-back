<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Student;
use App\Models\CoursesEnrollment;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CourseController extends Controller
{
    /**
     * ADMIN: Create new course
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255|unique:courses,title',
            'description' => 'required|string',
            'banner' => 'required|image|mimes:jpg,jpeg,png|max:2048',
            'status' => 'required|in:active,inactive',
            'price' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $slug = Str::slug($request->title);
            if (Course::where('slug', $slug)->exists()) {
                $slug .= '-' . Str::random(6);
            }

            $bannerPath = $request->file('banner')->store('course_banners', 'public');

            $course = Course::create([
                'title' => $request->title,
                'slug' => $slug,
                'description' => $request->description,
                'banner' => $bannerPath,
                'status' => $request->status,
                'price' => $request->price,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Course created successfully.',
                'data' => $course,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Course creation failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * ADMIN: Update course
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $course = Course::find($id);
        if (!$course) {
            return response()->json([
                'success' => false,
                'message' => 'Course not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255|unique:courses,title,' . $course->id,
            'description' => 'nullable|string',
            'banner' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            'status' => 'nullable|in:active,inactive',
            'price' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $data = $validator->validated();

            if (isset($data['title']) && $data['title'] !== $course->title) {
                $slug = Str::slug($data['title']);
                if (Course::where('slug', $slug)->where('id', '!=', $course->id)->exists()) {
                    $slug .= '-' . Str::random(6);
                }
                $data['slug'] = $slug;
            }

            if ($request->hasFile('banner')) {
                $data['banner'] = $request->file('banner')->store('course_banners', 'public');
            }

            $course->update($data);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Course updated successfully.',
                'data' => $course->fresh(),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Course update failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * ADMIN: Soft delete course
     */
    public function destroy(int $id): JsonResponse
    {
        $course = Course::find($id);
        if (!$course) {
            return response()->json([
                'success' => false,
                'message' => 'Course not found.',
            ], 404);
        }

        try {
            $course->delete();
            return response()->json([
                'success' => true,
                'message' => 'Course deleted successfully.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete course.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * ADMIN: Restore soft-deleted course
     */
    public function restore(int $id): JsonResponse
    {
        $course = Course::onlyTrashed()->find($id);
        if (!$course) {
            return response()->json([
                'success' => false,
                'message' => 'Course not found or not deleted.',
            ], 404);
        }

        try {
            $course->restore();
            return response()->json([
                'success' => true,
                'message' => 'Course restored successfully.',
                'data' => $course,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Course restoration failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Public: Fetch all active courses
     */
    public function index(): JsonResponse
    {
        try {
            $courses = Course::where('status', 'active')->get();
            return response()->json([
                'success' => true,
                'courses' => $courses,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch courses.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * STUDENT: Enroll in a course
     */
    public function courseEnroll(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required|exists:students,id',
            'course_id' => 'required|exists:courses,id',
            'billing_cycle' => 'required|in:monthly,quarterly,semi-annual,annual',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $student = Student::find($request->student_id);
            if (is_null($student->email_verified_at) && is_null($student->tel_verified_at)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please verify your email or phone number before enrolling.',
                ], 403);
            }

            $course = Course::where('id', $request->course_id)->where('status', 'active')->first();
            if (!$course) {
                return response()->json([
                    'success' => false,
                    'message' => 'Course is not available for enrollment.',
                ], 404);
            }

            if (CoursesEnrollment::where('course', $course->id)->where('student', $student->id)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are already enrolled in this course.',
                ], 409);
            }

            $months = match ($request->billing_cycle) {
                'monthly' => 1,
                'quarterly' => 3,
                'semi-annual' => 6,
                'annual' => 12,
            };

            $cost = $course->price * $months;
            if ($months > 1) $cost *= 0.95; // 5% discount for multi-month cycles

            $enrollment = CoursesEnrollment::create([
                'course' => $course->id,
                'student' => $student->id,
                'start_date' => now(),
                'end_date' => now()->addMonths($months),
                'billing_cycle' => $request->billing_cycle,
                'cost' => $cost,
            ]);

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Enrollment successful.',
                'data' => $enrollment,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Enrollment failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Fetch active courses with enrolled subjects
     */
    public function getActiveCourses(Request $request): JsonResponse
    {
        try {
            $studentId = $request->user()->id;
            $now = now();

            $enrollments = CoursesEnrollment::with(['course', 'subjects.subject'])
                ->where('student', $studentId)
                ->where('start_date', '<=', $now)
                ->where('end_date', '>=', $now)
                ->whereHas('payments', fn($q) => $q->successful())
                ->get();

            $mergedCourses = $enrollments->map(fn($enrollment) => [
                'enrollment_id' => $enrollment->id,
                'student' => $enrollment->student,
                'start_date' => $enrollment->start_date,
                'end_date' => $enrollment->end_date,
                'billing_cycle' => $enrollment->billing_cycle,
                'course' => $enrollment->course,
                'subjects' => $enrollment->subjects->map(fn($sub) => [
                    'id' => $sub->subject->id,
                    'name' => $sub->subject->name,
                    'description' => $sub->subject->description,
                    'banner' => $sub->subject->banner,
                    'progress' => $sub->progress,
                ]),
            ])->values();

            return response()->json([
                'success' => true,
                'message' => 'Active paid courses retrieved successfully.',
                'data' => $mergedCourses,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve active courses.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}

// namespace App\Http\Controllers;

// use App\Models\Course;
// use App\Models\Student;
// use Illuminate\Support\Str;
// use Illuminate\Http\Request;
// use App\Models\CoursesEnrollment;
// use Illuminate\Http\JsonResponse;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Validator;


// class CourseController extends Controller
// {
//     /**
//      * ADMIN: Create new course
//      */
//     public function store(Request $request)
//     {
//         // 1. Validate input
//         $validator = Validator::make($request->all(), [
//             'title' => 'required|string|max:255|unique:courses,title',
//             'description' => 'required|string',
//             'banner' => 'required|image|mimes:jpg,jpeg,png|max:2048',
//             'status' => 'required|in:active,inactive',
//             'price' => 'required|numeric|min:0',
//         ]);

//         if ($validator->fails()) {
//             return response()->json([
//                 'errors' => $validator->errors(),
//             ], 422);
//         }

//         DB::beginTransaction();

//         try {
//             // 2. Generate unique slug
//             $slug = Str::slug($request->title);

//             if (Course::where('slug', $slug)->exists()) {
//                 $slug .= '-' . Str::random(6);
//             }

//             // 3. Upload banner
//             $bannerPath = $request->file('banner')
//                 ->store('course_banners', 'public');

//             // 4. Create course
//             $course = Course::create([
//                 'title' => $request->title,
//                 'slug' => $slug,
//                 'description' => $request->description,
//                 'banner' => $bannerPath,
//                 'status' => $request->status,
//                 'price' => $request->price,
//             ]);

//             DB::commit();

//             return response()->json([
//                 'message' => 'Course created successfully.',
//                 'course' => $course,
//             ], 201);

//         } catch (\Throwable $e) {
//             DB::rollBack();

//             return response()->json([
//                 'message' => 'Course creation failed.',
//                 'error' => config('app.debug') ? $e->getMessage() : null,
//             ], 500);
//         }
//     }

//     /**
//      * ADMIN: Update course
//      */
//     public function update(Request $request, $id)
//     {
//         $course = Course::find($id);

//         if (!$course) {
//             return response()->json([
//                 'message' => 'Course not found.',
//             ], 404);
//         }

//         // 1. Validate input
//         $validator = Validator::make($request->all(), [
//             'title' => 'nullable|string|max:255|unique:courses,title,' . $course->id,
//             'description' => 'nullable|string',
//             'banner' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
//             'status' => 'nullable|in:active,inactive',
//             'price' => 'nullable|numeric|min:0',
//         ]);

//         if ($validator->fails()) {
//             return response()->json([
//                 'errors' => $validator->errors(),
//             ], 422);
//         }

//         DB::beginTransaction();

//         try {
//             $data = $validator->validated();

//             // 2. Handle title & slug update
//             if (isset($data['title']) && $data['title'] !== $course->title) {
//                 $slug = Str::slug($data['title']);

//                 if (Course::where('slug', $slug)->where('id', '!=', $course->id)->exists()) {
//                     $slug .= '-' . Str::random(6);
//                 }

//                 $data['slug'] = $slug;
//             }

//             // 3. Handle banner update
//             if ($request->hasFile('banner')) {
//                 $data['banner'] = $request->file('banner')
//                     ->store('course_banners', 'public');
//             }

//             // 4. Update course
//             $course->update($data);

//             DB::commit();

//             return response()->json([
//                 'message' => 'Course updated successfully.',
//                 'course' => $course->fresh(),
//             ]);

//         } catch (\Throwable $e) {
//             DB::rollBack();

//             return response()->json([
//                 'message' => 'Course update failed.',
//                 'error' => config('app.debug') ? $e->getMessage() : null,
//             ], 500);
//         }
//     }

//     /**
//      * ADMIN: Delete course (soft delete)
//      */
//     public function destroy($id)
//     {
//         $course = Course::find($id);

//         if (!$course) {
//             return response()->json([
//                 'message' => 'Course not found.',
//             ], 404);
//         }

//         try {
//             $course->delete();

//             return response()->json([
//                 'message' => 'Course deleted successfully.',
//             ]);

//         } catch (\Throwable $e) {
//             return response()->json([
//                 'message' => 'Failed to delete course.',
//                 'error' => config('app.debug') ? $e->getMessage() : null,
//             ], 500);
//         }
//     }

//     /**
//      * ADMIN: Restore soft-deleted course
//      */
//     public function restore(int $id): JsonResponse
//     {
//         // Only admins should reach here (middleware or policy)
//         $course = Course::onlyTrashed()->find($id);

//         if (!$course) {
//             return response()->json([
//                 'message' => 'Course not found or not deleted.',
//             ], 404);
//         }

//         try {
//             $course->restore();

//             return response()->json([
//                 'message' => 'Course restored successfully.',
//                 'course' => $course,
//             ], 200);

//         } catch (\Throwable $e) {
//             return response()->json([
//                 'message' => 'Course restoration failed.',
//                 'error' => config('app.debug') ? $e->getMessage() : null,
//             ], 500);
//         }
//     }

//     /**
//      * Public: Fetch active courses
//      */
//     public function index()
//     {
//         try {
//             $courses = Course::where('status', 'active')->get();
//             return response()->json([
//                 'courses' => $courses,
//             ], 200);
//         } catch (\Throwable $e) {
//             return response()->json([
//                 'message' => 'Failed to fetch courses.',
//                 'error' => config('app.debug') ? $e->getMessage() : null,
//             ], 500);
//         }
//     }

//     /**
//      * STUDENT: Enroll in a course
//      */
//     public function courseEnroll(Request $request)
//     {
//         // 1. Validate request
//         $validator = Validator::make($request->all(), [
//             'student_id' => 'required|exists:students,id',
//             'course_id' => 'required|exists:courses,id',
//             'billing_cycle' => 'required|in:monthly,quarterly,semi-annual,annual',
//         ]);

//         if ($validator->fails()) {
//             return response()->json([
//                 'errors' => $validator->errors(),
//             ], 422);
//         }

//         DB::beginTransaction();

//         try {
//             // 2. Fetch student
//             $student = Student::find($request->student_id);

//             // 3. Ensure student is verified
//             if (is_null($student->email_verified_at) && is_null($student->tel_verified_at)) {
//                 return response()->json([
//                     'message' => 'Please verify your email or phone number before enrolling.',
//                 ], 403);
//             }

//             // 4. Fetch active course
//             $course = Course::where('id', $request->course_id)
//                 ->where('status', 'active')
//                 ->first();

//             if (!$course) {
//                 return response()->json([
//                     'message' => 'Course is not available for enrollment.',
//                 ], 404);
//             }

//             // 5. Prevent duplicate enrollment
//             $alreadyEnrolled = CoursesEnrollment::where('course', $course->id)
//                 ->where('student', $student->id)
//                 ->exists();

//             if ($alreadyEnrolled) {
//                 return response()->json([
//                     'message' => 'You are already enrolled in this course.',
//                 ], 409);
//             }

//             // 6. Billing logic
//             $startDate = now();

//             $endDate = match ($request->billing_cycle) {
//                 'monthly' => now()->addMonth(),
//                 'quarterly' => now()->addMonths(3),
//                 'semi-annual' => now()->addMonths(6),
//                 'annual' => now()->addYear(),
//             };

//             $cost = $course->price; // monthly base price
//             if ($request->billing_cycle === 'quarterly') {
//                 $cost = ($cost * 3) - (($cost * 3) * 0.05); // 5% discount
//             } elseif ($request->billing_cycle === 'semi-annual') {
//                 $cost = ($cost * 6) - (($cost * 6) * 0.05); // 5% discount
//             } elseif ($request->billing_cycle === 'annual') {
//                 $cost = ($cost * 12) - (($cost * 12) * 0.05); // 5% discount
//             }

//             // 7. Create enrollment
//             $enrollment = CoursesEnrollment::create([
//                 'course' => $course->id,
//                 'student' => $student->id,
//                 'start_date' => $startDate,
//                 'end_date' => $endDate,
//                 'billing_cycle' => $request->billing_cycle,
//                 'cost' => $cost,
//             ]);

//             DB::commit();

//             return response()->json([
//                 'message' => 'Enrollment successful.',
//                 'enrollment' => $enrollment,
//             ], 201);

//         } catch (\Throwable $e) {
//             DB::rollBack();

//             return response()->json([
//                 'message' => 'Enrollment failed.',
//                 'error' => config('app.debug') ? $e->getMessage() : null,
//             ], 500);
//         }
//     }

//     /**
//      * Fetch active courses by merging enrollment + course table data
//      */
//     public function getActiveCourses(Request $request)
//     {
//         try {
//             $studentId = $request->user()->id;
//             $now = now();

//             // Step 1: Get active enrollments with successful payments
//             $enrollments = CoursesEnrollment::query()
//                 ->where('student', $studentId)
//                 ->where('start_date', '<=', $now)
//                 ->where('end_date', '>=', $now)
//                 ->whereHas('payments', function ($query) {
//                     $query->successful();
//                 })
//                 ->get();

//             // Step 2: Extract course IDs from enrollments
//             $courseIds = $enrollments->pluck('course')->unique()->values();

//             // Step 3: Fetch actual courses from courses table
//             $courses = Course::whereIn('id', $courseIds)->get()->keyBy('id');

//             // Step 4: Merge enrollment + course data
//             $mergedCourses = $enrollments->map(function ($enrollment) use ($courses) {

//                 $course = $courses->get($enrollment->course);

//                 // Fetch subjects under this enrollment
//                 $subjects = DB::table('subjects_enrollments')
//                     ->join('subjects', 'subjects_enrollments.subject', '=', 'subjects.id')
//                     ->where('subjects_enrollments.course_enrollment', $enrollment->id)
//                     ->whereNull('subjects_enrollments.deleted_at')
//                     ->select(
//                         'subjects.id',
//                         'subjects.name',
//                         'subjects.description',
//                         'subjects.banner',
//                         'subjects_enrollments.progress'
//                     )
//                     ->get();

//                 return [
//                     'enrollment_id' => $enrollment->id,
//                     'student' => $enrollment->student,
//                     'start_date' => $enrollment->start_date,
//                     'end_date' => $enrollment->end_date,
//                     'billing_cycle' => $enrollment->billing_cycle,

//                     // Course table data merged in
//                     'course' => $course,

//                     // Attached subjects
//                     'subjects' => $subjects,
//                 ];
//             })->values();

//             return response()->json([
//                 'success' => true,
//                 'message' => 'Active paid courses retrieved successfully',
//                 'courses' => $mergedCourses
//             ], 200);

//         } catch (\Exception $error) {
//             return response()->json([
//                 'success' => false,
//                 'message' => $error->getMessage(),
//                 'errors' => $error,
//             ], 500);
//         }
//     }

// }
