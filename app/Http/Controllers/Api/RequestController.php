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
use App\Actions\Requests\CreateRequestAction;
use App\Actions\Approvals\ApproveRequestAction;
use App\Enums\RequestStatus;

class RequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user    = Auth::user();
        $perPage = $request->query('per_page', 15);
        $status  = $request->query('status');
        $search  = $request->query('search');

        $query = VehicleRequest::with(['user', 'approvals', 'operationalTrip.vehicle', 'operationalTrip.driver', 'passengers']);

        if ($user->hasRole('Approver') && !$user->hasAnyRole(['Admin', 'GA'])) {
            $query->where('department_id', $user->department_id);
        } elseif (!$user->hasAnyRole(['Admin', 'GA', 'Approver'])) {
            $query->where('user_id', $user->id);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('purpose', 'like', '%' . $search . '%')
                  ->orWhere('destination_city', 'like', '%' . $search . '%')
                  ->orWhere('destination_place', 'like', '%' . $search . '%');
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
            ],
        ], 200);
    }

    public function store(StoreRequestRequest $request, CreateRequestAction $action): JsonResponse
    {
        $newRequest = $action->execute($request->validated());

        return response()->json([
            'status'  => 'success',
            'message' => 'Permintaan berhasil diajukan',
            'data'    => new RequestResource($newRequest->load(['user', 'passengers'])),
        ], 201);
    }

    public function show(VehicleRequest $vehicleRequest): JsonResponse
    {
        $user = Auth::user();
        if ($user->hasRole('Approver') && !$user->hasAnyRole(['Admin', 'GA'])) {
            if ($vehicleRequest->department_id !== $user->department_id) {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized. Request tidak berasal dari departemen Anda.'], 403);
            }
        } elseif (Auth::id() !== $vehicleRequest->user_id && !$user->hasAnyRole(['Admin', 'GA'])) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $vehicleRequest->load(['user', 'approvals', 'operationalTrip.vehicle', 'operationalTrip.driver', 'assignments', 'passengers']);

        return response()->json([
            'status' => 'success',
            'data'   => new RequestResource($vehicleRequest),
        ], 200);
    }

    public function update(UpdateRequestRequest $request, VehicleRequest $vehicleRequest): JsonResponse
    {
        if ($vehicleRequest->status !== RequestStatus::SUBMITTED) {
            return response()->json(['status' => 'error', 'message' => 'Hanya dapat mengubah request yang baru diajukan (submitted)'], 422);
        }

        $validated = $request->validated();
        
        // Extract passengers from validated data if present
        $passengers = $validated['passengers'] ?? null;
        unset($validated['passengers']);

        // Update request
        $vehicleRequest->update($validated);

        // Update passengers if provided
        if ($passengers !== null) {
            // Delete old passengers and create new ones
            $vehicleRequest->passengers()->delete();
            foreach ($passengers as $passengerData) {
                \App\Models\Passenger::create([
                    'request_id' => $vehicleRequest->id,
                    'name' => $passengerData['name'],
                    'department_id' => $passengerData['department_id'] ?? null,
                ]);
            }
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Permintaan berhasil diperbarui',
            'data'    => new RequestResource($vehicleRequest->fresh(['user', 'passengers'])),
        ], 200);
    }

    public function destroy(VehicleRequest $vehicleRequest): JsonResponse
    {
        if ($vehicleRequest->status !== RequestStatus::SUBMITTED) {
            return response()->json(['status' => 'error', 'message' => 'Hanya dapat menghapus request yang baru diajukan (submitted)'], 422);
        }

        $vehicleRequest->delete();

        return response()->json(['status' => 'success', 'message' => 'Permintaan berhasil dihapus'], 200);
    }

    public function approve(Request $request, VehicleRequest $vehicleRequest, ApproveRequestAction $action): JsonResponse
    {
        // Check authorization using policy
        if (!Auth::user()->can('approve', $vehicleRequest)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Anda tidak berhak untuk approve request ini. Pastikan departemen Anda sama dan Anda adalah Kepala Departemen.'
            ], 403);
        }

        $request->validate([
            'role'  => 'nullable|in:dept_head,hrd_head',
            'notes' => 'nullable|string'
        ]);

        // Auto-detect role from current request status if not provided
        $role = $request->input('role') ?? match($vehicleRequest->status) {
            RequestStatus::SUBMITTED           => 'dept_head',
            RequestStatus::APPROVED_DEPARTMENT => 'hrd_head',
            default => null,
        };

        if (!$role) {
            return response()->json(['status' => 'error', 'message' => 'Request tidak dapat disetujui pada status ini'], 422);
        }

        try {
            $updatedRequest = $action->execute($vehicleRequest, $role, 'approved', $request->input('notes'));
            
            return response()->json([
                'status'  => 'success',
                'message' => 'Permintaan berhasil disetujui',
                'data'    => new RequestResource($updatedRequest->fresh(['user', 'approvals'])),
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function reject(Request $request, VehicleRequest $vehicleRequest, ApproveRequestAction $action): JsonResponse
    {
        // Check authorization using policy
        if (!Auth::user()->can('reject', $vehicleRequest)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Anda tidak berhak untuk reject request ini. Pastikan departemen Anda sama dan Anda adalah Kepala Departemen.'
            ], 403);
        }

        $request->validate([
            'role'  => 'nullable|in:dept_head,hrd_head',
            'notes' => 'required|string'
        ]);

        // Auto-detect role from current request status if not provided
        $role = $request->input('role') ?? match($vehicleRequest->status) {
            RequestStatus::SUBMITTED           => 'dept_head',
            RequestStatus::APPROVED_DEPARTMENT => 'hrd_head',
            default => null,
        };

        if (!$role) {
            return response()->json(['status' => 'error', 'message' => 'Request tidak dapat ditolak pada status ini'], 422);
        }

        try {
            $updatedRequest = $action->execute($vehicleRequest, $role, 'rejected', $request->input('notes'));
            
            return response()->json([
                'status'  => 'success',
                'message' => 'Permintaan berhasil ditolak',
                'data'    => new RequestResource($updatedRequest->fresh(['user', 'approvals'])),
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function start(VehicleRequest $vehicleRequest): JsonResponse
    {
        $user = Auth::user();

        // Authorize: Only assigned Driver, Admin, or GA can start trip
        if ($vehicleRequest->driver_id !== $user->id && !$user->hasAnyRole(['Admin', 'GA'])) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized. Hanya driver yang ditugaskan atau GA yang dapat memulai perjalanan.'], 403);
        }

        if ($vehicleRequest->status !== RequestStatus::DRIVER_ASSIGNED) {
            return response()->json(['status' => 'error', 'message' => 'Perjalanan hanya dapat dimulai jika status adalah driver_assigned.'], 422);
        }

        if (empty($vehicleRequest->driver_id) || empty($vehicleRequest->vehicle_id)) {
            return response()->json(['status' => 'error', 'message' => 'Tidak dapat memulai perjalanan tanpa driver atau kendaraan.'], 422);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($vehicleRequest) {
            // Update request
            $vehicleRequest->update([
                'status' => RequestStatus::ON_GOING,
                'started_at' => now(),
            ]);

            // Update operational trip status
            if ($vehicleRequest->operationalTrip) {
                $vehicleRequest->operationalTrip->update(['status' => 'on_going']);
            }

            // Update driver status
            if ($vehicleRequest->driver) {
                $vehicleRequest->driver->update(['availability_status' => 'on_trip']);
            }

            // Update vehicle status
            if ($vehicleRequest->vehicle) {
                $vehicleRequest->vehicle->update(['status' => 'In Use']);
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Perjalanan dimulai',
            'data' => new RequestResource($vehicleRequest->fresh(['user', 'approvals', 'operationalTrip.vehicle', 'operationalTrip.driver'])),
        ], 200);
    }

    public function complete(VehicleRequest $vehicleRequest): JsonResponse
    {
        $user = Auth::user();

        // Authorize: Only assigned Driver, Admin, or GA can complete trip
        if ($vehicleRequest->driver_id !== $user->id && !$user->hasAnyRole(['Admin', 'GA'])) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized. Hanya driver yang ditugaskan atau GA yang dapat menyelesaikan perjalanan.'], 403);
        }

        if ($vehicleRequest->status !== RequestStatus::ON_GOING) {
            return response()->json(['status' => 'error', 'message' => 'Perjalanan hanya dapat diselesaikan jika sedang berjalan (on_going).'], 422);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($vehicleRequest) {
            // Update request
            $vehicleRequest->update([
                'status' => RequestStatus::COMPLETED,
                'completed_at' => now(),
            ]);

            // Update operational trip status
            if ($vehicleRequest->operationalTrip) {
                $vehicleRequest->operationalTrip->update(['status' => 'completed']);
            }

            // Update driver status
            if ($vehicleRequest->driver) {
                $vehicleRequest->driver->update(['availability_status' => 'available']);
            }

            // Update vehicle status
            if ($vehicleRequest->vehicle) {
                $vehicleRequest->vehicle->update(['status' => 'Available']);
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Perjalanan selesai',
            'data' => new RequestResource($vehicleRequest->fresh(['user', 'approvals', 'operationalTrip.vehicle', 'operationalTrip.driver'])),
        ], 200);
    }
}