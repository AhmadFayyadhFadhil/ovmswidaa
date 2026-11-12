<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Enums\RequestStatus;

class RequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        try {
            $this->resource->syncStatus();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("RequestResource syncStatus error for #{$this->resource->id}: " . $e->getMessage());
        }

        try {
            $this->resource->ensureItinerariesExist();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("RequestResource ensureItinerariesExist error for #{$this->resource->id}: " . $e->getMessage());
        }

        $user = $request->user();

        $canApprove = false;
        $canReject = false;

        if ($user) {
            $canApprove = $user->can('approve', $this->resource);
            $canReject = $user->can('reject', $this->resource);
        }

        $driversList = [];
        $vehiclesList = [];

        // 1. Check operational trips (using eager-loaded relation instead of direct query)
        $trips = $this->relationLoaded('operationalTrips') ? $this->operationalTrips : collect();
        foreach ($trips as $trip) {
            if ($trip->driver) {
                $driversList[] = $trip->driver->name;
            }
            if ($trip->vehicle) {
                $vehiclesList[] = $trip->vehicle->name . ' (' . $trip->vehicle->plate_number . ')';
            }
        }

        // 2. Check pending assignments if no operational trips (using eager-loaded relation)
        $allAssignments = $this->relationLoaded('assignments') ? $this->assignments : collect();
        $activeAssignments = $allAssignments->whereIn('status', ['pending_driver', 'accepted']);

        if (empty($driversList)) {
            foreach ($activeAssignments as $asg) {
                if ($asg->driver) {
                    $driversList[] = $asg->driver->name;
                }
                if ($asg->vehicle) {
                    $vehiclesList[] = $asg->vehicle->name . ' (' . $asg->vehicle->plate_number . ')';
                }
            }
            if (empty($vehiclesList) && $this->vehicle) {
                $vehiclesList[] = $this->vehicle->name . ' (' . $this->vehicle->plate_number . ')';
            }
        }

        // 3. Fallback to direct request columns if lists are still empty
        if (empty($driversList) && $this->driver) {
            $driversList[] = $this->driver->name;
        }
        if (empty($vehiclesList) && $this->vehicle) {
            $vehiclesList[] = $this->vehicle->name . ' (' . $this->vehicle->plate_number . ')';
        }

        // Remove duplicates
        $driversList = array_unique($driversList);
        $vehiclesList = array_unique($vehiclesList);

        $driverNameStr = !empty($driversList) ? implode(', ', $driversList) : 'Not Assigned';
        $vehicleModelStr = !empty($vehiclesList) ? implode(', ', $vehiclesList) : 'Not Assigned';

        // Compute all_drivers_approved flag (using in-memory data, no extra query)
        $allDriversApproved = true;
        if ($activeAssignments->isEmpty()) {
            $allDriversApproved = false;
        } else {
            foreach ($activeAssignments as $asg) {
                if ($asg->status === 'pending_driver') {
                    $allDriversApproved = false;
                    break;
                }
            }
        }
        if ($this->is_external) {
            $allDriversApproved = false;
        }

        return [
            'all_drivers_approved' => $allDriversApproved,
            'driver_name'       => $driverNameStr,
            'vehicle_model'     => $vehicleModelStr,
            'id'                => $this->id,
            'department_id'     => $this->department_id,
            'department_name'   => $this->department?->name,
            'destination_city'  => $this->destination_city,
            'destination_place' => $this->destination_place,
            'purpose'           => $this->purpose,
            'start_time'        => $this->start_time ? ($this->start_time instanceof \DateTimeInterface ? $this->start_time->format('Y-m-d H:i:s') : (string)$this->start_time) : null,
            'end_time'          => $this->end_time ? ($this->end_time instanceof \DateTimeInterface ? $this->end_time->format('Y-m-d H:i:s') : (string)$this->end_time) : null,
            'passenger_count'   => $this->passenger_count,
            'priority'          => $this->priority instanceof \BackedEnum ? $this->priority->value : (string)($this->priority ?? 'Normal'),
            'status'            => $this->status instanceof \BackedEnum ? $this->status->value : (string)($this->status ?? 'submitted'),
            'notes'             => $this->notes,
            'rejected_reason'   => $this->rejected_reason,
            'cancelled_by'      => $this->cancelled_by,
            'cancelled_by_name' => $this->cancelledBy?->name,
            'itinerary_file_url' => $this->itinerary_file_path ? asset('storage/' . $this->itinerary_file_path) : null,
            'itineraries'       => ($this->relationLoaded('itineraries') && $this->itineraries->count() > 0 ? $this->itineraries : $this->itineraries()->with(['driver', 'vehicle'])->get())->map(function ($it) {
                return [
                    'id'                   => $it->id,
                    'date'                 => $it->date ? $it->date->format('Y-m-d') : null,
                    'morning_time'         => $it->morning_time,
                    'morning_destination'  => $it->morning_destination,
                    'afternoon_time'       => $it->afternoon_time,
                    'afternoon_destination'=> $it->afternoon_destination,
                    'passengers_notes'     => $it->passengers_notes,
                    'additional_passengers'=> $it->additional_passengers,
                    'driver_id'            => $it->driver_id,
                    'driver_name'          => $it->driver?->name,
                    'driver_email'         => $it->driver?->email,
                    'driver_phone'         => $it->driver?->phone,
                    'vehicle_name'         => $it->vehicle ? (trim(($it->vehicle->brand ? $it->vehicle->brand . ' ' : '') . ($it->vehicle->name ?? $it->vehicle->model)) . ($it->vehicle->plate_number ? ' (' . $it->vehicle->plate_number . ')' : '')) : null,
                    'is_external'          => $it->is_external,
                    'external_driver_name' => $it->external_driver_name,
                    'external_license_plate'=> $it->external_license_plate,
                    'external_fleet_info'  => $it->external_fleet_info,
                    'third_party_cost'     => $it->third_party_cost,
                    'security_checked_out_at' => $it->security_checked_out_at,
                    'security_checked_in_at'  => $it->security_checked_in_at,
                    'status'               => $it->status,
                    'morning_checked_out_at'  => $it->morning_checked_out_at,
                    'morning_checked_in_at'   => $it->morning_checked_in_at,
                    'morning_status'        => $it->morning_status ?? 'pending',
                    'morning_checkout_by'   => $it->morning_checkout_by,
                    'morning_checkin_by'    => $it->morning_checkin_by,
                    'morning_checkout_notes'=> $it->morning_checkout_notes,
                    'morning_checkin_notes' => $it->morning_checkin_notes,
                    'afternoon_checked_out_at'=> $it->afternoon_checked_out_at,
                    'afternoon_checked_in_at' => $it->afternoon_checked_in_at,
                    'afternoon_status'      => $it->afternoon_status ?? 'pending',
                    'afternoon_checkout_by'  => $it->afternoon_checkout_by,
                    'afternoon_checkin_by'   => $it->afternoon_checkin_by,
                    'afternoon_checkout_notes'=> $it->afternoon_checkout_notes,
                    'afternoon_checkin_notes' => $it->afternoon_checkin_notes,
                    'is_overtime'          => $it->is_overtime,
                    'overtime_minutes'     => $it->overtime_minutes,
                    'overtime_formatted'   => $it->overtime_formatted,
                    'start_km'             => $it->start_km,
                    'end_km'               => $it->end_km,
                    'total_km'             => $it->total_km,
                    'updated_at'           => $it->updated_at,
                ];
            }),
            'can_approve'       => $canApprove,
            'can_reject'        => $canReject,
            'next_approval_role' => match ($this->status) {
                RequestStatus::SUBMITTED => 'dept_head',
                RequestStatus::ASSIGNED_BY_GA => 'hrd_head',
                default => null,
            },
            'estimated_duration'=> $this->estimated_duration,
            'is_external'       => $this->is_external,
            'third_party_cost'  => $this->third_party_cost,
            'additional_passengers' => $this->additional_passengers ?? $this->operationalTrip?->additional_passengers ?? $trips->first()?->additional_passengers ?? $this->itineraries?->first()?->additional_passengers,
            'qr_code_token'     => $this->qr_code_token,
            'security_checked_out_at' => $this->security_checked_out_at ? ($this->security_checked_out_at instanceof \DateTimeInterface ? $this->security_checked_out_at->format('Y-m-d H:i:s') : (string)$this->security_checked_out_at) : null,
            'security_checked_in_at'  => $this->security_checked_in_at ? ($this->security_checked_in_at instanceof \DateTimeInterface ? $this->security_checked_in_at->format('Y-m-d H:i:s') : (string)$this->security_checked_in_at) : null,
            'security_checkout_by'    => $this->security_checkout_by,
            'security_checkin_by'     => $this->security_checkin_by,
            'security_checkout_notes' => $this->security_checkout_notes,
            'security_checkin_notes'  => $this->security_checkin_notes,
            'start_km'                => $this->start_km ?? $this->operationalTrip?->start_km ?? $trips->first()?->start_km ?? $this->itineraries?->first()?->start_km,
            'end_km'                  => $this->end_km ?? $this->operationalTrip?->end_km ?? $trips->first()?->end_km ?? $this->itineraries?->last()?->end_km,
            'driver_has_started_km'   => (bool)(
                ($this->trip_mode === 'split_drop_pickup')
                    ? (($this->departure_start_km ?? $this->start_km) && (float)($this->departure_start_km ?? $this->start_km) > 0)
                    : (($this->start_km ?? $this->operationalTrip?->start_km ?? $trips->first()?->start_km ?? $this->itineraries?->first()?->start_km) && (float)($this->start_km ?? $this->operationalTrip?->start_km ?? $trips->first()?->start_km ?? $this->itineraries?->first()?->start_km) > 0)
            ),
            'total_km'                => ($this->trip_mode === 'split_drop_pickup')
                ? (($this->departure_total_km ?? 0) + ($this->return_total_km ?? 0) ?: ($this->total_km ?? null))
                : ($this->total_km ?? $this->operationalTrip?->total_km ?? $trips->first()?->total_km ?? ($this->itineraries ? $this->itineraries->sum('total_km') : null)),
            'trip_mode'               => $this->trip_mode ?? 'standby',
            'split_trip_stage'        => ($this->trip_mode === 'split_drop_pickup') ? (
                $this->return_checked_in_at ? 'completed' : (
                    $this->return_checked_out_at ? 'return_ongoing' : (
                        ($this->departure_checked_in_at || ($this->security_checked_in_at && !$this->return_checked_out_at)) ? 'waiting_pickup' : (
                            ($this->departure_checked_out_at || $this->security_checked_out_at) ? 'departure_ongoing' : 'scheduled'
                        )
                    )
                )
            ) : null,
            'split_trip_status_label' => ($this->trip_mode === 'split_drop_pickup') ? (
                $this->return_checked_in_at ? 'Selesai' : (
                    $this->return_checked_out_at ? 'Sedang Berjalan (Penjemputan)' : (
                        ($this->departure_checked_in_at || ($this->security_checked_in_at && !$this->return_checked_out_at)) ? 'Terjadwal Jemput' : (
                            ($this->departure_checked_out_at || $this->security_checked_out_at) ? 'Sedang Berjalan (Pengantaran)' : 'Terjadwal'
                        )
                    )
                )
            ) : null,
            'departure_start_km'      => $this->departure_start_km ?? $this->start_km,
            'departure_end_km'        => $this->departure_end_km ?? ($this->departure_checked_in_at || ($this->security_checked_in_at && !$this->return_checked_out_at) ? $this->end_km : null),
            'departure_total_km'      => $this->departure_total_km,
            'departure_checked_out_at'=> ($this->departure_checked_out_at ?? $this->security_checked_out_at) ? (($this->departure_checked_out_at ?? $this->security_checked_out_at) instanceof \DateTimeInterface ? ($this->departure_checked_out_at ?? $this->security_checked_out_at)->format('Y-m-d H:i:s') : (string)($this->departure_checked_out_at ?? $this->security_checked_out_at)) : null,
            'departure_checked_in_at' => ($this->departure_checked_in_at ?? ($this->return_checked_out_at ? null : $this->security_checked_in_at)) ? (($this->departure_checked_in_at ?? ($this->return_checked_out_at ? null : $this->security_checked_in_at)) instanceof \DateTimeInterface ? ($this->departure_checked_in_at ?? ($this->return_checked_out_at ? null : $this->security_checked_in_at))->format('Y-m-d H:i:s') : (string)($this->departure_checked_in_at ?? ($this->return_checked_out_at ? null : $this->security_checked_in_at))) : null,
            'departure_checkout_by'   => $this->departure_checkout_by,
            'departure_checkin_by'    => $this->departure_checkin_by,
            'return_departure_time'   => $this->return_departure_time ? ($this->return_departure_time instanceof \DateTimeInterface ? $this->return_departure_time->format('Y-m-d H:i:s') : (string)$this->return_departure_time) : null,
            'return_driver_id'        => $this->return_driver_id,
            'return_driver'           => $this->returnDriver ? [
                'id'    => $this->returnDriver->id,
                'name'  => $this->returnDriver->name,
                'email' => $this->returnDriver->email,
                'phone' => $this->returnDriver->phone,
            ] : null,
            'return_vehicle_id'       => $this->return_vehicle_id,
            'return_vehicle'          => $this->returnVehicle ? [
                'id'           => $this->returnVehicle->id,
                'name'         => $this->returnVehicle->name,
                'plate_number' => $this->returnVehicle->plate_number,
                'type'         => $this->returnVehicle->type,
                'odometer'     => $this->returnVehicle->odometer,
            ] : null,
            'return_start_km'         => $this->return_start_km,
            'return_end_km'           => $this->return_end_km,
            'return_total_km'         => $this->return_total_km,
            'return_checked_out_at'   => $this->return_checked_out_at ? ($this->return_checked_out_at instanceof \DateTimeInterface ? $this->return_checked_out_at->format('Y-m-d H:i:s') : (string)$this->return_checked_out_at) : null,
            'return_checked_in_at'    => $this->return_checked_in_at ? ($this->return_checked_in_at instanceof \DateTimeInterface ? $this->return_checked_in_at->format('Y-m-d H:i:s') : (string)$this->return_checked_in_at) : null,
            'return_checkout_by'      => $this->return_checkout_by,
            'return_checkin_by'       => $this->return_checkin_by,
            'return_reassigned_at'    => $this->return_reassigned_at ? ($this->return_reassigned_at instanceof \DateTimeInterface ? $this->return_reassigned_at->format('Y-m-d H:i:s') : (string)$this->return_reassigned_at) : null,
            'return_reassigned_by'    => $this->return_reassigned_by,
            'return_reassigned_by_name'=> $this->returnReassignedBy?->name,
            'return_notes'            => $this->return_notes,
            'active_leg_stage'        => rescue(fn() => $this->calculateActiveLegStage(), 'departure_ready', false),
            'started_at'              => $this->started_at,
            'completed_at'            => $this->completed_at,
            'pending_previous_trip'   => rescue(fn() => \App\Services\TripAvailabilityGuardService::getLockForRequest($this->resource, $user?->id), null, false),
            'is_overtime'             => $this->is_overtime,
            'overtime_minutes'        => $this->overtime_minutes,
            'overtime_formatted'      => $this->overtime_formatted,
            'external_fleet_info'     => $this->external_fleet_info,
            'external_photo_url'      => $this->external_photo_path ? asset('storage/' . $this->external_photo_path) : null,
            'external_trip_type'      => $this->external_trip_type ?? 'round_trip',
            'external_departure_cost' => $this->external_departure_cost ?? 0,
            'external_return_cost'    => $this->external_return_cost ?? 0,
            'external_return_fleet_info' => $this->external_return_fleet_info,
            'external_return_photo_url'  => $this->external_return_photo_path ? asset('storage/' . $this->external_return_photo_path) : null,
            'external_driver_name'       => $this->external_driver_name,
            'external_license_plate'      => $this->external_license_plate,
            'external_return_driver_name' => $this->external_return_driver_name,
            'external_return_license_plate'=> $this->external_return_license_plate,
            'external_provider'           => $this->external_provider,

            // Second external vehicle:
            'external_driver_name_2'       => $this->external_driver_name_2,
            'external_license_plate_2'      => $this->external_license_plate_2,
            'external_fleet_info_2'        => $this->external_fleet_info_2,
            'external_photo_url_2'         => $this->external_photo_path_2 ? asset('storage/' . $this->external_photo_path_2) : null,
            'external_departure_cost_2'    => $this->external_departure_cost_2 ?? 0,
            'external_return_cost_2'       => $this->external_return_cost_2 ?? 0,
            'external_return_driver_name_2' => $this->external_return_driver_name_2,
            'external_return_license_plate_2'=> $this->external_return_license_plate_2,
            'external_return_fleet_info_2' => $this->external_return_fleet_info_2,
            'external_return_photo_url_2'  => $this->external_return_photo_path_2 ? asset('storage/' . $this->external_return_photo_path_2) : null,
            'third_party_cost_2'           => $this->third_party_cost_2 ?? 0,

            'rating'                  => $this->rating,
            'rating_notes'            => $this->rating_notes,
            'rated_at'                => $this->rated_at,

            'requested_by'      => [
                'id'    => $this->user?->id,
                'name'  => $this->user?->name,
                'email' => $this->user?->email,
                'phone' => $this->user?->phone,
            ],
            'approvals' => $this->whenLoaded('approvals', fn() =>
                $this->approvals->map(function ($a) {
                    $approver = $a->approver;
                    $approverName = $approver?->name;
                    $isAdmin = $approver?->hasRoleDirect('Admin') || $a->role === 'admin' || str_contains(strtolower($a->role ?? ''), 'admin');
                    $isGaTeam = $a->role === 'ga_team' || str_contains(strtolower($approverName ?? ''), 'gateam');
                    if ($isGaTeam) {
                        $specified = $this->ga_approved_by_name;
                        $approverName = $specified ? ('GA Team oleh ' . $specified) : 'GA Team (Backup Account)';
                    }
                    return [
                        'id'          => $a->id,
                        'role'        => $a->role === 'ga_team' ? 'GA Team Backup' : ($isAdmin ? 'admin' : $a->role),
                        'role_label'  => $isAdmin ? 'Admin System' : ($a->role === 'ga_team' ? 'GA Team Backup' : ($a->role === 'dept_head' ? 'Kepala Departemen' : 'GA & HRD')),
                        'status'      => $a->status,
                        'notes'       => $a->notes,
                        'is_admin'    => $isAdmin,
                        'admin_label' => $isAdmin ? 'Disetujui oleh Admin' : null,
                        'approver'    => [
                            'id'       => $approver?->id,
                            'name'     => $approverName,
                            'is_admin' => $isAdmin,
                        ],
                        'created_at'  => $a->created_at,
                    ];
                })
            ),
            'operational_trip' => $this->whenLoaded('operationalTrip', fn() => $this->operationalTrip ? [
                'id' => $this->operationalTrip->id,
                'driver' => [
                    'id' => $this->operationalTrip->driver?->id,
                    'name' => $this->operationalTrip->driver?->name,
                    'email' => $this->operationalTrip->driver?->email,
                    'phone' => $this->operationalTrip->driver?->phone,
                ],
                'vehicle' => [
                    'id' => $this->operationalTrip->vehicle?->id,
                    'name' => $this->operationalTrip->vehicle?->name,
                    'plate_number' => $this->operationalTrip->vehicle?->plate_number,
                    'type' => $this->operationalTrip->vehicle?->type,
                ],
                'status' => $this->operationalTrip->status,
                'additional_passengers' => $this->operationalTrip->additional_passengers,
            ] : null),
            'operational_trips' => $trips->map(fn($t) => [
                'id' => $t->id,
                'additional_passengers' => $t->additional_passengers,
                'driver' => [
                    'id' => $t->driver?->id,
                    'name' => $t->driver?->name,
                    'email' => $t->driver?->email,
                    'phone' => $t->driver?->phone,
                ],
                'vehicle' => [
                    'id' => $t->vehicle?->id,
                    'name' => $t->vehicle?->name,
                    'plate_number' => $t->vehicle?->plate_number,
                    'type' => $t->vehicle?->type,
                ],
                'status' => $t->status,
                'security_checked_out_at' => $t->security_checked_out_at ? ($t->security_checked_out_at instanceof \DateTimeInterface ? $t->security_checked_out_at->format('Y-m-d H:i:s') : (string)$t->security_checked_out_at) : null,
                'security_checked_in_at' => $t->security_checked_in_at ? ($t->security_checked_in_at instanceof \DateTimeInterface ? $t->security_checked_in_at->format('Y-m-d H:i:s') : (string)$t->security_checked_in_at) : null,
                'security_checkout_by' => $t->security_checkout_by,
                'security_checkin_by' => $t->security_checkin_by,
                'security_checkout_notes' => $t->security_checkout_notes,
                'security_checkin_notes' => $t->security_checkin_notes,
                'start_km' => $t->start_km,
                'end_km' => $t->end_km,
                'total_km' => $t->total_km,
            ]),
            'passengers' => $this->whenLoaded('passengers', fn() =>
                PassengerResource::collection($this->passengers)
            ),
            'driver' => $this->driver ? [
                'id' => $this->driver->id,
                'name' => $this->driver->name,
                'email' => $this->driver->email,
                'phone' => $this->driver->phone,
            ] : null,
            'vehicle' => $this->vehicle ? [
                'id' => $this->vehicle->id,
                'name' => $this->vehicle->name,
                'plate_number' => $this->vehicle->plate_number,
                'type' => $this->vehicle->type,
                'odometer' => $this->vehicle->odometer,
            ] : null,
            'assignments' => $this->relationLoaded('assignments') ? $this->assignments->map(fn($asg) => [
                'id'          => $asg->id,
                'driver_id'   => $asg->driver_id,
                'driver_name' => $asg->driver?->name,
                'driver_email'=> $asg->driver?->email,
                'driver_phone'=> $asg->driver?->phone,
                'vehicle_id'  => $asg->vehicle_id,
                'vehicle_name'=> $asg->vehicle?->name,
                'vehicle_plate'=> $asg->vehicle?->plate_number,
                'notes'       => $asg->notes,
                'status'      => $asg->status,
                'assigned_at' => $asg->assigned_at,
            ]) : null,
            'coordinator_id'          => $this->coordinator_id,
            'coordinator_name'        => $this->coordinator?->name,
            'coordinator_assigned_at' => $this->coordinator_assigned_at,
            'ga_approved_by'          => $this->ga_approved_by,
            'ga_approved_name'        => $this->ga_approved_by_name ?: ($this->gaApprover?->name ?? null),
            'ga_approved_by_name'     => $this->ga_approved_by_name ?: ($this->gaApprover?->name ?? null),
            'ga_approval_source'      => $this->ga_approval_source ?? 'primary',
            'ga_approval_display_text'=> $this->ga_approval_source === 'ga_team' 
                ? ('Disetujui oleh GA Team oleh ' . ($this->ga_approved_by_name ?: 'Tim GA Operasional'))
                : ('Disetujui oleh GA Coordinator (' . ($this->ga_approved_by_name ?: ($this->gaApprover?->name ?: 'Melodi Bella Astria')) . ')'),
            'ga_approved_at'          => $this->ga_approved_at,
            'created_at'              => $this->created_at,
            'updated_at'              => $this->updated_at,
        ];
    }

    private function calculateActiveLegStage(): string
    {
        if ($this->trip_mode === 'split_drop_pickup') {
            if ($this->departure_checked_out_at === null) {
                return 'departure_ready';
            } elseif ($this->departure_checked_in_at === null) {
                return 'departure_ongoing';
            } elseif ($this->return_checked_out_at === null) {
                return 'return_ready';
            } elseif ($this->return_checked_in_at === null) {
                return 'return_ongoing';
            } else {
                return 'all_completed';
            }
        }

        if ($this->security_checked_out_at === null) {
            return 'departure_ready';
        } elseif ($this->security_checked_in_at === null) {
            return 'departure_ongoing';
        } else {
            return 'all_completed';
        }
    }
}