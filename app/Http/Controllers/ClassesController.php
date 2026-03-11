<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Holiday;
use App\Models\Subject;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Validator;
use App\Models\Classes;
use App\Models\ClassStaff;
use App\Models\ClassSchedule;
use App\Models\SubjectsEnrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;

class ClassesController extends Controller
{
    /**
     * Display paginated classes
     */
    public function index(Request $request): JsonResponse
    {
        $classes = Classes::with(['staffs', 'schedules', 'sessions'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data' => $classes,
        ]);
    }

    /**
     * Create a new class
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [

            'subject_id' => 'required|exists:subjects,id',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive',

            'staffs' => 'nullable|array',
            'staffs.*.staff_id' => 'required_with:staffs|exists:staffs,id',
            'staffs.*.role' => 'nullable|string|max:100',

            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',

            'schedules' => 'required|array|min:1',

            'schedules.*.day_of_week' => 'required|string|in:sunday,monday,tuesday,wednesday,thursday,friday,saturday',
            'schedules.*.start_time' => 'required|date_format:H:i',
            'schedules.*.duration_minutes' => 'required|integer|min:1',

        ]);

        if ($validator->fails()) {

            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | 1. Create Class
            |--------------------------------------------------------------------------
            */
            if (empty($request->title)) {

                $subject = Subject::find($request->subject_id);

                $course = $subject ? Course::find($subject->course_id[0] ?? null) : null;

                $title = 'Untitled Class';

                if ($subject && $course) {
                    $title = $course->title . ' ' . $subject->name . ' Class';
                } elseif ($subject) {
                    $title = $subject->name . ' Class';
                }

                $request->merge([
                    'title' => $title
                ]);
            }
            $class = Classes::create([
                'subject_id' => $request->subject_id,
                'title' => $request->title,
                'description' => $request->description,
                'status' => $request->status
            ]);

            /*
            |--------------------------------------------------------------------------
            | 2. Assign Staff
            |--------------------------------------------------------------------------
            */

            if ($request->has('staffs')) {

                $staffData = [];

                foreach ($request->staffs as $staff) {

                    $staffData[$staff['staff_id']] = [
                        'role' => $staff['role'] ?? null
                    ];
                }

                $class->staffs()->sync($staffData);
            }

            /*
            |--------------------------------------------------------------------------
            | 3. Create Schedules + Generate Sessions
            |--------------------------------------------------------------------------
            */

            $sessionsCreated = 0;

            foreach ($request->schedules as $scheduleData) {

                $endTime = Carbon::createFromFormat('H:i', $scheduleData['start_time'])
                    ->addMinutes($scheduleData['duration_minutes'])
                    ->format('H:i');

                $schedule = ClassSchedule::create([
                    'class_id' => $class->id,
                    'day_of_week' => $scheduleData['day_of_week'],
                    'start_time' => $scheduleData['start_time'],
                    'end_time' => $endTime,
                    'start_date' => $request->start_date,
                    'end_date' => $request->end_date
                ]);

                /*
                |--------------------------------------------------------------------------
                | Generate Sessions
                |--------------------------------------------------------------------------
                */

                $current = Carbon::parse($request->start_date);

                $current->next($scheduleData['day_of_week']);

                while ($current->lte($request->end_date)) {

                    $isHoliday = Holiday::whereDate('holiday_date', $current)->exists();

                    if (!$isHoliday) {

                        ClassSession::create([
                            'class_id' => $class->id,
                            'class_schedule_id' => $schedule->id,
                            'session_date' => $current->toDateString(),
                            'starts_at' => $scheduleData['start_time'],
                            'ends_at' => $endTime,
                            'class_link' => "https://meet.google.com/" . Str::random(10),
                            'status' => 'scheduled'
                        ]);

                        $sessionsCreated++;
                    }

                    $current->addWeek();
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Class created successfully',
                'sessions_created' => $sessionsCreated,
                'class' => $class->load([
                    'subject',
                    'staffs',
                    'schedules',
                    'sessions'
                ])
            ], 201);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'message' => 'Class creation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show a specific class
     */
    public function show(int $id): JsonResponse
    {
        $class = Classes::with(['staffs', 'schedules', 'sessions'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $class,
        ]);
    }

    /**
     * Update class
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $class = Classes::findOrFail($id);

        $validated = $request->validate([
            'subject_id' => 'sometimes|exists:subjects,id',
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'status' => 'sometimes|in:active,inactive',
            'staffs' => 'nullable|array',
            'staffs.*.staff_id' => 'required|exists:staffs,id',
            'staffs.*.role' => 'nullable|string|max:255',
        ]);

        DB::beginTransaction();
        try {
            $class->update($validated);

            // Sync staffs
            if (!empty($validated['staffs'])) {
                $staffData = collect($validated['staffs'])->mapWithKeys(fn($s) => [
                    $s['staff_id'] => ['role' => $s['role'] ?? null]
                ])->toArray();
                $class->staffs()->sync($staffData);
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Class updated successfully',
                'data' => $class->load('staffs', 'schedules'),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update class',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Soft delete class
     */
    public function destroy(int $id): JsonResponse
    {
        $class = Classes::findOrFail($id);
        $class->delete();

        return response()->json([
            'success' => true,
            'message' => 'Class deleted successfully',
        ]);
    }

    /**
     * Restore soft-deleted class
     */
    public function restore(int $id): JsonResponse
    {
        $class = Classes::withTrashed()->findOrFail($id);
        $class->restore();

        return response()->json([
            'success' => true,
            'message' => 'Class restored successfully',
        ]);
    }

    /**
     * Permanently delete class
     */
    public function forceDelete(int $id): JsonResponse
    {
        $class = Classes::withTrashed()->findOrFail($id);
        $class->forceDelete();

        return response()->json([
            'success' => true,
            'message' => 'Class permanently deleted',
        ]);
    }

    /**
     * Update class status
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $request->validate(['status' => 'required|in:active,inactive']);

        $class = Classes::findOrFail($id);
        $class->update(['status' => $request->status]);

        return response()->json([
            'success' => true,
            'message' => 'Status updated successfully',
            'data' => $class,
        ]);
    }

    /**
     * Attach staff
     */
    public function attachStaff(Request $request, int $id): JsonResponse
    {
        $class = Classes::findOrFail($id);

        $validated = $request->validate([
            'staff_id' => 'required|exists:staffs,id',
            'role' => 'nullable|string|max:255',
        ]);

        $class->staffs()->attach($validated['staff_id'], ['role' => $validated['role'] ?? null]);

        return response()->json([
            'success' => true,
            'message' => 'Staff attached successfully',
        ]);
    }

    /**
     * Detach staff
     */
    public function detachStaff(int $classId, int $staffId): JsonResponse
    {
        $class = Classes::findOrFail($classId);
        $class->staffs()->detach($staffId);

        return response()->json([
            'success' => true,
            'message' => 'Staff detached successfully',
        ]);
    }

    /**
     * Student schedule: enrolled classes + upcoming sessions
     */
    public function studentSchedule(Request $request): JsonResponse
    {
        $studentId = $request->user()->id;

        $enrollments = SubjectsEnrollment::with([
            'subject.classes.staffs',
            'subject.classes.schedules.sessions' => fn($q) => $q
                ->whereDate('session_date', '>=', now())
                ->orderBy('session_date')
                ->orderBy('starts_at')
        ])->where('student', $studentId)->get();

        $data = $enrollments->map(fn($enrollment) => $enrollment->subject ? [
            'subject' => $enrollment->subject->name,
            'classes' => $enrollment->subject->classes->map(fn($class) => [
                'class_id' => $class->id,
                'title' => $class->title,
                'description' => $class->description,
                'status' => $class->status,
                'tutors' => $class->staffs->map(fn($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'role' => $s->pivot->role,
                ]),
                'weekly_schedule' => $class->schedules->map(fn($schedule) => [
                    'day_of_week' => $schedule->day_of_week,
                    'start_time' => $schedule->start_time,
                    'end_time' => $schedule->end_time,
                ]),
                'upcoming_sessions' => $class->schedules->flatMap(fn($s) => $s->sessions)->values(),
            ])
        ] : null)->filter()->values();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Create schedule and generate sessions for a class
     */
    public function createScheduleAndSessions(Request $request)
    {
        try {
            $validated = $request->validate([
                'class_id' => 'required|exists:classes,id',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after:start_date',
                'schedules' => 'required|array',

                'schedules.*.day_of_week' => 'required|integer|min:0|max:6',
                'schedules.*.start_time' => 'required|date_format:H:i',
                'schedules.*.duration_minutes' => 'required|integer|min:1'
            ]);

            DB::beginTransaction();

            $sessionsCreated = 0;

            foreach ($validated['schedules'] as $scheduleData) {

                $endTime = Carbon::createFromFormat('H:i', $scheduleData['start_time'])
                    ->addMinutes($scheduleData['duration_minutes'])
                    ->format('H:i');

                $schedule = ClassSchedule::create([
                    'class_id' => $validated['class_id'],
                    'day_of_week' => $scheduleData['day_of_week'],
                    'start_time' => $scheduleData['start_time'],
                    'end_time' => $endTime,
                    'start_date' => $validated['start_date'],
                    'end_date' => $validated['end_date'],
                ]);

                $current = Carbon::parse($validated['start_date']);

                $current->next($scheduleData['day_of_week']);

                while ($current->lte($validated['end_date'])) {

                    $isHoliday = Holiday::whereDate('holiday_date', $current)->exists();

                    if (!$isHoliday) {

                        ClassSession::create([
                            'class_id' => $validated['class_id'],
                            'class_schedule_id' => $schedule->id,
                            'session_date' => $current->toDateString(),
                            'starts_at' => $scheduleData['start_time'],
                            'ends_at' => $endTime,
                            'status' => 'scheduled'
                        ]);

                        $sessionsCreated++;
                    }

                    $current->addWeek();
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Schedule and sessions created successfully',
                'sessions_created' => $sessionsCreated
            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'message' => 'Failed to create schedule',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reschedule a specific session
     */
    public function rescheduleSession(Request $request, $sessionId)
    {
        $validated = $request->validate([
            'session_date' => 'required|date',
            'starts_at' => 'required',
            'ends_at' => 'required'
        ]);

        $session = ClassSession::findOrFail($sessionId);

        $session->update($validated);

        return response()->json([
            'message' => 'Session rescheduled successfully'
        ]);
    }
}
