<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use App\Models\SubjectsEnrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SubjectController extends Controller
{
    /**
     * Admin: View all subjects (including inactive)
     */
    public function allSubjects()
    {
        $subjects = Subject::latest()->get();

        return response()->json([
            'subjects' => $subjects,
        ]);
    }

    /**
     * Create new subject (Admin)
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'banner' => 'required|image|mimes:jpg,jpeg,png|max:2048',
            'department' => 'required|in:science,commercial,art,general',
            'status' => 'required|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $bannerPath = $request->file('banner')->store('subject_banners', 'public');

            $subject = Subject::create([
                'name' => $request->name,
                'description' => $request->description,
                'banner' => $bannerPath,
                'department' => $request->department,
                'status' => $request->status,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Subject created successfully.',
                'subject' => $subject,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to create subject.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Update subject (Admin)
     */
    public function update(Request $request, $id)
    {
        $subject = Subject::find($id);

        if (!$subject) {
            return response()->json([
                'message' => 'Subject not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'banner' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            'department' => 'nullable|in:science,commercial,art,general',
            'status' => 'nullable|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $data = $validator->validated();

            if ($request->hasFile('banner')) {
                $data['banner'] = $request->file('banner')->store('subject_banners', 'public');
            }

            $subject->update($data);

            DB::commit();

            return response()->json([
                'message' => 'Subject updated successfully.',
                'subject' => $subject->fresh(),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to update subject.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Soft delete subject (Admin)
     */
    public function destroy($id)
    {
        $subject = Subject::find($id);

        if (!$subject) {
            return response()->json([
                'message' => 'Subject not found.',
            ], 404);
        }

        $subject->delete();

        return response()->json([
            'message' => 'Subject deleted successfully.',
        ]);
    }

    /**
     * Restore soft-deleted subject (Admin)
     */
    public function restore($id)
    {
        $subject = Subject::onlyTrashed()->find($id);

        if (!$subject) {
            return response()->json([
                'message' => 'Subject not found or not deleted.',
            ], 404);
        }

        $subject->restore();

        return response()->json([
            'message' => 'Subject restored successfully.',
            'subject' => $subject,
        ]);
    }

    /*
     * Public Method: List all active subjects
     */
    public function index()
    {
        $subjects = Subject::where('status', 'active')->get();

        return response()->json([
            'subjects' => $subjects,
        ]);
    }

    /*
     * Public Method: List subjects by department
     */
    public function subjectsByDepartment(string $department)
    {
        try {
            $subjects = Subject::where('status', 'active')
                ->where('department', $department)
                ->get();

            return response()->json([
                'message' => 'Subjects fetched successfully.',
                'subjects' => $subjects,
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to retrieve subjects.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
     * Public Method: List subjects by course and department
     */
    public function subjectsByCourseAndDepartment(int $courseId, string $department)
    {
        try {
            $subjects = Subject::query()
                ->where('status', 'active')
                ->whereJsonContains('courses', $courseId)
                ->whereJsonContains('departments', $department)
                ->get();

            return response()->json([
                'message' => 'Subjects fetched successfully.',
                'subjects' => $subjects,
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to retrieve subjects.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
     * Public Method: Subject enrollment
     */
    public function subjectEnroll(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'course_enrollment_id' => 'required|exists:courses_enrollments,id',
            'subject_id' => 'required|exists:subjects,id',
            'student_id' => 'required|exists:students,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Verify subject enrollment exists already for that course and that student
            $existingEnrollment = SubjectsEnrollment::where('course_enrollment', $request->course_enrollment_id)
                ->where('subject_id', $request->subject_id)
                ->where('student_id', $request->student_id)
                ->first();
            if ($existingEnrollment) {
                return response()->json([
                    'message' => 'Subject already enrolled for this course and student.',
                ], 409);
            }

            // Create subject enrollment
            SubjectsEnrollment::create([
                'course_enrollment' => $request->course_enrollment_id,
                'subject_id' => $request->subject_id,
                'student_id' => $request->student_id,
            ]);

            return response()->json([
                'message' => 'Subject enrolled successfully.',
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to enroll subject.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}









// namespace App\Http\Controllers;

// use App\Models\Subject;
// use App\Models\SubjectsEnrollment;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\DB;
// use App\Http\Controllers\Controller;
// use Illuminate\Support\Facades\Validator;

// class SubjectController extends Controller
// {
//     /**
//      * Admin: View all subjects (including inactive)
//      */
//     public function allSubjects()
//     {
//         $subjects = Subject::latest()->get();

//         return response()->json([
//             'subjects' => $subjects,
//         ]);
//     }

//     /**
//      * Create new subject (Admin)
//      */
//     public function store(Request $request)
//     {
//         $validator = Validator::make($request->all(), [
//             'name' => 'required|string|max:255',
//             'description' => 'required|string',
//             'banner' => 'required|image|mimes:jpg,jpeg,png|max:2048',

//             'courses' => 'required|array|min:1',
//             'courses.*' => 'exists:courses,id',

//             'departments' => 'required|array|min:1',
//             'departments.*' => 'string',

//             'assignees' => 'required|array|min:1',
//             'assignees.*' => 'exists:staffs,id',

//             'status' => 'required|in:active,inactive',
//         ]);

//         if ($validator->fails()) {
//             return response()->json([
//                 'errors' => $validator->errors(),
//             ], 422);
//         }

//         DB::beginTransaction();

//         try {
//             // Upload banner
//             $bannerPath = $request->file('banner')
//                 ->store('subject_banners', 'public');

//             $subject = Subject::create([
//                 'name' => $request->name,
//                 'description' => $request->description,
//                 'banner' => $bannerPath,
//                 'courses' => $request->courses,
//                 'departments' => $request->departments,
//                 'assignees' => $request->assignees,
//                 'status' => $request->status,
//             ]);

//             DB::commit();

//             return response()->json([
//                 'message' => 'Subject created successfully.',
//                 'subject' => $subject,
//             ], 201);

//         } catch (\Throwable $e) {
//             DB::rollBack();

//             return response()->json([
//                 'message' => 'Failed to create subject.',
//                 'error' => config('app.debug') ? $e->getMessage() : null,
//             ], 500);
//         }
//     }

//     /**
//      * Update subject (Admin)
//      */
//     public function update(Request $request, $id)
//     {
//         $subject = Subject::find($id);

//         if (!$subject) {
//             return response()->json([
//                 'message' => 'Subject not found.',
//             ], 404);
//         }

//         $validator = Validator::make($request->all(), [
//             'name' => 'nullable|string|max:255',
//             'description' => 'nullable|string',
//             'banner' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',

//             'courses' => 'nullable|array|min:1',
//             'courses.*' => 'exists:courses,id',

//             'departments' => 'nullable|array|min:1',
//             'departments.*' => 'string',

//             'assignees' => 'nullable|array|min:1',
//             'assignees.*' => 'exists:staffs,id',

//             'status' => 'nullable|in:active,inactive',
//         ]);

//         if ($validator->fails()) {
//             return response()->json([
//                 'errors' => $validator->errors(),
//             ], 422);
//         }

//         DB::beginTransaction();

//         try {
//             $data = $validator->validated();

//             // Handle banner update
//             if ($request->hasFile('banner')) {
//                 $data['banner'] = $request->file('banner')
//                     ->store('subject_banners', 'public');
//             }

//             $subject->update($data);

//             DB::commit();

//             return response()->json([
//                 'message' => 'Subject updated successfully.',
//                 'subject' => $subject->fresh(),
//             ]);

//         } catch (\Throwable $e) {
//             DB::rollBack();

//             return response()->json([
//                 'message' => 'Failed to update subject.',
//                 'error' => config('app.debug') ? $e->getMessage() : null,
//             ], 500);
//         }
//     }

//     /**
//      * Soft delete subject (Admin)
//      */
//     public function destroy($id)
//     {
//         $subject = Subject::find($id);

//         if (!$subject) {
//             return response()->json([
//                 'message' => 'Subject not found.',
//             ], 404);
//         }

//         $subject->delete();

//         return response()->json([
//             'message' => 'Subject deleted successfully.',
//         ]);
//     }

//     /**
//      * Restore soft-deleted subject (Admin)
//      */
//     public function restore($id)
//     {
//         $subject = Subject::onlyTrashed()->find($id);

//         if (!$subject) {
//             return response()->json([
//                 'message' => 'Subject not found or not deleted.',
//             ], 404);
//         }

//         $subject->restore();

//         return response()->json([
//             'message' => 'Subject restored successfully.',
//             'subject' => $subject,
//         ]);
//     }

//     /*
//      * Public Method: List all active subjects
//      */
//     public function index()
//     {
//         $subjects = Subject::where('status', 'active')->get();

//         return response()->json([
//             'subjects' => $subjects,
//         ]);
//     }

//     /*
//      * Public Method: List subjects by course
//      */
//     public function subjectsByCourse(int $courseId)
//     {
//         try {
//             $subjects = Subject::query()
//                 ->where('status', 'active')
//                 ->whereJsonContains('courses', $courseId)
//                 ->get();

//             return response()->json([
//                 'message' => 'Subjects fetched successfully.',
//                 'subjects' => $subjects,
//             ], 200);

//         } catch (\Throwable $e) {
//             return response()->json([
//                 'message' => 'Failed to retrieve subjects.',
//                 'error' => config('app.debug') ? $e->getMessage() : null,
//             ], 500);
//         }
//     }

//     /*
//      * Public Method: List subjects by course and department
//      */
//     public function subjectsByCourseAndDepartment(int $courseId, string $department)
//     {
//         try {
//             $subjects = Subject::query()
//                 ->where('status', 'active')
//                 ->whereJsonContains('courses', $courseId)
//                 ->whereJsonContains('departments', $department)
//                 ->get();

//             return response()->json([
//                 'message' => 'Subjects fetched successfully.',
//                 'subjects' => $subjects,
//             ], 200);

//         } catch (\Throwable $e) {
//             return response()->json([
//                 'message' => 'Failed to retrieve subjects.',
//                 'error' => config('app.debug') ? $e->getMessage() : null,
//             ], 500);
//         }
//     }

//     /*
//      * Public Method: Subject enrollment
//      */
//     public function subjectEnroll(Request $request)
//     {
//         $validator = Validator::make($request->all(), [
//             'course_enrollment_id' => 'required|exists:courses_enrollments,id',
//             'subject_id' => 'required|exists:subjects,id',
//             'student_id' => 'required|exists:students,id',
//         ]);

//         if ($validator->fails()) {
//             return response()->json([
//                 'errors' => $validator->errors(),
//             ], 422);
//         }

//         try {
//             // Verify subject enrollment exists already for that course and that student
//             $existingEnrollment = SubjectsEnrollment::where('course_enrollment', $request->course_enrollment_id)
//                 ->where('subject', $request->subject_id)
//                 ->where('student', $request->student_id)
//                 ->first();
//             if ($existingEnrollment) {
//                 return response()->json([
//                     'message' => 'Subject already enrolled for this course and student.',
//                 ], 409);
//             }

//             // Create subject enrollment
//             SubjectsEnrollment::create([
//                 'course_enrollment' => $request->course_enrollment_id,
//                 'subject' => $request->subject_id,
//                 'student' => $request->student_id,
//             ]);

//             return response()->json([
//                 'message' => 'Subject enrolled successfully.',
//             ], 201);

//         } catch (\Throwable $e) {
//             return response()->json([
//                 'message' => 'Failed to enroll subject.',
//                 'error' => config('app.debug') ? $e->getMessage() : null,
//             ], 500);
//         }
//     }
// }
