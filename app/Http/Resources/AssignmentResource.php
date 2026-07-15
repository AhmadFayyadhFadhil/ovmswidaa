<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request' => $this->request ? new RequestResource($this->request) : null,
            'vehicle' => $this->request?->operationalTrip?->vehicle ? [
                'id' => $this->request->operationalTrip->vehicle->id,
                'name' => $this->request->operationalTrip->vehicle->name,
                'plate_number' => $this->request->operationalTrip->vehicle->plate_number,
                'type' => $this->request->operationalTrip->vehicle->type,
            ] : null,
            'driver' => [
                'id' => $this->driver?->id,
                'name' => $this->driver?->name,
                'email' => $this->driver?->email,
            ],
            'assigned_by' => [
                'id' => $this->assignedBy?->id,
                'name' => $this->assignedBy?->name,
            ],
            'assigned_at' => $this->assigned_at,
            'status' => $this->status,
            'notes' => $this->notes,
            'reject_reason' => $this->reject_reason,
            'start_photo' => $this->start_photo ? url('storage/' . $this->start_photo) : null,
            'end_photo' => $this->end_photo ? url('storage/' . $this->end_photo) : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
