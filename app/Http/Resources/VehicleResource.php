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
        $rawStatus = $this->status;
        $statusLabel = 'Tersedia';
        $s = strtolower(trim((string)$rawStatus));
        if ($s === 'available' || $s === 'tersedia') {
            $statusLabel = 'Tersedia';
        } elseif (in_array($s, ['on trip', 'in use', 'in transit', 'on_going', 'dipakai'])) {
            $statusLabel = 'Sedang Berjalan';
        } elseif (in_array($s, ['maintenance', 'servis', 'perbaikan'])) {
            $statusLabel = 'Dalam Perbaikan';
        } elseif (in_array($s, ['decommissioned', 'retired', 'inactive', 'tidak aktif'])) {
            $statusLabel = 'Tidak Aktif';
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'plate_number' => $this->plate_number,
            'type' => $this->type,
            'capacity' => $this->capacity,
            'odometer' => $this->odometer,
            'status' => $this->status,
            'status_label' => $statusLabel,
            'photo_url' => $this->photo ? url('storage/' . $this->photo) : null,
            'stnk_photo_url' => $this->stnk_photo ? url('storage/' . $this->stnk_photo) : null,
            'last_maintained' => $this->last_maintained,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
