<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssignmentRequest;
use App\Http\Requests\UpdateAssignmentRequest;
use App\Http\Resources\AssignmentResource;
use App\Http\Resources\RequestResource;
use App\Models\Assignment;
use App\Models\Request as VehicleRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Actions\Assignments\AssignDriverAction;
use App\Actions\Assignments\DriverRespondAction;
use App\Enums\RequestStatus;

class AssignmentController extends Controller
{
    private function hasRoleDirect($user, array $roles): bool
    {
        if (!$user) return false;
        if ($user->isHrGaHead()) return true;

        $userRoles = $user->roles()->pluck('name')->map(fn($r) => strtolower($r))->toArray();
        foreach ($roles as $role) {
            $target = strtolower($role);
            foreach ($userRoles as $ur) {
                if ($ur === $target || str_contains($ur, $target)) {
                    return true;
                }
            }
        }
        return false;
    }

    public function index(Request $request): JsonResponse
    {
        $user    = Auth::user();
        $perPage = $request->query('per_page', 15);
        $status  = $request->query('status');

        $query = Assignment::with(['request.user', 'request.passengers.department', 'request.driver', 'request.vehicle', 'request.operationalTrip.vehicle', 'request.operationalTrip.driver', 'request.operationalTrips.driver', 'request.operationalTrips.vehicle', 'request.assignments.driver', 'request.assignments.vehicle', 'request.approvals.approver', 'request.itineraries.driver', 'request.itineraries.vehicle', 'driver', 'vehicle', 'assignedBy']);

        if (!$this->hasRoleDirect($user, ['Admin', 'admin']) && !Auth::user()->isHrGaHead() && !$this->hasRoleDirect($user, ['GA', 'ga'])) {
            $query->where('driver_id', $user->id);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($request->query('request_id')) {
            $query->where('request_id', $request->query('request_id'));
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

    public function store(Request $request, AssignDriverAction $action): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user || (!$this->hasRoleDirect($user, ['Admin', 'admin', 'GA', 'ga', 'Approver', 'approver', 'HRD', 'hrd', 'head']) && !$user->isHrGaHead())) {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
            }

            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'request_id'         => 'required|exists:requests,id',
                'is_external'        => 'nullable|boolean',
                'third_party_cost'   => 'nullable|numeric|min:0',
                'estimated_duration' => 'nullable|integer|min:1',
                'priority'           => 'nullable|string',
                'driver_id'          => 'nullable|exists:users,id',
                'vehicle_id'         => 'nullable|exists:vehicles,id',
                'driver_ids'         => 'nullable|array',
                'driver_ids.*'       => 'exists:users,id|distinct',
                'vehicle_ids'        => 'nullable|array',
                'vehicle_ids.*'      => 'exists:vehicles,id|distinct',
                'notes'              => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $validator->errors()->first(),
                    'errors'  => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
            $vehicleRequest = VehicleRequest::findOrFail($validated['request_id']);

            if (!empty($validated['is_external'])) {
                $priority = $validated['priority'] ?? $vehicleRequest->priority->value ?? 'Normal';
                // Removed strict priority constraint: Urgent and Critical can now use third party fleet if needed

                $photoPath = null;
                if ($request->hasFile('external_photo')) {
                    $photoPath = $this->storePublicFileSafely($request->file('external_photo'), 'external_photos');
                }

                $returnPhotoPath = null;
                if ($request->hasFile('external_return_photo')) {
                    $returnPhotoPath = $this->storePublicFileSafely($request->file('external_return_photo'), 'external_photos');
                }

                $photoPath2 = null;
                if ($request->hasFile('external_photo_2')) {
                    $photoPath2 = $this->storePublicFileSafely($request->file('external_photo_2'), 'external_photos');
                }

                $returnPhotoPath2 = null;
                if ($request->hasFile('external_return_photo_2')) {
                    $returnPhotoPath2 = $this->storePublicFileSafely($request->file('external_return_photo_2'), 'external_photos');
                }

                $newStatus = \App\Enums\RequestStatus::DRIVER_ASSIGNED;

                // Generate QR token if not yet set (handles both null AND empty string cases)
                $qrToken = !empty($vehicleRequest->qr_code_token)
                    ? $vehicleRequest->qr_code_token
                    : ('REQ-' . time() . '-' . bin2hex(random_bytes(4)));

                $vehicleRequest->update([
                    'status' => $newStatus,
                    'qr_code_token' => $qrToken,
                    'is_external' => true,
                    'third_party_cost' => $validated['third_party_cost'] ?? 0,
                    'estimated_duration' => $validated['estimated_duration'] ?? null,
                    'priority' => $priority,
                    'notes' => $validated['notes'] ?? $vehicleRequest->notes,
                    'assigned_by' => auth()->id(),
                    'assigned_at' => now(),
                    'external_fleet_info' => $validated['external_fleet_info'] ?? null,
                    'external_photo_path' => $photoPath ?? $vehicleRequest->external_photo_path,
                    'external_trip_type' => $validated['external_trip_type'] ?? 'round_trip',
                    'external_departure_cost' => $validated['external_departure_cost'] ?? 0,
                    'external_return_cost'    => $validated['external_return_cost'] ?? 0,
                    'external_return_fleet_info' => $validated['external_return_fleet_info'] ?? null,
                    'external_return_photo_path' => $returnPhotoPath ?? $vehicleRequest->external_return_photo_path,
                    'external_driver_name'       => $validated['external_driver_name'] ?? null,
                    'external_license_plate'      => $validated['external_license_plate'] ?? null,
                    'external_return_driver_name' => $validated['external_return_driver_name'] ?? null,
                    'external_return_license_plate'=> $validated['external_return_license_plate'] ?? null,
                    'external_provider'           => $validated['external_provider'] ?? null,

                    // Second external vehicle:
                    'external_driver_name_2'       => $validated['external_driver_name_2'] ?? null,
                    'external_license_plate_2'      => $validated['external_license_plate_2'] ?? null,
                    'external_fleet_info_2'        => $validated['external_fleet_info_2'] ?? null,
                    'external_photo_path_2'        => $photoPath2 ?? $vehicleRequest->external_photo_path_2,
                    'external_departure_cost_2'    => $validated['external_departure_cost_2'] ?? 0,
                    'external_return_cost_2'       => $validated['external_return_cost_2'] ?? 0,
                    'external_return_driver_name_2' => $validated['external_return_driver_name_2'] ?? null,
                    'external_return_license_plate_2'=> $validated['external_return_license_plate_2'] ?? null,
                    'external_return_fleet_info_2' => $validated['external_return_fleet_info_2'] ?? null,
                    'external_return_photo_path_2' => $returnPhotoPath2 ?? $vehicleRequest->external_return_photo_path_2,
                    'third_party_cost_2'           => $validated['third_party_cost_2'] ?? 0,
                ]);

                return response()->json([
                    'status'  => 'success',
                    'message' => 'Request berhasil ditugaskan ke Pihak Ketiga',
                    'data'    => [
                        'id' => null,
                        'request' => $vehicleRequest->fresh(['user', 'passengers']),
                    ],
                ], 201);
            }

            $driverIds = !empty($validated['driver_ids']) 
                ? array_values(array_filter($validated['driver_ids'])) 
                : (!empty($validated['driver_id']) ? [$validated['driver_id']] : []);
                
            $vehicleIds = !empty($validated['vehicle_ids']) 
                ? array_values(array_filter($validated['vehicle_ids'])) 
                : (!empty($validated['vehicle_id']) ? [$validated['vehicle_id']] : []);

            if (empty($driverIds) || empty($vehicleIds)) {
                return response()->json(['status' => 'error', 'message' => 'Driver dan Kendaraan wajib dipilih.'], 422);
            }

            if (count($driverIds) > 1 && count($driverIds) !== count(array_unique($driverIds))) {
                return response()->json(['status' => 'error', 'message' => 'Driver 1 dan Driver 2 tidak boleh orang yang sama.'], 422);
            }

            if (count($vehicleIds) > 1 && count($vehicleIds) !== count(array_unique($vehicleIds))) {
                return response()->json(['status' => 'error', 'message' => 'Kendaraan 1 dan Kendaraan 2 tidak boleh unit mobil yang sama.'], 422);
            }

            \Illuminate\Support\Facades\DB::transaction(function () use ($vehicleRequest) {
                $existing = \App\Models\Assignment::where('request_id', $vehicleRequest->id)->get();
                foreach ($existing as $extAsg) {
                    if ($extAsg->driver) {
                        try {
                            $extAsg->driver->update(['availability_status' => 'available']);
                        } catch (\Throwable $ex) {}
                    }
                    \App\Models\OperationalTrip::where('request_id', $vehicleRequest->id)
                        ->where('driver_id', $extAsg->driver_id)
                        ->delete();
                    $extAsg->delete();
                }
            });

            $assignment = null;
            foreach ($driverIds as $index => $driverId) {
                $vId = $vehicleIds[$index] ?? $vehicleIds[0];
                $dInt = (int)$driverId;
                $vInt = (int)$vId;
                
                $driver = \App\Models\User::find($dInt);
                if (!$driver) {
                    return response()->json(['status' => 'error', 'message' => 'Driver yang dipilih tidak ditemukan'], 422);
                }

                $asg = $action->execute($vehicleRequest, $dInt, $vInt, $validated['notes'] ?? null, $validated);
                if ($index === 0) {
                    $assignment = $asg;
                }
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Kendaraan berhasil di-assign ke driver',
                'data'    => $assignment ? new AssignmentResource($assignment->fresh(['request.user', 'request.passengers', 'request.driver', 'request.vehicle', 'request.assignments.driver', 'driver', 'assignedBy'])) : [
                    'id' => null,
                    'request' => $vehicleRequest->fresh(['user', 'passengers', 'driver', 'vehicle']),
                ],
            ], 201);
        } catch (\Throwable $e) {
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
        if (Auth::id() !== $assignment->driver_id && !$this->hasRoleDirect(Auth::user(), ['Admin', 'admin']) && !Auth::user()->isHrGaHead() && !$this->hasRoleDirect(Auth::user(), ['GA', 'ga'])) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $assignment->load(['request.user', 'request.passengers', 'request.driver', 'request.vehicle', 'request.operationalTrip.vehicle', 'driver', 'assignedBy']);

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
        if (Auth::id() !== $assignment->driver_id && !$this->hasRoleDirect(Auth::user(), ['Admin', 'admin'])) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $validated = $request->validated();

        try {
            // Handle photo uploads
            $startPhotoPath = null;
            $endPhotoPath = null;
            
            if ($request->hasFile('start_photo')) {
                $startPhotoPath = $this->storePublicFileSafely($request->file('start_photo'), 'assignments/photos');
            }
            
            if ($request->hasFile('end_photo')) {
                $endPhotoPath = $this->storePublicFileSafely($request->file('end_photo'), 'assignments/photos');
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
                'data'    => new AssignmentResource($assignment->fresh(['request.user', 'request.passengers.department', 'request.driver', 'request.vehicle', 'request.operationalTrip.vehicle', 'request.operationalTrip.driver', 'request.operationalTrips.driver', 'request.operationalTrips.vehicle', 'request.assignments.driver', 'request.approvals.approver', 'request.itineraries.driver', 'request.itineraries.vehicle', 'driver', 'assignedBy'])),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function destroy(Assignment $assignment): JsonResponse
    {
        if (!$this->hasRoleDirect(Auth::user(), ['Admin', 'admin'])) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        if ($assignment->status !== 'pending_driver') {
            return response()->json(['status' => 'error', 'message' => 'Tidak dapat menghapus assignment yang sudah diproses'], 422);
        }

        $assignment->delete();

        return response()->json(['status' => 'success', 'message' => 'Assignment berhasil dihapus'], 200);
    }

    public function cancel(Assignment $assignment): JsonResponse
    {
        if (!$this->hasRoleDirect(Auth::user(), ['Admin', 'admin']) && !Auth::user()->isHrGaHead() && !$this->hasRoleDirect(Auth::user(), ['GA', 'ga'])) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        if ($assignment->status !== 'pending_driver') {
            return response()->json(['status' => 'error', 'message' => 'Tidak dapat membatalkan assignment yang sudah diproses'], 422);
        }

        // Kembalikan status request ke state sebelumnya (submitted)
        $revertStatus = \App\Enums\RequestStatus::SUBMITTED;

        $assignment->request()->update([
            'status'      => $revertStatus,
            'driver_id'   => null,
            'vehicle_id'  => null,
            'assigned_by' => null,
            'assigned_at' => null,
            'driver_response_status' => null,
            'qr_code_token' => null,
        ]);

        $assignment->delete();

        return response()->json(['status' => 'success', 'message' => 'Assignment berhasil dibatalkan'], 200);
    }

    public function storeDailyAssignments(Request $request, VehicleRequest $vehicleRequest): JsonResponse
    {
        $user = Auth::user();
        if (!$this->hasRoleDirect($user, ['Admin', 'admin']) && !$user->isHrGaHead() && !$this->hasRoleDirect($user, ['GA', 'ga'])) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'daily_assignments' => 'required|array|min:1',
            'daily_assignments.*.itinerary_id' => 'required|exists:request_itineraries,id',
            'daily_assignments.*.driver_id' => 'nullable|exists:users,id',
            'daily_assignments.*.vehicle_id' => 'nullable|exists:vehicles,id',
            'daily_assignments.*.is_external' => 'nullable|boolean',
            'daily_assignments.*.external_driver_name' => 'nullable|string|max:255',
            'daily_assignments.*.external_license_plate' => 'nullable|string|max:255',
            'daily_assignments.*.external_fleet_info' => 'nullable|string|max:255',
            'daily_assignments.*.third_party_cost' => 'nullable|numeric',
        ]);

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($vehicleRequest, $validated, $user) {
                foreach ($validated['daily_assignments'] as $asg) {
                    $itinerary = \App\Models\RequestItinerary::where('request_id', $vehicleRequest->id)
                        ->where('id', $asg['itinerary_id'])
                        ->firstOrFail();

                    $isExternal = !empty($asg['is_external']);
                    $driverId = $asg['driver_id'] ?? null;
                    $vehicleId = $asg['vehicle_id'] ?? null;

                    if (!$isExternal && $driverId) {
                        // Check driver conflict on this itinerary's date
                        $driver = \App\Models\User::find($driverId);
                        $driverName = $driver ? $driver->name : 'Driver';
                        $itDateStr = $itinerary->date ? $itinerary->date->format('Y-m-d') : null;

                        $conflictingDriverItinerary = \App\Models\RequestItinerary::where('driver_id', $driverId)
                            ->where('request_id', '!=', $vehicleRequest->id)
                            ->where('date', $itDateStr)
                            ->whereIn('status', ['assigned', 'on_going'])
                            ->whereHas('request', function ($q) {
                                $q->whereNotIn('status', [
                                    RequestStatus::REJECTED->value,
                                    RequestStatus::CANCELLED->value,
                                    RequestStatus::COMPLETED->value,
                                ]);
                            })
                            ->exists();

                        $conflictingDriverSingleRequest = \App\Models\Request::where('driver_id', $driverId)
                            ->where('id', '!=', $vehicleRequest->id)
                            ->whereIn('status', [
                                RequestStatus::WAITING_DRIVER->value,
                                RequestStatus::DRIVER_ASSIGNED->value,
                                RequestStatus::ON_GOING->value,
                            ])
                            ->where(function ($q) use ($itDateStr) {
                                $q->whereDate('start_time', $itDateStr)
                                  ->orWhereDate('end_time', $itDateStr);
                            })
                            ->exists();

                        if ($conflictingDriverItinerary || $conflictingDriverSingleRequest) {
                            throw new \Exception("Driver {$driverName} sudah memiliki tugas pada tanggal {$itDateStr}. Silakan pilih driver lain.");
                        }
                    }

                    if (!$isExternal && $vehicleId) {
                        // Check vehicle conflict on this itinerary's date
                        $vehicle = \App\Models\Vehicle::find($vehicleId);
                        $vehicleName = $vehicle ? "{$vehicle->name} ({$vehicle->plate_number})" : 'Kendaraan';
                        $itDateStr = $itinerary->date ? $itinerary->date->format('Y-m-d') : null;

                        $conflictingVehicleItinerary = \App\Models\RequestItinerary::where('vehicle_id', $vehicleId)
                            ->where('request_id', '!=', $vehicleRequest->id)
                            ->where('date', $itDateStr)
                            ->whereIn('status', ['assigned', 'on_going'])
                            ->whereHas('request', function ($q) {
                                $q->whereNotIn('status', [
                                    RequestStatus::REJECTED->value,
                                    RequestStatus::CANCELLED->value,
                                    RequestStatus::COMPLETED->value,
                                ]);
                            })
                            ->exists();

                        $conflictingVehicleSingleRequest = \App\Models\Request::where('vehicle_id', $vehicleId)
                            ->where('id', '!=', $vehicleRequest->id)
                            ->whereIn('status', [
                                RequestStatus::WAITING_DRIVER->value,
                                RequestStatus::DRIVER_ASSIGNED->value,
                                RequestStatus::ON_GOING->value,
                            ])
                            ->where(function ($q) use ($itDateStr) {
                                $q->whereDate('start_time', $itDateStr)
                                  ->orWhereDate('end_time', $itDateStr);
                            })
                            ->exists();

                        if ($conflictingVehicleItinerary || $conflictingVehicleSingleRequest) {
                            throw new \Exception("Kendaraan {$vehicleName} sudah ditugaskan pada tanggal {$itDateStr}. Silakan pilih kendaraan lain.");
                        }
                    }

                    $itinerary->update([
                        'driver_id' => $isExternal ? null : $driverId,
                        'vehicle_id' => $isExternal ? null : $vehicleId,
                        'is_external' => $isExternal,
                        'external_driver_name' => $isExternal ? ($asg['external_driver_name'] ?? null) : null,
                        'external_license_plate' => $isExternal ? ($asg['external_license_plate'] ?? null) : null,
                        'external_fleet_info' => $isExternal ? ($asg['external_fleet_info'] ?? null) : null,
                        'third_party_cost' => $isExternal ? ($asg['third_party_cost'] ?? 0) : 0,
                        'status' => 'assigned',
                    ]);

                    // Create or update Assignment row for driver if internal
                    if (!$isExternal && $driverId) {
                        Assignment::updateOrCreate(
                            [
                                'request_id' => $vehicleRequest->id,
                                'driver_id'  => $driverId,
                            ],
                            [
                                'assigned_by' => $user->id,
                                'assigned_at' => now(),
                                'notes' => "Assignment per tanggal " . ($itinerary->date ? $itinerary->date->format('d-m-Y') : ''),
                                'status' => 'accepted',
                            ]
                        );
                    }
                }

                $firstInternalDriver = null;
                $firstInternalVehicle = null;
                foreach ($validated['daily_assignments'] as $asg) {
                    if (empty($asg['is_external']) && !empty($asg['driver_id'])) {
                        if (!$firstInternalDriver) $firstInternalDriver = $asg['driver_id'];
                        if (!$firstInternalVehicle && !empty($asg['vehicle_id'])) $firstInternalVehicle = $asg['vehicle_id'];
                    }
                }

                $qrToken = !empty($vehicleRequest->qr_code_token)
                    ? $vehicleRequest->qr_code_token
                    : ('REQ-' . time() . '-' . bin2hex(random_bytes(4)));

                $vehicleRequest->update([
                    'status' => \App\Enums\RequestStatus::DRIVER_ASSIGNED,
                    'qr_code_token' => $qrToken,
                    'assigned_by' => $user->id,
                    'assigned_at' => now(),
                    'driver_id' => $firstInternalDriver ?? $vehicleRequest->driver_id,
                    'vehicle_id' => $firstInternalVehicle ?? $vehicleRequest->vehicle_id,
                ]);
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Penugasan harian berhasil disimpan',
                'data' => new RequestResource($vehicleRequest->fresh(['user', 'passengers.department', 'itineraries.driver', 'itineraries.vehicle', 'operationalTrips.driver', 'operationalTrips.vehicle', 'assignments.driver', 'approvals.approver', 'driver', 'vehicle', 'operationalTrip.driver', 'operationalTrip.vehicle'])),
            ], 200);
        } catch (\Throwable $e) {
            \Log::error('Daily assignment error:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
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
            $targetDir = storage_path('app/public/' . $relativeDir);

            if (!file_exists($targetDir)) {
                @mkdir($targetDir, 0777, true);
            }

            $targetPath = $targetDir . '/' . $filename;

            $success = false;
            if ($file->getRealPath()) {
                $success = @move_uploaded_file($file->getRealPath(), $targetPath) || @copy($file->getRealPath(), $targetPath);
            }

            if (!$success) {
                $storedPath = $file->store($relativeDir, 'public');
                return $storedPath ?: null;
            }

            return $relativeDir . '/' . $filename;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Assignment File Storage Error ({$folder}): " . $e->getMessage());
            try {
                return $file->store($folder, 'public');
            } catch (\Throwable $ex) {
                return null;
            }
        }
    }
}
