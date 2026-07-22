<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Enums\RequestStatus;

class RequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->syncStatus();
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
            }
            if ($this->vehicle) {
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
            'start_time'        => $this->start_time,
            'end_time'          => $this->end_time,
            'passenger_count'   => $this->passenger_count,
            'priority'          => $this->priority?->value,
            'status'            => $this->status?->value,
            'notes'             => $this->notes,
            'itinerary_file_url' => $this->itinerary_file_path ? asset('storage/' . $this->itinerary_file_path) : null,
            'itineraries'       => $this->relationLoaded('itineraries') ? $this->itineraries->map(function ($it) {
                return [
                    'id'                   => $it->id,
                    'date'                 => $it->date ? $it->date->format('Y-m-d') : null,
                    'morning_time'         => $it->morning_time,
                    'morning_destination'  => $it->morning_destination,
                    'afternoon_time'       => $it->afternoon_time,
                    'afternoon_destination'=> $it->afternoon_destination,
                    'passengers_notes'     => $it->passengers_notes,
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
                    'updated_at'           => $it->updated_at,
                ];
            }) : [],
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
            'qr_code_token'     => $this->qr_code_token,
            'security_checked_out_at' => $this->security_checked_out_at,
            'security_checked_in_at'  => $this->security_checked_in_at,
            'security_checkout_by'    => $this->security_checkout_by,
            'security_checkin_by'     => $this->security_checkin_by,
            'security_checkout_notes' => $this->security_checkout_notes,
            'security_checkin_notes'  => $this->security_checkin_notes,
            'started_at'              => $this->started_at,
            'completed_at'            => $this->completed_at,
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

            'requested_by'      => [
                'id'    => $this->user?->id,
                'name'  => $this->user?->name,
                'email' => $this->user?->email,
            ],
            'approvals' => $this->whenLoaded('approvals', fn() =>
                $this->approvals->map(fn($a) => [
                    'id'       => $a->id,
                    'role'     => $a->role,
                    'status'   => $a->status,
                    'notes'    => $a->notes,
                    'approver' => [
                        'id'   => $a->approver?->id,
                        'name' => $a->approver?->name,
                    ],
                    'created_at' => $a->created_at,
                ])
            ),
            'operational_trip' => $this->whenLoaded('operationalTrip', fn() => $this->operationalTrip ? [
                'id' => $this->operationalTrip->id,
                'driver' => [
                    'id' => $this->operationalTrip->driver?->id,
                    'name' => $this->operationalTrip->driver?->name,
                    'email' => $this->operationalTrip->driver?->email,
                ],
                'vehicle' => [
                    'id' => $this->operationalTrip->vehicle?->id,
                    'name' => $this->operationalTrip->vehicle?->name,
                    'plate_number' => $this->operationalTrip->vehicle?->plate_number,
                    'type' => $this->operationalTrip->vehicle?->type,
                ],
                'status' => $this->operationalTrip->status,
            ] : null),
            'operational_trips' => $trips->map(fn($t) => [
                'id' => $t->id,
                'driver' => [
                    'id' => $t->driver?->id,
                    'name' => $t->driver?->name,
                    'email' => $t->driver?->email,
                ],
                'vehicle' => [
                    'id' => $t->vehicle?->id,
                    'name' => $t->vehicle?->name,
                    'plate_number' => $t->vehicle?->plate_number,
                    'type' => $t->vehicle?->type,
                ],
                'status' => $t->status,
                'security_checked_out_at' => $t->security_checked_out_at,
                'security_checked_in_at' => $t->security_checked_in_at,
                'security_checkout_by' => $t->security_checkout_by,
                'security_checkin_by' => $t->security_checkin_by,
                'security_checkout_notes' => $t->security_checkout_notes,
                'security_checkin_notes' => $t->security_checkin_notes,
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
            ] : null,
            'assignments' => $this->relationLoaded('assignments') ? $this->assignments->map(fn($asg) => [
                'id'          => $asg->id,
                'driver_id'   => $asg->driver_id,
                'driver_name' => $asg->driver?->name,
                'driver_email'=> $asg->driver?->email,
                'driver_phone'=> $asg->driver?->phone,
                'notes'       => $asg->notes,
                'status'      => $asg->status,
                'assigned_at' => $asg->assigned_at,
            ]) : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}