<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssignmentRequest;
use App\Http\Requests\UpdateAssignmentRequest;
use App\Http\Resources\AssignmentResource;
use App\Models\Assignment;
use App\Models\Request as VehicleRequest;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AssignmentController extends Controller
{
    /**
     * Display a listing of assignments with pagination and filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $user    = Auth::user();
        $perPage = $request->query('per_page', 15);
        $status  = $request->query('status');

        $query = Assignment::with(['request', 'vehicle', 'driver']);

        if (!$user->hasAnyRole(['Admin', 'GA'])) {
            $query->where('driver_id', $user->id);
        }

        if ($status && in_array($status, ['Active', 'Completed', 'Cancelled'])) {
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
                'from'         => $assignments->firstItem(),
                'to'           => $assignments->lastItem(),
            ],
        ], 200);
    }

    /**
     * Store a newly created assignment.
     * FIX: vehicle_id pada request dipakai sebagai sumber kebenaran,
     * bukan vehicle_id dari VehicleRequest. Kalau vehicle_id berbeda, tolak.
     */
    public function store(StoreAssignmentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $vehicleRequest = VehicleRequest::findOrFail($validated['request_id']);

        if ($vehicleRequest->status !== 'Approved') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Request harus dalam status Approved untuk di-assign',
            ], 422);
        }

        // FIX: vehicle yang di-assign harus sama dengan yang di-request (jika request punya vehicle_id)
        if ($vehicleRequest->vehicle_id && $vehicleRequest->vehicle_id !== (int) $validated['vehicle_id']) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Kendaraan yang di-assign tidak sesuai dengan yang direquest',
            ], 422);
        }

        $vehicle = Vehicle::findOrFail($validated['vehicle_id']);

        if ($vehicle->status !== 'Available') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Kendaraan tidak tersedia (status: ' . $vehicle->status . ')',
            ], 422);
        }

        // Validate that driver_id is actually a Driver - check role directly via database
        $driver = \App\Models\User::findOrFail($validated['driver_id']);
        $hasDriverRole = $driver->roles()->where('name', 'Driver')->exists();
        if (!$hasDriverRole) {
            return response()->json([
                'message' => 'User yang dipilih bukan merupakan Driver'
            ], 422);
        }

        $assignment = Assignment::create([
            'request_id'  => $validated['request_id'],
            'vehicle_id'  => $validated['vehicle_id'],
            'driver_id'   => $validated['driver_id'],
            'assigned_at' => $validated['assigned_at'],
            'notes'       => $validated['notes'] ?? null,
            'status'      => 'Active',
        ]);

        $vehicle->update(['status' => 'In Use']);
        $vehicleRequest->update(['status' => 'Completed']);

        return response()->json([
            'status'  => 'success',
            'message' => 'Kendaraan berhasil di-assign',
            'data'    => new AssignmentResource($assignment->load(['request', 'vehicle', 'driver'])),
        ], 201);
    }

    /**
     * Display the specified assignment.
     */
    public function show(Assignment $assignment): JsonResponse
    {
        if (Auth::id() !== $assignment->driver_id && !Auth::user()->hasAnyRole(['Admin', 'GA'])) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized',
            ], 403);
        }

        $assignment->load(['request', 'vehicle', 'driver']);

        return response()->json([
            'status' => 'success',
            'data'   => new AssignmentResource($assignment),
        ], 200);
    }

    /**
     * Update assignment (return vehicle).
     */
    public function update(UpdateAssignmentRequest $request, Assignment $assignment): JsonResponse
    {
        if ($assignment->status !== 'Active') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Assignment tidak dalam status Active',
            ], 422);
        }

        $validated = $request->validated();

        $assignment->update([
            'returned_at' => $validated['returned_at'],
            'notes'       => $validated['notes'] ?? $assignment->notes,
            'status'      => 'Completed',
        ]);

        $assignment->vehicle->update(['status' => 'Available']);

        return response()->json([
            'status'  => 'success',
            'message' => 'Kendaraan berhasil dikembalikan',
            'data'    => new AssignmentResource($assignment->fresh(['request', 'vehicle', 'driver'])),
        ], 200);
    }

    /**
     * Remove the specified assignment (Admin only, non-Active).
     */
    public function destroy(Assignment $assignment): JsonResponse
    {
        if (!Auth::user()->hasRole('Admin')) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized',
            ], 403);
        }

        if ($assignment->status === 'Active') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Tidak dapat menghapus assignment yang masih Active',
            ], 422);
        }

        $assignment->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Assignment berhasil dihapus',
        ], 200);
    }

    /**
     * Cancel an active assignment.
     */
    public function cancel(Assignment $assignment): JsonResponse
    {
        if (!Auth::user()->hasAnyRole(['Admin', 'GA'])) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized',
            ], 403);
        }

        if ($assignment->status !== 'Active') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Assignment tidak dalam status Active',
            ], 422);
        }

        $assignment->update(['status' => 'Cancelled']);

        if (!$assignment->returned_at) {
            $assignment->vehicle->update(['status' => 'Available']);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Assignment berhasil dibatalkan',
            'data'    => new AssignmentResource($assignment->fresh(['request', 'vehicle', 'driver'])),
        ], 200);
    }
}
