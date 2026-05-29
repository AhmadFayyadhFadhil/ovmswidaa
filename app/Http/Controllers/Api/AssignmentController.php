<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssignmentRequest;
use App\Http\Requests\UpdateAssignmentRequest;
use App\Http\Resources\AssignmentResource;
use App\Models\Assignment;
use App\Models\Request as VehicleRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Actions\Assignments\AssignDriverAction;
use App\Actions\Assignments\DriverRespondAction;

class AssignmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user    = Auth::user();
        $perPage = $request->query('per_page', 15);
        $status  = $request->query('status');

        $query = Assignment::with(['request.operationalTrip.vehicle', 'driver', 'assignedBy']);

        if (!$user->hasAnyRole(['Admin', 'GA'])) {
            $query->where('driver_id', $user->id);
        }

        if ($status) {
            $query->where('status', $status);
        }

        $assignments = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'status'     => 'success',
            'data'       => AssignmentResource::collection($assignments->items()),
            'pagination' => [
                'total'        => $assignments->total(),
                'per_page'     => $assignments->perPage(),
                'current_page' => $assignments->currentPage(),
                'last_page'    => $assignments->lastPage(),
            ],
        ], 200);
    }

    public function store(StoreAssignmentRequest $request, AssignDriverAction $action): JsonResponse
    {
        $validated = $request->validated();
        $vehicleRequest = VehicleRequest::findOrFail($validated['request_id']);

        // Check if assigned driver has the Driver role
        $driver = \App\Models\User::findOrFail($validated['driver_id']);
        if (!$driver->hasRole('Driver')) {
            return response()->json(['status' => 'error', 'message' => 'User yang dipilih bukan merupakan Driver'], 422);
        }

        try {
            $assignment = $action->execute($vehicleRequest, $validated['driver_id'], $validated['notes'] ?? null);

            return response()->json([
                'status'  => 'success',
                'message' => 'Kendaraan berhasil di-assign ke driver',
                'data'    => new AssignmentResource($assignment->load(['request', 'driver', 'assignedBy'])),
            ], 201);
        } catch (\Exception $e) {
            \Log::error('Assignment creation error:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function show(Assignment $assignment): JsonResponse
    {
        if (Auth::id() !== $assignment->driver_id && !Auth::user()->hasAnyRole(['Admin', 'GA'])) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $assignment->load(['request.operationalTrip.vehicle', 'driver', 'assignedBy']);

        return response()->json([
            'status' => 'success',
            'data'   => new AssignmentResource($assignment),
        ], 200);
    }

    /**
     * Driver responds to the assignment.
     */
    public function update(UpdateAssignmentRequest $request, Assignment $assignment, DriverRespondAction $action): JsonResponse
    {
        if (Auth::id() !== $assignment->driver_id && !Auth::user()->hasRole('Admin')) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $validated = $request->validated();

        try {
            // Handle photo uploads
            $startPhotoPath = null;
            $endPhotoPath = null;
            
            if ($request->hasFile('start_photo')) {
                $startPhotoPath = $request->file('start_photo')->store('assignments/photos', 'public');
            }
            
            if ($request->hasFile('end_photo')) {
                $endPhotoPath = $request->file('end_photo')->store('assignments/photos', 'public');
            }

            $action->execute(
                $assignment,
                $validated['response'],
                $validated['vehicle_id'] ?? null,
                $validated['reject_reason'] ?? null,
                $startPhotoPath,
                $endPhotoPath
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Respon driver berhasil disimpan',
                'data'    => new AssignmentResource($assignment->fresh(['request.operationalTrip.vehicle', 'driver', 'assignedBy'])),
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function destroy(Assignment $assignment): JsonResponse
    {
        if (!Auth::user()->hasRole('Admin')) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        if ($assignment->status !== 'pending_driver') {
            return response()->json(['status' => 'error', 'message' => 'Tidak dapat menghapus assignment yang sudah diproses'], 422);
        }

        $assignment->delete();

        return response()->json(['status' => 'success', 'message' => 'Assignment berhasil dihapus'], 200);
    }
}
