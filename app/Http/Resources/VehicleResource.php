<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'plate_number' => $this->plate_number,
            'type' => $this->type,
            'capacity' => $this->capacity,
            'odometer' => $this->odometer,
            'status' => $this->status,
            'photo_url' => $this->photo ? url('storage/' . $this->photo) : null,
            'last_maintained' => $this->last_maintained,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
