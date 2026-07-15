<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Enums\RequestStatus;

class RequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        $canApprove = false;
        $canReject = false;

        if ($user) {
            $canApprove = $user->can('approve', $this->resource);
            $canReject = $user->can('reject', $this->resource);
        }

        $driversList = [];
        $vehiclesList = [];

        // 1. Check operational trips (accepted/active trips)
        $trips = \App\Models\OperationalTrip::where('request_id', $this->id)->with(['driver', 'vehicle'])->get();
        foreach ($trips as $trip) {
            if ($trip->driver) {
                $driversList[] = $trip->driver->name;
            }
            if ($trip->vehicle) {
                $vehiclesList[] = $trip->vehicle->name . ' (' . $trip->vehicle->plate_number . ')';
            }
        }

        // 2. Check pending assignments if no operational trips
        if (empty($driversList)) {
            $assignments = \App\Models\Assignment::where('request_id', $this->id)->with(['driver'])->get();
            foreach ($assignments as $asg) {
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

        // Compute all_drivers_approved flag
        $assignments = \App\Models\Assignment::where('request_id', $this->id)->get();
        $allDriversApproved = true;
        if ($assignments->isEmpty()) {
            $allDriversApproved = false;
        } else {
            foreach ($assignments as $asg) {
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
            'operational_trips' => \App\Models\OperationalTrip::where('request_id', $this->id)
                ->with(['driver', 'vehicle'])
                ->get()
                ->map(fn($t) => [
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
            ] : null,
            'vehicle' => $this->vehicle ? [
                'id' => $this->vehicle->id,
                'name' => $this->vehicle->name,
                'plate_number' => $this->vehicle->plate_number,
                'type' => $this->vehicle->type,
            ] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}