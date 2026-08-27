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
                    ->whereNotIn('status', [
                        \App\Enums\RequestStatus::REJECTED,
                        \App\Enums\RequestStatus::COMPLETED,
                        \App\Enums\RequestStatus::CANCELLED
                    ])
                    ->where(function ($q) use ($startTime, $endTime) {
                        $q->where(function ($sub) use ($startTime, $endTime) {
                            $sub->where('start_time', '<', $endTime)
                                ->where('end_time', '>', $startTime);
                        });
                    })
                    ->pluck('id');

                $busyVehicleIds = [];

                $busyFromRequests = \App\Models\Request::whereIn('id', $overlappingRequestIds)
                    ->whereNotNull('vehicle_id')
                    ->pluck('vehicle_id')
                    ->toArray();
                $busyVehicleIds = array_merge($busyVehicleIds, $busyFromRequests);

                $busyFromTrips = \App\Models\OperationalTrip::whereIn('request_id', $overlappingRequestIds)
                    ->where('status', '!=', 'cancelled')
                    ->whereNotNull('vehicle_id')
                    ->pluck('vehicle_id')
                    ->toArray();
                $busyVehicleIds = array_merge($busyVehicleIds, $busyFromTrips);

                $busyFromItineraries = \App\Models\RequestItinerary::whereIn('request_id', $overlappingRequestIds)
                    ->whereNotNull('vehicle_id')
                    ->pluck('vehicle_id')
                    ->toArray();
                $busyVehicleIds = array_merge($busyVehicleIds, $busyFromItineraries);

                $busyVehicleIds = array_unique(array_filter($busyVehicleIds));

                if (!empty($busyVehicleIds)) {
                    $query->whereNotIn('id', $busyVehicleIds);
                }
            }
        }

        if ($status) {
            $upperStatus = strtoupper($status);
            if ($upperStatus === 'AVAILABLE') {
                $query->whereIn('status', ['Available', 'available', 'AVAILABLE', 'Tersedia']);
            } elseif (in_array($upperStatus, ['IN TRANSIT', 'IN_TRANSIT', 'IN USE', 'IN_USE', 'ON TRIP', 'ON_TRIP', 'ON_GOING'])) {
                $query->whereIn('status', ['In Use', 'in use', 'IN USE', 'In Transit', 'On Trip', 'on trip', 'ON TRIP', 'On_Going']);
            } elseif (in_array($upperStatus, ['MAINTENANCE', 'SERVIS', 'PERBAIKAN'])) {
                $query->whereIn('status', ['Maintenance', 'maintenance', 'MAINTENANCE', 'Servis', 'Perbaikan']);
            } elseif (in_array($upperStatus, ['RETIRED', 'DECOMMISSIONED', 'INACTIVE', 'TIDAK AKTIF'])) {
                $query->whereIn('status', ['Retired', 'retired', 'RETIRED', 'Decommissioned', 'decommissioned', 'DECOMMISSIONED', 'Inactive']);
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
        $user = Auth::user();
        $isAuthorized = $user && (
            $user->hasRoleDirect(['Admin', 'admin', 'GA', 'ga']) ||
            $user->isHrGaHead() ||
            ($user->isHrGaDepartment() && $user->hasRoleDirect(['Approver', 'approver']))
        );

        if (!$isAuthorized) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        try {
            $validated = $request->validated();
            if ($request->hasFile('photo')) {
                $photoPath = $this->storePublicFileSafely($request->file('photo'), 'vehicles/photos');
                if ($photoPath) {
                    $validated['photo'] = $photoPath;
                }
            }
            if ($request->hasFile('stnk_photo')) {
                $stnkPath = $this->storePublicFileSafely($request->file('stnk_photo'), 'vehicles/stnk');
                if ($stnkPath) {
                    $validated['stnk_photo'] = $stnkPath;
                }
            }

            $vehicle = Vehicle::create($validated);

            return response()->json([
                'status'  => 'success',
                'message' => 'Kendaraan berhasil ditambahkan',
                'data'    => new VehicleResource($vehicle),
            ], 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Create Vehicle Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Gagal menambahkan kendaraan: ' . $e->getMessage()], 500);
        }
    }

    public function show(Vehicle $vehicle): JsonResponse
    {
        $authUser = Auth::user();
        if (!$authUser || !$authUser->hasRoleDirect(['Admin', 'admin', 'GA', 'ga', 'HRHead', 'Approver', 'approver', 'Driver', 'driver', 'Security', 'security'])) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'status' => 'success',
            'data'   => new VehicleResource($vehicle),
        ], 200);
    }

    public function update(UpdateVehicleRequest $request, Vehicle $vehicle): JsonResponse
    {
        $user = Auth::user();
        $isAuthorized = $user && (
            $user->hasRoleDirect(['Admin', 'admin', 'GA', 'ga']) ||
            $user->isHrGaHead() ||
            ($user->isHrGaDepartment() && $user->hasRoleDirect(['Approver', 'approver']))
        );

        if (!$isAuthorized) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        try {
            $validated = $request->validated();
            if ($request->hasFile('photo')) {
                $photoPath = $this->storePublicFileSafely($request->file('photo'), 'vehicles/photos');
                if ($photoPath) {
                    $validated['photo'] = $photoPath;
                }
            }
            if ($request->hasFile('stnk_photo')) {
                $stnkPath = $this->storePublicFileSafely($request->file('stnk_photo'), 'vehicles/stnk');
                if ($stnkPath) {
                    $validated['stnk_photo'] = $stnkPath;
                }
            }

            $vehicle->update($validated);

            return response()->json([
                'status'  => 'success',
                'message' => 'Kendaraan berhasil diperbarui',
                'data'    => new VehicleResource($vehicle),
            ], 200);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Update Vehicle Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Gagal memperbarui kendaraan: ' . $e->getMessage()], 500);
        }
    }

    public function destroy(Vehicle $vehicle): JsonResponse
    {
        $user = Auth::user();
        $isAuthorized = $user && (
            $user->hasRoleDirect(['Admin', 'admin', 'GA', 'ga']) ||
            $user->isHrGaHead() ||
            ($user->isHrGaDepartment() && $user->hasRoleDirect(['Approver', 'approver']))
        );

        if (!$isAuthorized) {
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

    private function storePublicFileSafely(\Illuminate\Http\UploadedFile $file, string $folder): ?string
    {
        try {
            $originalName = $file->getClientOriginalName();
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION) ?: 'jpg');
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'])) {
                $ext = 'jpg';
            }

            $filename = time() . '_' . uniqid() . '.' . $ext;
            $relativeDir = trim($folder, '/');
            $relativePath = $relativeDir . '/' . $filename;

            $realPath = $file->getRealPath() ?: $file->getPathname();
            $fileContent = $realPath ? @file_get_contents($realPath) : null;

            // Strategy 1: Direct Storage Disk Binary Put (Bypasses finfo extension)
            if ($fileContent !== false && $fileContent !== null) {
                try {
                    $stored = \Illuminate\Support\Facades\Storage::disk('public')->put($relativePath, $fileContent);
                    if ($stored) {
                        return $relativePath;
                    }
                } catch (\Throwable $diskEx) {
                    \Illuminate\Support\Facades\Log::warning("Storage put failed: " . $diskEx->getMessage());
                }
            }

            // Strategy 2: Direct Filesystem Write
            $targetDir = storage_path('app/public/' . $relativeDir);
            if (!file_exists($targetDir)) {
                @mkdir($targetDir, 0777, true);
            }
            $targetPath = $targetDir . '/' . $filename;

            if ($fileContent !== false && $fileContent !== null && @file_put_contents($targetPath, $fileContent) !== false) {
                return $relativePath;
            }

            // Strategy 3: move_uploaded_file / copy
            if ($realPath && (@move_uploaded_file($realPath, $targetPath) || @copy($realPath, $targetPath))) {
                return $relativePath;
            }

            return null;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Vehicle File Storage Error ({$folder}): " . $e->getMessage());
            return null;
        }
    }
}