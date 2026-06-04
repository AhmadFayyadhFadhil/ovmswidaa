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
            'can_approve'       => $canApprove,
            'can_reject'        => $canReject,
            'next_approval_role' => match ($this->status) {
                RequestStatus::SUBMITTED => 'dept_head',
                RequestStatus::APPROVED_DEPARTMENT => 'hrd_head',
                default => null,
            },
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