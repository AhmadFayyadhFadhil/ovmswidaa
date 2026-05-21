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
            'request' => [
                'id' => $this->request?->id,
                'purpose' => $this->request?->purpose,
                'start_time' => $this->request?->start_time,
                'end_time' => $this->request?->end_time,
            ],
            'vehicle' => [
                'id' => $this->vehicle?->id,
                'name' => $this->vehicle?->name,
                'plate_number' => $this->vehicle?->plate_number,
                'type' => $this->vehicle?->type,
            ],
            'driver' => [
                'id' => $this->driver?->id,
                'name' => $this->driver?->name,
                'email' => $this->driver?->email,
            ],
            'assigned_at' => $this->assigned_at,
            'returned_at' => $this->returned_at,
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
