<?php

namespace App\Actions\Requests;

use App\Models\Request;
use App\Models\Passenger;
use App\Enums\RequestStatus;
use Illuminate\Support\Facades\DB;

class CreateRequestAction
{
    public function execute(array $data): Request
    {
        // Check daily request quota limit
        $maxRequests = (int) \App\Models\Setting::getValue('max_requests_per_day', 10);
        $date = date('Y-m-d', strtotime($data['start_time']));
        
        $count = Request::whereDate('start_time', $date)
            ->whereIn('status', [
                RequestStatus::SUBMITTED,
                RequestStatus::APPROVED_DEPARTMENT,
                RequestStatus::ASSIGNED_BY_GA,
                RequestStatus::APPROVED_HRD,
                RequestStatus::APPROVED_HRD_GA,
                RequestStatus::WAITING_DRIVER,
                RequestStatus::DRIVER_ASSIGNED,
                RequestStatus::ON_GOING,
                RequestStatus::COMPLETED
            ])->count();

        if ($count >= $maxRequests) {
            throw new \Exception("Pengajuan gagal. Kuota maksimal request untuk tanggal {$date} telah penuh ({$maxRequests} request).");
        }

        // Validate passenger same-day availability
        if (!empty($data['passengers'])) {
            foreach ($data['passengers'] as $passengerData) {
                if (empty($passengerData['name'])) continue;
                
                $name = trim($passengerData['name']);
                
                $hasConflict = Passenger::where('name', $name)
                    ->whereHas('request', function ($q) use ($date) {
                        $q->whereDate('start_time', $date)
                          ->whereIn('status', [
                              RequestStatus::SUBMITTED,
                              RequestStatus::APPROVED_DEPARTMENT,
                              RequestStatus::ASSIGNED_BY_GA,
                              RequestStatus::APPROVED_HRD,
                              RequestStatus::APPROVED_HRD_GA,
                              RequestStatus::WAITING_DRIVER,
                              RequestStatus::DRIVER_ASSIGNED,
                              RequestStatus::ON_GOING,
                              RequestStatus::COMPLETED
                          ]);
                    })
                    ->exists();
                
                if ($hasConflict) {
                    throw new \Exception("Penumpang '{$name}' sudah terdaftar pada perjalanan lain pada tanggal tersebut. Silakan pilih penumpang lain yang tidak memiliki jadwal perjalanan.");
                }
            }
        }

        return DB::transaction(function () use ($data) {
            $user = auth()->user();
            $isGA = $user->hasRoleDirect('GA') || $user->hasRoleDirect('Admin');
            
            $status = RequestStatus::SUBMITTED;
            $driverId = $data['driver_id'] ?? null;
            $vehicleId = $data['vehicle_id'] ?? null;
            $assignedBy = null;
            $assignedAt = null;
            $priority = $data['priority'] ?? 'Normal';
            $isExternal = !empty($data['is_external']);

            if ($isGA) {
                $priority = 'Urgent';
                if ($isExternal) {
                    $status = RequestStatus::ASSIGNED_BY_GA;
                    $assignedBy = $user->id;
                    $assignedAt = now();
                } elseif ($driverId && $vehicleId) {
                    $status = RequestStatus::ASSIGNED_BY_GA;
                    $assignedBy = $user->id;
                    $assignedAt = now();
                } else {
                    $status = RequestStatus::APPROVED_DEPARTMENT;
                }
            }

            $request = Request::create([
                'user_id' => $user->id,
                'department_id' => $data['department_id'] ?? $user->department_id,
                'destination_city' => $data['destination_city'],
                'destination_place' => $data['destination_place'],
                'purpose' => $data['purpose'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'] ?? null,
                'passenger_count' => $data['passenger_count'] ?? 1,
                'priority' => $priority,
                'status' => $status,
                'notes' => $data['notes'] ?? null,
                'qr_code_token' => null,
                'driver_id' => $isExternal ? null : $driverId,
                'vehicle_id' => $isExternal ? null : $vehicleId,
                'assigned_by' => $assignedBy,
                'assigned_at' => $assignedAt,
                // External fleet columns
                'is_external' => $isExternal,
                'third_party_cost' => $isExternal ? ($data['third_party_cost'] ?? 0) : 0,
                'external_fleet_info' => $isExternal ? ($data['external_fleet_info'] ?? null) : null,
                'external_driver_name' => $isExternal ? ($data['external_driver_name'] ?? null) : null,
                'external_license_plate' => $isExternal ? ($data['external_license_plate'] ?? null) : null,
                'external_trip_type' => $isExternal ? ($data['external_trip_type'] ?? 'round_trip') : 'round_trip',
                'external_departure_cost' => $isExternal ? ($data['external_departure_cost'] ?? 0) : 0,
                'external_return_cost' => $isExternal ? ($data['external_return_cost'] ?? 0) : 0,
            ]);

            // Create assignment and operational trip if driver & vehicle are assigned during creation
            if ($isGA && $driverId && $vehicleId) {
                // 1. Create Assignment
                \App\Models\Assignment::create([
                    'request_id' => $request->id,
                    'driver_id' => $driverId,
                    'assigned_by' => $user->id,
                    'assigned_at' => now(),
                    'status' => 'accepted',
                ]);

                // 2. Create OperationalTrip
                \App\Models\OperationalTrip::create([
                    'request_id' => $request->id,
                    'driver_id' => $driverId,
                    'vehicle_id' => $vehicleId,
                    'start_datetime' => $request->start_time,
                    'end_datetime' => $request->end_time,
                    'status' => 'scheduled',
                ]);
            }

            // Create passengers if provided
            if (!empty($data['passengers'])) {
                foreach ($data['passengers'] as $passengerData) {
                    $userId = $passengerData['user_id'] ?? null;
                    if (!$userId && !empty($passengerData['name'])) {
                        $resolved = \App\Models\User::where('name', trim($passengerData['name']))->first();
                        if ($resolved) {
                            $userId = $resolved->id;
                        }
                    }
                    Passenger::create([
                        'request_id' => $request->id,
                        'name' => $passengerData['name'],
                        'department_id' => $passengerData['department_id'] ?? null,
                        'user_id' => $userId,
                    ]);
                }
            }

            return $request;
        });
    }
}
