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

class AssignmentController extends Controller
{
    /**
     * Display a listing of assignments with pagination and filtering
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $perPage = $request->query('per_page', 15);
        $status = $request->query('status');

        $query = Assignment::with(['request', 'vehicle', 'driver']);

        // GA and Admin can view all assignments
        if (!$user->hasAnyRole(['Admin', 'GA'])) {
            // Drivers see only their own assignments
            $query->where('driver_id', $user->id);
        }

        // Filter by status if provided
        if ($status && in_array($status, ['Active', 'Completed', 'Cancelled'])) {
            $query->where('status', $status);
        }

        $assignments = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => AssignmentResource::collection($assignments->items()),
            'pagination' => [
                'total' => $assignments->total(),
                'per_page' => $assignments->perPage(),
                'current_page' => $assignments->currentPage(),
                'last_page' => $assignments->lastPage(),
                'from' => $assignments->firstItem(),
                'to' => $assignments->lastItem(),
            ]
        ], 200);
    }

    /**
     * Store a newly created assignment
     */
    public function store(StoreAssignmentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Verify driver role
        $driver = \App\Models\User::find($validated['driver_id']);
        if (!$driver || !$driver->hasRole('Driver')) {
            return response()->json([
                'status' => 'error',
                'message' => 'User yang dipilih bukan merupakan Driver'
            ], 422);
        }

        // Check if request is approved
        $vehicleRequest = VehicleRequest::find($validated['request_id']);
        if ($vehicleRequest->status !== 'Approved') {
            return response()->json([
                'status' => 'error',
                'message' => 'Request harus dalam status Approved untuk di-assign'
            ], 422);
        }

        // Check vehicle is available
        $vehicle = $vehicleRequest->vehicle;
        if (!$vehicle || $vehicle->status !== 'Available') {
            return response()->json([
                'status' => 'error',
                'message' => 'Kendaraan tidak tersedia'
            ], 422);
        }

        // Create assignment
        $assignment = Assignment::create([
            'request_id' => $validated['request_id'],
            'vehicle_id' => $validated['vehicle_id'],
            'driver_id' => $validated['driver_id'],
            'assigned_at' => $validated['assigned_at'],
            'notes' => $validated['notes'] ?? null,
            'status' => 'Active',
        ]);

        // Update vehicle status to "In Use"
        $vehicle->update(['status' => 'In Use']);

        // Update request status to "Completed"
        $vehicleRequest->update(['status' => 'Completed']);

        return response()->json([
            'status' => 'success',
            'message' => 'Kendaraan berhasil di-assign',
            'data' => new AssignmentResource($assignment->load(['request', 'vehicle', 'driver']))
        ], 201);
    }

    /**
     * Display the specified assignment
     */
    public function show(Assignment $assignment): JsonResponse
    {
        // Check authorization
        if (Auth::id() !== $assignment->driver_id && !Auth::user()->hasAnyRole(['Admin', 'GA'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized'
            ], 403);
        }

        $assignment->load(['request', 'vehicle', 'driver']);

        return response()->json([
            'status' => 'success',
            'data' => new AssignmentResource($assignment)
        ], 200);
    }

    /**
     * Update the specified assignment (return vehicle)
     */
    public function update(UpdateAssignmentRequest $request, Assignment $assignment): JsonResponse
    {
        // Only allow return if active
        if ($assignment->status !== 'Active') {
            return response()->json([
                'status' => 'error',
                'message' => 'Assignment tidak dalam status Active'
            ], 422);
        }

        $validated = $request->validated();

        // Update assignment
        $assignment->update([
            'returned_at' => $validated['returned_at'],
            'notes' => $validated['notes'] ?? $assignment->notes,
            'status' => 'Completed',
        ]);

        // Update vehicle status back to "Available"
        $assignment->vehicle->update(['status' => 'Available']);

        return response()->json([
            'status' => 'success',
            'message' => 'Kendaraan berhasil dikembalikan',
            'data' => new AssignmentResource($assignment->fresh(['request', 'vehicle', 'driver']))
        ], 200);
    }

    /**
     * Remove the specified assignment
     */
    public function destroy(Assignment $assignment): JsonResponse
    {
        // Check authorization (only Admin)
        if (!Auth::user()->hasRole('Admin')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized'
            ], 403);
        }

        // Only allow delete if cancelled
        if ($assignment->status === 'Active') {
            return response()->json([
                'status' => 'error',
                'message' => 'Tidak dapat menghapus assignment yang masih Active'
            ], 422);
        }

        $assignment->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Assignment berhasil dihapus'
        ], 200);
    }

    /**
     * Cancel an active assignment
     */
    public function cancel(Assignment $assignment): JsonResponse
    {
        // Check authorization
        if (!Auth::user()->hasAnyRole(['Admin', 'GA'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized'
            ], 403);
        }

        // Only allow cancel if active
        if ($assignment->status !== 'Active') {
            return response()->json([
                'status' => 'error',
                'message' => 'Assignment tidak dalam status Active'
            ], 422);
        }

        // Update assignment
        $assignment->update(['status' => 'Cancelled']);

        // Update vehicle status back to "Available" if not returned
        if (!$assignment->returned_at) {
            $assignment->vehicle->update(['status' => 'Available']);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Assignment berhasil dibatalkan',
            'data' => new AssignmentResource($assignment->fresh(['request', 'vehicle', 'driver']))
        ], 200);
    }
}
