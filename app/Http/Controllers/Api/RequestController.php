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
     * Display a listing of the user's requests or all requests if user has permission
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $perPage = $request->query('per_page', 15);
        $status = $request->query('status');
        $search = $request->query('search');

        $query = VehicleRequest::with(['user', 'vehicle', 'approver']);

        // If user has permission to view all requests (Approver, GA, Admin)
        if (!$user->can('view-all-requests')) {
            // Otherwise show only user's own requests
            $query->where('user_id', $user->id);
        }

        // Filter by status if provided
        if ($status && in_array($status, ['Pending', 'Approved', 'Rejected', 'Completed', 'Cancelled'])) {
            $query->where('status', $status);
        }

        // Search by purpose if provided
        if ($search) {
            $query->where('purpose', 'like', '%' . $search . '%')
                ->orWhere('notes', 'like', '%' . $search . '%');
        }

        $requests = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => RequestResource::collection($requests->items()),
            'pagination' => [
                'total' => $requests->total(),
                'per_page' => $requests->perPage(),
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'from' => $requests->firstItem(),
                'to' => $requests->lastItem(),
            ]
        ], 200);
    }

    /**
     * Store a newly created request
     */
    public function store(StoreRequestRequest $request): JsonResponse
    {
        // Create new request
        $newRequest = VehicleRequest::create([
            'user_id' => Auth::id(),
            'purpose' => $request->validated('purpose'),
            'start_time' => $request->validated('start_time'),
            'end_time' => $request->validated('end_time'),
            'vehicle_id' => $request->validated('vehicle_id'),
            'notes' => $request->validated('notes'),
            'status' => 'Pending',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Permintaan berhasil diajukan',
            'data' => new RequestResource($newRequest)
        ], 201);
    }

    /**
     * Display the specified request
     */
    public function show(VehicleRequest $request): JsonResponse
    {
        // Check if user can view this request
        if (Auth::id() !== $request->user_id && !Auth::user()->can('view-all-requests')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized'
            ], 403);
        }

        $request->load(['user', 'vehicle', 'approver']);

        return response()->json([
            'status' => 'success',
            'data' => new RequestResource($request)
        ], 200);
    }

    /**
     * Update the specified request
     */
    public function update(UpdateRequestRequest $request, VehicleRequest $vehicleRequest): JsonResponse
    {
        // Only allow update if request is still pending
        if ($vehicleRequest->status !== 'Pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'Hanya dapat mengubah request yang masih Pending'
            ], 422);
        }

        $vehicleRequest->update($request->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Permintaan berhasil diperbarui',
            'data' => new RequestResource($vehicleRequest->fresh(['user', 'vehicle', 'approver']))
        ], 200);
    }

    /**
     * Delete the specified request
     */
    public function destroy(VehicleRequest $request): JsonResponse
    {
        // Only allow delete if request is pending
        if ($request->status !== 'Pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'Hanya dapat menghapus request yang masih Pending'
            ], 422);
        }

        $request->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Permintaan berhasil dihapus'
        ], 200);
    }

    /**
     * Approve the specified request
     */
    public function approve(VehicleRequest $request): JsonResponse
    {
        // Check authorization
        if (!Auth::user()->can('approve-request')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized'
            ], 403);
        }

        // Check if request is pending
        if ($request->status !== 'Pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'Request tidak dalam status Pending'
            ], 422);
        }

        $request->update([
            'status' => 'Approved',
            'approver_id' => Auth::id(),
            'approval_date' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Permintaan berhasil disetujui',
            'data' => new RequestResource($request->fresh(['user', 'vehicle', 'approver']))
        ], 200);
    }

    /**
     * Reject the specified request
     */
    public function reject(Request $request, VehicleRequest $vehicleRequest): JsonResponse
    {
        // Check authorization
        if (!Auth::user()->can('reject-request')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized'
            ], 403);
        }

        // Check if request is pending
        if ($vehicleRequest->status !== 'Pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'Request tidak dalam status Pending'
            ], 422);
        }

        $request->validate([
            'notes' => 'required|string|max:1000'
        ]);

        $vehicleRequest->update([
            'status' => 'Rejected',
            'approver_id' => Auth::id(),
            'approval_date' => now(),
            'notes' => $request->input('notes'),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Permintaan berhasil ditolak',
            'data' => new RequestResource($vehicleRequest->fresh(['user', 'vehicle', 'approver']))
        ], 200);
    }
}