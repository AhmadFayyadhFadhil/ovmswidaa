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
use App\Enums\RequestStatus;

class VehicleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Self-healing: Rekonsiliasi ketersediaan armada berdasarkan status gerbang riil
        $this->reconcileVehiclesAvailability();

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
                        RequestStatus::REJECTED->value,
                        RequestStatus::COMPLETED->value,
                        RequestStatus::CANCELLED->value,
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

    /**
     * Rekonsiliasi otomatis ketersediaan armada.
     * Jika kendaraan ditandai 'In Use' namun secara riil sudah kembali masuk gerbang,
     * statusnya otomatis dipulihkan menjadi 'Available' dan odometernya disinkronkan.
     */
    protected function reconcileVehiclesAvailability(): void
    {
        try {
            // 1. Dapatkan ID kendaraan yang BENAR-BENAR sedang berada di luar pabrik (checkout tanpa checkin)
            $activeSplitDepVehicles = \App\Models\Request::where('trip_mode', 'split_drop_pickup')
                ->whereNotNull('departure_checked_out_at')
                ->whereNull('departure_checked_in_at')
                ->whereNotIn('status', [RequestStatus::REJECTED->value, RequestStatus::COMPLETED->value, RequestStatus::CANCELLED->value])
                ->whereNotNull('vehicle_id')
                ->pluck('vehicle_id')
                ->toArray();

            $activeSplitRetVehicles = \App\Models\Request::where('trip_mode', 'split_drop_pickup')
                ->whereNotNull('return_checked_out_at')
                ->whereNull('return_checked_in_at')
                ->whereNotIn('status', [RequestStatus::REJECTED->value, RequestStatus::COMPLETED->value, RequestStatus::CANCELLED->value])
                ->whereNotNull('return_vehicle_id')
                ->pluck('return_vehicle_id')
                ->toArray();

            $activeSingleVehicles = \App\Models\Request::where(function ($q) {
                    $q->whereNull('trip_mode')->orWhere('trip_mode', '!=', 'split_drop_pickup');
                })
                ->whereNotNull('security_checked_out_at')
                ->whereNull('security_checked_in_at')
                ->whereNotIn('status', [RequestStatus::REJECTED->value, RequestStatus::COMPLETED->value, RequestStatus::CANCELLED->value])
                ->whereNotNull('vehicle_id')
                ->pluck('vehicle_id')
                ->toArray();

            $activeTripVehicles = \App\Models\OperationalTrip::where('status', 'on_going')
                ->whereNotNull('security_checked_out_at')
                ->whereNull('security_checked_in_at')
                ->whereNotNull('vehicle_id')
                ->pluck('vehicle_id')
                ->toArray();

            $genuinelyActiveVehicleIds = array_unique(array_filter(array_merge(
                $activeSplitDepVehicles,
                $activeSplitRetVehicles,
                $activeSingleVehicles,
                $activeTripVehicles
            )));

            // 2. Pulihkan kendaraan yang statusnya 'In Use' tapi tidak ada trip aktif di luar pabrik
            $vehiclesToRestore = Vehicle::whereIn('status', ['In Use', 'in use', 'IN USE', 'On Trip', 'on trip'])
                ->whereNotIn('id', $genuinelyActiveVehicleIds)
                ->get();

            foreach ($vehiclesToRestore as $veh) {
                $updateData = ['status' => 'Available'];

                // Cari KM akhir terbaru dari perjalanan yang telah checkin
                $latestSplitDepEndKm = \App\Models\Request::where('vehicle_id', $veh->id)
                    ->where('trip_mode', 'split_drop_pickup')
                    ->whereNotNull('departure_checked_in_at')
                    ->max('departure_end_km');

                $latestSplitRetEndKm = \App\Models\Request::where('return_vehicle_id', $veh->id)
                    ->where('trip_mode', 'split_drop_pickup')
                    ->whereNotNull('return_checked_in_at')
                    ->max('return_end_km');

                $latestGeneralEndKm = \App\Models\Request::where('vehicle_id', $veh->id)
                    ->whereNotNull('security_checked_in_at')
                    ->max('end_km');

                $maxRecordedKm = max(
                    (int)($latestSplitDepEndKm ?? 0),
                    (int)($latestSplitRetEndKm ?? 0),
                    (int)($latestGeneralEndKm ?? 0)
                );

                if ($maxRecordedKm > 0 && $maxRecordedKm > (int)($veh->odometer ?? 0)) {
                    $updateData['odometer'] = $maxRecordedKm;
                }

                $veh->update($updateData);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Vehicle Reconcile Error: " . $e->getMessage());
        }
    }
}