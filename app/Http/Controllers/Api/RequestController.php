<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRequest as StoreRequestRequest;
use App\Http\Requests\UpdateRequestRequest;
use App\Http\Resources\RequestResource;
use App\Models\Request as VehicleRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class RequestController extends Controller
{
    /**
     * Display a listing of requests with filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $user    = Auth::user();
        $perPage = $request->query('per_page', 15);
        $status  = $request->query('status');
        $search  = $request->query('search');

        $query = VehicleRequest::with(['user', 'vehicle', 'approver']);

        // Only privileged roles see all requests; others see only their own
        if (!$user->hasAnyRole(['Admin', 'GA', 'Approver'])) {
            $query->where('user_id', $user->id);
        }

        // Filter by status
        if ($status && in_array($status, ['Pending', 'Approved', 'Rejected', 'Completed', 'Cancelled'])) {
            $query->where('status', $status);
        }

        // FIX: wrap orWhere inside a closure so it doesn't break the user_id filter
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('purpose', 'like', '%' . $search . '%')
                  ->orWhere('notes', 'like', '%' . $search . '%');
            });
        }

        $requests = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'status'     => 'success',
            'data'       => RequestResource::collection($requests->items()),
            'pagination' => [
                'total'        => $requests->total(),
                'per_page'     => $requests->perPage(),
                'current_page' => $requests->currentPage(),
                'last_page'    => $requests->lastPage(),
                'from'         => $requests->firstItem(),
                'to'           => $requests->lastItem(),
            ],
        ], 200);
    }

    /**
     * Store a newly created request.
     */
    public function store(StoreRequestRequest $request): JsonResponse
    {
        $newRequest = VehicleRequest::create([
            'user_id'    => Auth::id(),
            'purpose'    => $request->validated('purpose'),
            'start_time' => $request->validated('start_time'),
            'end_time'   => $request->validated('end_time'),
            'vehicle_id' => $request->validated('vehicle_id'),
            'notes'      => $request->validated('notes'),
            'status'     => 'Pending',
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Permintaan berhasil diajukan',
            'data'    => new RequestResource($newRequest->load(['user', 'vehicle', 'approver'])),
        ], 201);
    }

    /**
     * Display the specified request.
     */
    public function show(VehicleRequest $vehicleRequest): JsonResponse
    {
        if (Auth::id() !== $vehicleRequest->user_id && !Auth::user()->hasAnyRole(['Admin', 'GA', 'Approver'])) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized',
            ], 403);
        }

        $vehicleRequest->load(['user', 'vehicle', 'approver']);

        return response()->json([
            'status' => 'success',
            'data'   => new RequestResource($vehicleRequest),
        ], 200);
    }

    /**
     * Update the specified request (only if Pending).
     */
    public function update(UpdateRequestRequest $request, VehicleRequest $vehicleRequest): JsonResponse
    {
        if ($vehicleRequest->status !== 'Pending') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Hanya dapat mengubah request yang masih Pending',
            ], 422);
        }

        $vehicleRequest->update($request->validated());

        return response()->json([
            'status'  => 'success',
            'message' => 'Permintaan berhasil diperbarui',
            'data'    => new RequestResource($vehicleRequest->fresh(['user', 'vehicle', 'approver'])),
        ], 200);
    }

    /**
     * Delete the specified request (only if Pending).
     */
    public function destroy(VehicleRequest $vehicleRequest): JsonResponse
    {
        if (Auth::id() !== $vehicleRequest->user_id && !Auth::user()->hasRole('Admin')) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized',
            ], 403);
        }

        if ($vehicleRequest->status !== 'Pending') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Hanya dapat menghapus request yang masih Pending',
            ], 422);
        }

        $vehicleRequest->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Permintaan berhasil dihapus',
        ], 200);
    }

    /**
     * Approve the specified request.
     */
    public function approve(VehicleRequest $vehicleRequest): JsonResponse
    {
        // Only Admin and Approver roles can approve
        if (!Auth::user()->hasAnyRole(['Admin', 'Approver'])) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized',
            ], 403);
        }

        if ($vehicleRequest->status !== 'Pending') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Request tidak dalam status Pending',
            ], 422);
        }

        $vehicleRequest->update([
            'status'        => 'Approved',
            'approver_id'   => Auth::id(),
            'approval_date' => now(),
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Permintaan berhasil disetujui',
            'data'    => new RequestResource($vehicleRequest->fresh(['user', 'vehicle', 'approver'])),
        ], 200);
    }

    /**
     * Reject the specified request.
     */
    public function reject(Request $request, VehicleRequest $vehicleRequest): JsonResponse
    {
        if (!Auth::user()->hasAnyRole(['Admin', 'Approver'])) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized',
            ], 403);
        }

        if ($vehicleRequest->status !== 'Pending') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Request tidak dalam status Pending',
            ], 422);
        }

        $request->validate([
            'notes' => 'required|string|max:1000',
        ]);

        $vehicleRequest->update([
            'status'        => 'Rejected',
            'approver_id'   => Auth::id(),
            'approval_date' => now(),
            'notes'         => $request->input('notes'),
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Permintaan berhasil ditolak',
            'data'    => new RequestResource($vehicleRequest->fresh(['user', 'vehicle', 'approver'])),
        ], 200);
    }
}