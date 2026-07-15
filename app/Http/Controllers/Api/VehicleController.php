<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVehicleRequest;
use App\Http\Requests\UpdateVehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class VehicleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->query('per_page', 15);
        $status  = $request->query('status');
        $search  = $request->query('search');

        $query = Vehicle::query();

        $excludeRequestId = $request->query('exclude_busy_for_request_id');
        if ($excludeRequestId) {
            $targetRequest = \App\Models\Request::find($excludeRequestId);
            if ($targetRequest && $targetRequest->start_time) {
                $startTime = $targetRequest->start_time;
                $endTime = $targetRequest->end_time;
                if (!$endTime) {
                    $duration = $targetRequest->estimated_duration ?: 3;
                    $endTime = (clone $startTime)->addHours($duration);
                }
                
                $overlappingRequestIds = \App\Models\Request::where('id', '!=', $targetRequest->id)
                    ->where('status', '!=', \App\Enums\RequestStatus::REJECTED)
                    ->where('status', '!=', \App\Enums\RequestStatus::COMPLETED)
                    ->where(function ($q) use ($startTime, $endTime) {
                        $q->where(function ($sub) use ($startTime, $endTime) {
                            $sub->where('start_time', '>=', $startTime)
                                ->where('start_time', '<', $endTime);
                        })
                        ->orWhere(function ($sub) use ($startTime, $endTime) {
                            $sub->where('end_time', '>', $startTime)
                                ->where('end_time', '<=', $endTime);
                        })
                        ->orWhere(function ($sub) use ($startTime, $endTime) {
                            $sub->where('start_time', '<=', $startTime)
                                ->where('end_time', '>=', $endTime);
                        });
                    })
                    ->pluck('id');

                $busyVehicleIds = [];

                $busyFromRequests = \App\Models\Request::whereIn('id', $overlappingRequestIds)
                    ->whereNotNull('vehicle_id')
                    ->pluck('vehicle_id')
                    ->toArray();
                $busyVehicleIds = array_merge($busyVehicleIds, $busyFromRequests);

                $busyFromAssignments = \App\Models\Assignment::whereIn('request_id', $overlappingRequestIds)
                    ->where('status', '!=', 'rejected')
                    ->whereNotNull('vehicle_id')
                    ->pluck('vehicle_id')
                    ->toArray();
                $busyVehicleIds = array_merge($busyVehicleIds, $busyFromAssignments);

                $busyFromTrips = \App\Models\OperationalTrip::whereIn('request_id', $overlappingRequestIds)
                    ->where('status', '!=', 'cancelled')
                    ->whereNotNull('vehicle_id')
                    ->pluck('vehicle_id')
                    ->toArray();
                $busyVehicleIds = array_merge($busyVehicleIds, $busyFromTrips);

                $busyVehicleIds = array_unique(array_filter($busyVehicleIds));

                if (!empty($busyVehicleIds)) {
                    $query->whereNotIn('id', $busyVehicleIds);
                }
            }
        }

        if ($status) {
            $upperStatus = strtoupper($status);
            if ($upperStatus === 'AVAILABLE') {
                $query->where('status', 'Available');
            } elseif ($upperStatus === 'IN TRANSIT' || $upperStatus === 'IN_TRANSIT' || $upperStatus === 'IN USE') {
                $query->where('status', 'In Use');
            } elseif ($upperStatus === 'MAINTENANCE') {
                $query->where('status', 'Maintenance');
            } elseif ($upperStatus === 'RETIRED') {
                $query->where('status', 'Retired');
            } else {
                $query->where('status', $status);
            }
        }

        if ($search) {
            $query->where('name', 'like', '%' . $search . '%')
                  ->orWhere('plate_number', 'like', '%' . $search . '%')
                  ->orWhere('type', 'like', '%' . $search . '%');
        }

        $vehicles = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data'   => VehicleResource::collection($vehicles->items()),
            'pagination' => [
                'total'        => $vehicles->total(),
                'per_page'     => $vehicles->perPage(),
                'current_page' => $vehicles->currentPage(),
                'last_page'    => $vehicles->lastPage(),
                'from'         => $vehicles->firstItem(),
                'to'           => $vehicles->lastItem(),
            ],
        ], 200);
    }

    public function store(StoreVehicleRequest $request): JsonResponse
    {
        if (!Auth::user()->hasRoleDirect('Admin') && !Auth::user()->isHrGaHead()) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $validated = $request->validated();
        if ($request->hasFile('photo')) {
            $validated['photo'] = $request->file('photo')->store('vehicles/photos', 'public');
        }

        $vehicle = Vehicle::create($validated);

        return response()->json([
            'status'  => 'success',
            'message' => 'Kendaraan berhasil ditambahkan',
            'data'    => new VehicleResource($vehicle),
        ], 201);
    }

    public function show(Vehicle $vehicle): JsonResponse
    {
        $authUser = Auth::user();
        if (!$authUser->hasRoleDirect(['Admin', 'GA', 'HRHead', 'Approver', 'Driver', 'Security'])) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'status' => 'success',
            'data'   => new VehicleResource($vehicle),
        ], 200);
    }

    public function update(UpdateVehicleRequest $request, Vehicle $vehicle): JsonResponse
    {
        if (!Auth::user()->hasRoleDirect('Admin') && !Auth::user()->isHrGaHead()) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $validated = $request->validated();
        if ($request->hasFile('photo')) {
            $validated['photo'] = $request->file('photo')->store('vehicles/photos', 'public');
        }

        $vehicle->update($validated);

        return response()->json([
            'status'  => 'success',
            'message' => 'Kendaraan berhasil diperbarui',
            'data'    => new VehicleResource($vehicle),
        ], 200);
    }

    public function destroy(Vehicle $vehicle): JsonResponse
    {
        if (!Auth::user()->hasRoleDirect('Admin') && !Auth::user()->isHrGaHead()) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        if ($vehicle->operationalTrips()->exists()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Tidak dapat menghapus kendaraan yang memiliki riwayat penugasan',
            ], 422);
        }

        $vehicle->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Kendaraan berhasil dihapus',
        ], 200);
    }
}