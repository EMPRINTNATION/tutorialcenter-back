<?php

namespace App\Http\Controllers;

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
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'subject_id' => 'required|exists:subjects,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive',
            'staffs' => 'nullable|array',
            'staffs.*.staff_id' => 'required|exists:staffs,id',
            'staffs.*.role' => 'nullable|string|max:255',
            'schedules' => 'nullable|array',
            'schedules.*.day_of_week' => 'required|in:sunday,monday,tuesday,wednesday,thursday,friday,saturday',
            'schedules.*.start_time' => 'required|date_format:H:i',
            'schedules.*.end_time' => 'required|date_format:H:i',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            // 1️⃣ Create class
            $class = Classes::create($validator->validated());

            // 2️⃣ Attach staffs
            if ($request->filled('staffs')) {
                $staffData = collect($request->staffs)->mapWithKeys(fn($s) => [
                    $s['staff_id'] => ['role' => $s['role'] ?? null]
                ])->toArray();
                $class->staffs()->attach($staffData);
            }

            // 3️⃣ Create schedules
            if ($request->filled('schedules')) {
                foreach ($request->schedules as $schedule) {
                    $class->schedules()->create([
                        'day_of_week' => $schedule['day_of_week'],
                        'start_time' => $schedule['start_time'],
                        'end_time' => $schedule['end_time'],
                    ]);
                }
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Class created successfully',
                'data' => $class->load('staffs', 'schedules'),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create class',
                'error' => config('app.debug') ? $e->getMessage() : null,
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
}
