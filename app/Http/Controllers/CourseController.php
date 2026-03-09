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
            'billing_cycle' => 'required|in:monthly,quarterly,semi_annual,annual',
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

            if (CoursesEnrollment::where('course_id', $course->id)->where('student_id', $student->id)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are already enrolled in this course.',
                ], 409);
            }

            $months = match ($request->billing_cycle) {
                'monthly' => 1,
                'quarterly' => 3,
                'semi_annual' => 6,
                'annual' => 12,
            };

            $cost = $course->price * $months;
            if ($months > 1)
                $cost *= 0.95; // 5% discount for multi-month cycles

            $enrollment = CoursesEnrollment::create([
                'course_id' => $course->id,
                'student_id' => $student->id,
                'start_date' => now(),
                'end_date' => now()->addMonths($months),
                'billing_cycle' => $request->billing_cycle,
                'cost' => $cost,
            ]);

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Enrollment successful.',
                'enrollment' => $enrollment,
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

            $enrollments = CoursesEnrollment::with([
                'course',
                'subjects.subject'
            ])
                ->where('student_id', $studentId)
                ->where('start_date', '<=', $now)
                ->where('end_date', '>=', $now)
                ->whereHas('payments', function ($q) {
                    $q->where('status', 'successful');
                })
                ->get();

            $courses = $enrollments->map(function ($enrollment) {
                return [
                    'enrollment_id' => $enrollment->id,
                    'student_id' => $enrollment->student_id,
                    'course_id' => $enrollment->course_id,
                    'start_date' => $enrollment->start_date,
                    'end_date' => $enrollment->end_date,
                    'billing_cycle' => $enrollment->billing_cycle,

                    // IMPORTANT for React
                    'course' => [
                        'id' => $enrollment->course->id ?? null,
                        'title' => $enrollment->course->title ?? null,
                        'description' => $enrollment->course->description ?? null,
                    ],

                    'subjects' => $enrollment->subjects->map(function ($sub) {
                        return [
                            'id' => optional($sub->subject)->id,
                            'name' => optional($sub->subject)->name,
                            'description' => optional($sub->subject)->description,
                            'banner' => optional($sub->subject)->banner,
                            'progress' => $sub->progress ?? 0,
                        ];
                    })->values()
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Active paid courses retrieved successfully.',
                'courses' => $courses
            ]);

        } catch (\Throwable $e) {

            // VERY IMPORTANT for debugging
            \Log::error($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve active courses.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
