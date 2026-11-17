<?php

namespace App\Actions\Assignments;

use App\Models\Request;
use App\Models\Assignment;
use App\Models\Vehicle;
use App\Enums\RequestStatus;
use Illuminate\Support\Facades\DB;
use Exception;

class AssignDriverAction
{
    public function execute(Request $request, int $driverId, int $vehicleId, ?string $notes = null, array $data = []): Assignment
    {
        $createdAssignment = DB::transaction(function () use ($request, $driverId, $vehicleId, $notes, $data) {
            // Determine effective start_time, duration, and end_time
            $effectiveStartTime = !empty($data['start_time']) 
                ? \Carbon\Carbon::parse($data['start_time']) 
                : ($request->start_time ? \Carbon\Carbon::parse($request->start_time) : now());

            $effectiveDuration = isset($data['estimated_duration']) && is_numeric($data['estimated_duration']) && (int)$data['estimated_duration'] > 0
                ? (int)$data['estimated_duration']
                : ($request->estimated_duration ? (int)$request->estimated_duration : 3);

            $effectiveEndTime = !empty($data['end_time'])
                ? \Carbon\Carbon::parse($data['end_time'])
                : $effectiveStartTime->copy()->addHours($effectiveDuration);

            // Ensure end_time is strictly after start_time
            if ($effectiveEndTime->lte($effectiveStartTime)) {
                $effectiveEndTime = $effectiveStartTime->copy()->addHours($effectiveDuration);
            }

            // Determine trip mode: standby vs split_drop_pickup
            $tripMode = $data['trip_mode'] ?? $request->trip_mode ?? 'standby';
            $isSplitTrip = ($tripMode === 'split_drop_pickup');

            // Sesi 1 (Antar):
            $depDuration = isset($data['departure_duration']) && is_numeric($data['departure_duration']) && (int)$data['departure_duration'] > 0
                ? (int)$data['departure_duration']
                : 3;
            $depEndTime = $isSplitTrip ? $effectiveStartTime->copy()->addHours($depDuration) : $effectiveEndTime;

            // Sesi 2 (Jemput):
            $returnDriverId = !empty($data['return_driver_id']) ? (int)$data['return_driver_id'] : $driverId;
            $returnVehicleId = !empty($data['return_vehicle_id']) ? (int)$data['return_vehicle_id'] : $vehicleId;
            $returnDepartureTime = null;

            if ($isSplitTrip) {
                $returnDepartureTime = !empty($data['return_departure_time'])
                    ? \Carbon\Carbon::parse($data['return_departure_time'])
                    : $effectiveEndTime->copy()->subHours(2);
                if ($returnDepartureTime->gte($effectiveEndTime)) {
                    $returnDepartureTime = $effectiveEndTime->copy()->subHours(1);
                }
            }

            $poolGroupId = $data['pool_group_id'] ?? $request->pool_group_id;
            $pooledRequestIds = $data['pooled_request_ids'] ?? [];

            // ===== VALIDATE DRIVER & VEHICLE TIME CONFLICTS =====
            // 1. Sesi Keberangkatan / Antar:
            $this->validateDriverTimeConflict($driverId, $request, $effectiveStartTime, $depEndTime, $poolGroupId, $pooledRequestIds);
            $this->validateVehicleTimeConflict($vehicleId, $request, $effectiveStartTime, $depEndTime, $poolGroupId, $pooledRequestIds);

            // 2. Sesi Penjemputan (khusus split_drop_pickup):
            if ($isSplitTrip && $returnDepartureTime) {
                $this->validateDriverTimeConflict($returnDriverId, $request, $returnDepartureTime, $effectiveEndTime, $poolGroupId, $pooledRequestIds);
                $this->validateVehicleTimeConflict($returnVehicleId, $request, $returnDepartureTime, $effectiveEndTime, $poolGroupId, $pooledRequestIds);
            }

            // Priority check
            $priorityVal = 'Normal';
            if (!empty($data['priority'])) {
                $priorityVal = $data['priority'] instanceof \BackedEnum ? $data['priority']->value : (is_object($data['priority']) ? ($data['priority']->value ?? 'Normal') : (string)$data['priority']);
            } elseif (!empty($request->priority)) {
                $priorityVal = $request->priority instanceof \BackedEnum ? $request->priority->value : (is_object($request->priority) ? ($request->priority->value ?? 'Normal') : (string)$request->priority);
            }

            $authUser = auth()->user();
            $assignerId = $authUser?->id ?? $request->user_id ?? 1;

            $userRoles = $authUser ? $authUser->getRoleNames()->map(fn($r) => strtolower(trim($r)))->toArray() : [];
            $isGAOrAdmin = in_array('ga', $userRoles, true) || in_array('admin', $userRoles, true) || in_array('administrator', $userRoles, true);
            $isCoordinator = in_array('driver coordinator', $userRoles, true) || in_array('driver_coordinator', $userRoles, true);

            $isUrgent = in_array(strtolower($priorityVal), ['urgent', 'critical'], true);
            $isDirectScheduled = $isGAOrAdmin || $isUrgent || !$isCoordinator;

            $asgStatus = $isDirectScheduled ? 'accepted' : 'allocated';
            $reqStatus = $isDirectScheduled ? RequestStatus::DRIVER_ASSIGNED : RequestStatus::ASSIGNED_BY_GA;

            // Cancel any old assignments for drivers not part of this new assignment
            $activeDriverIds = array_filter([$driverId, $returnDriverId]);
            Assignment::where('request_id', $request->id)
                ->whereNotIn('driver_id', $activeDriverIds)
                ->whereNotIn('status', ['completed'])
                ->update(['status' => 'cancelled']);

            // Create assignment for primary (departure) driver
            $assignment = Assignment::create([
                'request_id'  => $request->id,
                'vehicle_id'  => $vehicleId,
                'driver_id'   => $driverId,
                'assigned_by' => $assignerId,
                'assigned_at' => now(),
                'status'      => $asgStatus,
                'notes'       => $isSplitTrip ? 'Penugasan Sesi Keberangkatan (Antar)' : $notes,
            ]);

            // Create assignment for return driver if different
            if ($isSplitTrip && $returnDriverId && $returnDriverId !== $driverId) {
                Assignment::create([
                    'request_id'  => $request->id,
                    'vehicle_id'  => $returnVehicleId,
                    'driver_id'   => $returnDriverId,
                    'assigned_by' => $assignerId,
                    'assigned_at' => now(),
                    'status'      => $asgStatus,
                    'notes'       => 'Penugasan Sesi Penjemputan (Jemput)',
                ]);
            }

            $qrCodeToken = $request->qr_code_token;
            if ($isDirectScheduled && !$qrCodeToken) {
                $qrCodeToken = 'REQ-' . time() . '-' . bin2hex(random_bytes(4));
            }

            // Update request fields and status
            $updatePayload = [
                'status'                 => $reqStatus,
                'trip_mode'              => $tripMode,
                'driver_id'              => $driverId,
                'vehicle_id'             => $vehicleId,
                'assigned_by'            => $assignerId,
                'assigned_at'            => now(),
                'is_external'            => false,
                'third_party_cost'       => 0,
                'start_time'             => $effectiveStartTime->format('Y-m-d H:i:s'),
                'end_time'               => $effectiveEndTime->format('Y-m-d H:i:s'),
                'estimated_duration'     => $effectiveDuration,
                'priority'               => $priorityVal,
                'driver_response_status' => $asgStatus,
                'qr_code_token'          => $qrCodeToken,
            ];

            if (isset($data['pool_group_id'])) {
                $updatePayload['pool_group_id'] = $data['pool_group_id'];
                $updatePayload['is_pooled'] = (bool)($data['is_pooled'] ?? true);
                $updatePayload['master_pool_qr'] = $data['master_pool_qr'] ?? null;
            }
            if (isset($data['chained_from_request_id'])) {
                $updatePayload['chained_from_request_id'] = $data['chained_from_request_id'];
            }

            if ($isSplitTrip) {
                $updatePayload['return_departure_time'] = $returnDepartureTime ? $returnDepartureTime->format('Y-m-d H:i:s') : null;
                $updatePayload['return_driver_id']      = $returnDriverId;
                $updatePayload['return_vehicle_id']     = $returnVehicleId;
                $updatePayload['return_notes']          = $data['return_notes'] ?? null;
            }

            if ($isCoordinator && !$isGAOrAdmin) {
                $updatePayload['coordinator_id'] = $assignerId;
                $updatePayload['coordinator_assigned_at'] = now();
            }

            if ($isDirectScheduled) {
                $updatePayload['ga_approved_by'] = $assignerId;
                $updatePayload['ga_approved_at'] = now();
            }

            $request->update($updatePayload);

            if ($isDirectScheduled) {
                try {
                    // Sesi 1: Keberangkatan (Antar)
                    $trip1 = \App\Models\OperationalTrip::where('request_id', $request->id)
                        ->where('driver_id', $driverId)
                        ->first();
                    if (!$trip1) {
                        $trip1 = new \App\Models\OperationalTrip(['request_id' => $request->id]);
                    }
                    $trip1->driver_id = $driverId;
                    $trip1->vehicle_id = $vehicleId;
                    $trip1->start_datetime = $effectiveStartTime->format('Y-m-d H:i:s');
                    $trip1->end_datetime = $depEndTime->format('Y-m-d H:i:s');
                    $trip1->status = 'scheduled';
                    $trip1->save();

                    // Sesi 2: Penjemputan (khusus split_drop_pickup)
                    if ($isSplitTrip && $returnDepartureTime) {
                        $trip2 = \App\Models\OperationalTrip::where('request_id', $request->id)
                            ->where('id', '!=', $trip1->id)
                            ->first();
                        if (!$trip2) {
                            $trip2 = new \App\Models\OperationalTrip(['request_id' => $request->id]);
                        }
                        $trip2->driver_id = $returnDriverId;
                        $trip2->vehicle_id = $returnVehicleId;
                        $trip2->start_datetime = $returnDepartureTime->format('Y-m-d H:i:s');
                        $trip2->end_datetime = $effectiveEndTime->format('Y-m-d H:i:s');
                        $trip2->status = 'scheduled';
                        $trip2->save();
                    }
                } catch (\Throwable $ex) {}
            }

            return $assignment;
        });

        return $createdAssignment;
    }

    /**
     * Re-assign return trip driver and vehicle dynamically
     */
    public function reassignReturnTrip(Request $request, int $newDriverId, ?int $newVehicleId = null, ?string $notes = null): Request
    {
        if ($request->return_checked_out_at !== null) {
            throw new Exception("Sesi penjemputan sudah melakukan check-out keluar gerbang dan tidak dapat diubah supirnya.");
        }

        $effectiveEndTime = $request->end_time ? \Carbon\Carbon::parse($request->end_time) : now()->addHours(3);
        $returnDepartureTime = $request->return_departure_time 
            ? \Carbon\Carbon::parse($request->return_departure_time) 
            : $effectiveEndTime->copy()->subHours(2);

        $targetVehicleId = $newVehicleId ?? $request->return_vehicle_id ?? $request->vehicle_id;

        // Validate conflicts for return window (support return_pool_group_id)
        $ignorePool = $request->return_pool_group_id ?? $request->pool_group_id;
        $this->validateDriverTimeConflict($newDriverId, $request, $returnDepartureTime, $effectiveEndTime, $ignorePool);
        if ($targetVehicleId) {
            $this->validateVehicleTimeConflict($targetVehicleId, $request, $returnDepartureTime, $effectiveEndTime, $ignorePool);
        }

        $authUser = auth()->user();
        $assignerId = $authUser?->id ?? 1;

        $request->update([
            'return_driver_id'      => $newDriverId,
            'return_vehicle_id'     => $targetVehicleId,
            'return_reassigned_at'  => now(),
            'return_reassigned_by'  => $assignerId,
            'return_reassign_notes' => $notes,
        ]);

        // Cancel any previous return driver assignment if different from departure and new driver
        try {
            Assignment::where('request_id', $request->id)
                ->where('driver_id', '!=', $request->driver_id)
                ->where('driver_id', '!=', $newDriverId)
                ->whereNotIn('status', ['completed'])
                ->update(['status' => 'cancelled']);
        } catch (\Throwable $e) {}

        // Update or create assignment record for return driver
        try {
            Assignment::updateOrCreate(
                ['request_id' => $request->id, 'driver_id' => $newDriverId],
                [
                    'vehicle_id'  => $targetVehicleId,
                    'assigned_by' => $assignerId,
                    'assigned_at' => now(),
                    'status'      => 'accepted',
                    'notes'       => $notes ?: 'Re-assignment Sesi Penjemputan',
                ]
            );

            // Update or create OperationalTrip for the return session
            $departureTrip = \App\Models\OperationalTrip::where('request_id', $request->id)
                ->where('driver_id', $request->driver_id)
                ->first();

            $returnTrip = \App\Models\OperationalTrip::where('request_id', $request->id)
                ->when($departureTrip, function($q) use ($departureTrip) {
                    $q->where('id', '!=', $departureTrip->id);
                })
                ->orderBy('id', 'desc')
                ->first();

            if (!$returnTrip) {
                $returnTrip = new \App\Models\OperationalTrip(['request_id' => $request->id]);
            }

            $returnTrip->driver_id = $newDriverId;
            $returnTrip->vehicle_id = $targetVehicleId;
            $returnTrip->start_datetime = $returnDepartureTime->format('Y-m-d H:i:s');
            $returnTrip->end_datetime = $effectiveEndTime->format('Y-m-d H:i:s');
            $returnTrip->status = 'scheduled';
            $returnTrip->save();
        } catch (\Throwable $ex) {}

        return $request->fresh(['user', 'driver', 'vehicle', 'returnDriver', 'returnVehicle']);
    }

    /**
     * Validate driver doesn't have conflicting assignment at the same time
     * Supports smart leg checking for split_drop_pickup requests
     */
    private function validateDriverTimeConflict(int $driverId, Request $request, ?\Carbon\Carbon $effectiveStartTime = null, ?\Carbon\Carbon $effectiveEndTime = null, ?string $ignorePoolGroupId = null, array $ignoreRequestIds = []): void
    {
        $startTime = $effectiveStartTime ?? ($request->start_time ? \Carbon\Carbon::parse($request->start_time) : null);
        $endTime = $effectiveEndTime ?? ($request->end_time ? \Carbon\Carbon::parse($request->end_time) : null);

        if (!$startTime || !$endTime) {
            return; // Cannot validate without time range
        }

        $conflictQuery = Request::where(function ($dQuery) use ($driverId) {
                $dQuery->where('driver_id', $driverId)
                       ->orWhere('return_driver_id', $driverId);
            })
            ->whereNotIn('status', ['completed', 'cancelled', 'rejected']);

        if (!empty($ignoreRequestIds)) {
            $conflictQuery->whereNotIn('id', array_unique(array_merge([$request->id], $ignoreRequestIds)));
        } else {
            $conflictQuery->where('id', '!=', $request->id);
        }

        $poolGroup = $ignorePoolGroupId ?? $request->pool_group_id ?? $request->return_pool_group_id;
        if (!empty($poolGroup)) {
            $conflictQuery->where(function ($q) use ($poolGroup) {
                $q->where(function ($sub) use ($poolGroup) {
                    $sub->whereNull('pool_group_id')
                        ->orWhere('pool_group_id', '!=', $poolGroup);
                })->where(function ($sub) use ($poolGroup) {
                    $sub->whereNull('return_pool_group_id')
                        ->orWhere('return_pool_group_id', '!=', $poolGroup);
                });
            });
        }

        $conflict = $conflictQuery->where(function ($q) use ($startTime, $endTime) {
                $q->where(function ($sub) use ($startTime, $endTime) {
                    // Check split trips: check overlap with departure leg or return leg independently
                    $sub->where('trip_mode', 'split_drop_pickup')
                        ->where(function ($legs) use ($startTime, $endTime) {
                            $legs->where(function ($dep) use ($startTime, $endTime) {
                                $dep->where('start_time', '<', $endTime)
                                    ->whereRaw('COALESCE(departure_checked_in_at, DATE_ADD(start_time, INTERVAL 3 HOUR)) > ?', [$startTime->format('Y-m-d H:i:s')]);
                            })->orWhere(function ($ret) use ($startTime, $endTime) {
                                $ret->whereNotNull('return_departure_time')
                                    ->where('return_departure_time', '<', $endTime)
                                    ->where('end_time', '>', $startTime);
                            });
                        });
                })->orWhere(function ($sub) use ($startTime, $endTime) {
                    // Regular standby trips
                    $sub->where(function ($mode) {
                        $mode->whereNull('trip_mode')->orWhere('trip_mode', '!=', 'split_drop_pickup');
                    })->where('start_time', '<', $endTime)
                      ->where('end_time', '>', $startTime);
                });
            })
            ->first();

        if ($conflict) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'driver_id' => 'Driver ini sudah ditugaskan ke request lain (#REQ-' . $conflict->id . ') pada rentang waktu yang sama (' . \Carbon\Carbon::parse($conflict->start_time)->format('d/m/Y H:i') . ' - ' . \Carbon\Carbon::parse($conflict->end_time)->format('d/m/Y H:i') . '). Silakan pilih driver lain atau sesuaikan jam keberangkatan.',
            ]);
        }
    }

    /**
     * Validate vehicle doesn't have conflicting assignment at the same time
     * Supports smart leg checking for split_drop_pickup requests
     */
    private function validateVehicleTimeConflict(int $vehicleId, Request $request, ?\Carbon\Carbon $effectiveStartTime = null, ?\Carbon\Carbon $effectiveEndTime = null, ?string $ignorePoolGroupId = null, array $ignoreRequestIds = []): void
    {
        $startTime = $effectiveStartTime ?? ($request->start_time ? \Carbon\Carbon::parse($request->start_time) : null);
        $endTime = $effectiveEndTime ?? ($request->end_time ? \Carbon\Carbon::parse($request->end_time) : null);

        if (!$startTime || !$endTime) {
            return; // Cannot validate without time range
        }

        $conflictQuery = Request::where(function ($vQuery) use ($vehicleId) {
                $vQuery->where('vehicle_id', $vehicleId)
                       ->orWhere('return_vehicle_id', $vehicleId);
            })
            ->whereNotIn('status', ['completed', 'cancelled', 'rejected']);

        if (!empty($ignoreRequestIds)) {
            $conflictQuery->whereNotIn('id', array_unique(array_merge([$request->id], $ignoreRequestIds)));
        } else {
            $conflictQuery->where('id', '!=', $request->id);
        }

        $poolGroup = $ignorePoolGroupId ?? $request->pool_group_id ?? $request->return_pool_group_id;
        if (!empty($poolGroup)) {
            $conflictQuery->where(function ($q) use ($poolGroup) {
                $q->where(function ($sub) use ($poolGroup) {
                    $sub->whereNull('pool_group_id')
                        ->orWhere('pool_group_id', '!=', $poolGroup);
                })->where(function ($sub) use ($poolGroup) {
                    $sub->whereNull('return_pool_group_id')
                        ->orWhere('return_pool_group_id', '!=', $poolGroup);
                });
            });
        }

        $conflict = $conflictQuery->where(function ($q) use ($startTime, $endTime) {
                $q->where(function ($sub) use ($startTime, $endTime) {
                    // Check split trips: check overlap with departure leg or return leg independently
                    $sub->where('trip_mode', 'split_drop_pickup')
                        ->where(function ($legs) use ($startTime, $endTime) {
                            $legs->where(function ($dep) use ($startTime, $endTime) {
                                $dep->where('start_time', '<', $endTime)
                                    ->whereRaw('COALESCE(departure_checked_in_at, DATE_ADD(start_time, INTERVAL 3 HOUR)) > ?', [$startTime->format('Y-m-d H:i:s')]);
                            })->orWhere(function ($ret) use ($startTime, $endTime) {
                                $ret->whereNotNull('return_departure_time')
                                    ->where('return_departure_time', '<', $endTime)
                                    ->where('end_time', '>', $startTime);
                            });
                        });
                })->orWhere(function ($sub) use ($startTime, $endTime) {
                    // Regular standby trips
                    $sub->where(function ($mode) {
                        $mode->whereNull('trip_mode')->orWhere('trip_mode', '!=', 'split_drop_pickup');
                    })->where('start_time', '<', $endTime)
                      ->where('end_time', '>', $startTime);
                });
            })
            ->first();

        if ($conflict) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'vehicle_id' => 'Kendaraan ini sudah ditugaskan ke request lain (#REQ-' . $conflict->id . ') pada rentang waktu yang sama (' . \Carbon\Carbon::parse($conflict->start_time)->format('d/m/Y H:i') . ' - ' . \Carbon\Carbon::parse($conflict->end_time)->format('d/m/Y H:i') . '). Silakan pilih kendaraan lain atau sesuaikan jam keberangkatan.',
            ]);
        }
    }

    /**
     * Group multiple requests into a shared pooled ride with 1 driver and 1 vehicle.
     * Generates a shared pool_group_id and master_pool_qr.
     */
    public function poolRequests(array $requestIds, int $driverId, int $vehicleId, array $data = []): array
    {
        if (count($requestIds) < 2) {
            throw new Exception("Minimal 2 permohonan kendaraan diperlukan untuk menggabungkan perjalanan (shared ride).");
        }

        $requests = Request::whereIn('id', $requestIds)
            ->whereNotIn('status', ['completed', 'cancelled', 'rejected'])
            ->orderBy('start_time', 'asc')
            ->get();

        if ($requests->count() < 2) {
            throw new Exception("Permohonan yang dipilih tidak memenuhi syarat atau sudah berstatus selesai/batal.");
        }

        $poolGroupId = 'POOL-' . date('Ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(6));
        $masterPoolQr = 'POOL-QR-' . time() . '-' . strtoupper(\Illuminate\Support\Str::random(6));

        $updatedRequests = [];

        DB::transaction(function () use ($requests, $requestIds, $driverId, $vehicleId, $data, $poolGroupId, $masterPoolQr, &$updatedRequests) {
            // Pre-stamp all requests in the pool with pool_group_id so mutual operations recognize the group
            Request::whereIn('id', $requestIds)->update([
                'pool_group_id'  => $poolGroupId,
                'is_pooled'      => true,
                'master_pool_qr' => $masterPoolQr,
            ]);

            $previousReqId = null;

            foreach ($requests as $idx => $req) {
                $req->refresh();
                $reqData = $data;
                $reqData['pool_group_id'] = $poolGroupId;
                $reqData['is_pooled'] = true;
                $reqData['master_pool_qr'] = $masterPoolQr;
                $reqData['pooled_request_ids'] = $requestIds;

                // For subsequent legs or requests in pool, record chained_from_request_id
                if ($previousReqId !== null) {
                    $reqData['chained_from_request_id'] = $previousReqId;
                }

                // If specific return schedule per request is not set, keep request's own return_departure_time if exists
                if (empty($reqData['return_departure_time']) && !empty($req->return_departure_time)) {
                    $reqData['return_departure_time'] = $req->return_departure_time;
                }

                $this->execute($req, $driverId, $vehicleId, $data['notes'] ?? 'Perjalanan Gabungan (Shared Ride)', $reqData);
                $updatedRequests[] = $req->fresh(['user', 'driver', 'vehicle', 'returnDriver', 'returnVehicle', 'passengers']);

                $previousReqId = $req->id;
            }
        });

        return $updatedRequests;
    }

    /**
     * Pool return penjemputan across multiple requests that originally had different drivers/vehicles.
     */
    public function poolReturnTrips(array $requestIds, int $returnDriverId, ?int $returnVehicleId = null, ?string $notes = null): array
    {
        if (count($requestIds) < 2) {
            throw new Exception("Minimal 2 permohonan kendaraan diperlukan untuk menggabungkan penjemputan.");
        }

        $requests = Request::whereIn('id', $requestIds)
            ->whereNotIn('status', ['completed', 'cancelled', 'rejected'])
            ->get();

        if ($requests->count() < 2) {
            throw new Exception("Permohonan yang dipilih tidak memenuhi syarat atau sudah berstatus selesai/batal.");
        }

        $returnPoolGroupId = 'RET-POOL-' . date('Ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(6));

        $updatedRequests = [];
        DB::transaction(function () use ($requests, $requestIds, $returnDriverId, $returnVehicleId, $notes, $returnPoolGroupId, &$updatedRequests) {
            // Pre-stamp all requests with return_pool_group_id so conflict validator recognizes them as mutual pool
            Request::whereIn('id', $requestIds)->update([
                'return_pool_group_id' => $returnPoolGroupId,
            ]);

            foreach ($requests as $req) {
                $req->refresh();
                $updatedRequests[] = $this->reassignReturnTrip($req, $returnDriverId, $returnVehicleId, $notes ?? 'Penjemputan Gabungan (Shared Return Pickup)');
            }
        });

        return $updatedRequests;
    }
}
