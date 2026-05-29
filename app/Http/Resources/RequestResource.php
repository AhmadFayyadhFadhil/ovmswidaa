<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'department_id'     => $this->department_id,
            'destination_city'  => $this->destination_city,
            'destination_place' => $this->destination_place,
            'purpose'           => $this->purpose,
            'start_time'        => $this->start_time,
            'end_time'          => $this->end_time,
            'passenger_count'   => $this->passenger_count,
            'priority'          => $this->priority?->value,
            'status'            => $this->status?->value,
            'notes'             => $this->notes,
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
            'passengers' => $this->whenLoaded('passengers', fn() =>
                PassengerResource::collection($this->passengers)
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}