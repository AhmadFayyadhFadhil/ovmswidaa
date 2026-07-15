<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user) return false;
        
        $roles = $user->roles()->pluck('name')->map(fn($r) => strtolower($r))->toArray();
        
        return in_array('admin', $roles, true) || 
               in_array('ga', $roles, true) || 
               $user->isHrGaHead();
    }

    public function rules(): array
    {
        return [
            'request_id'         => 'required|exists:requests,id',
            'is_external'        => 'nullable|boolean',
            'third_party_cost'   => 'nullable|numeric|min:0',
            'estimated_duration' => 'nullable|integer|min:1',
            'priority'           => 'nullable|string|in:Normal,Urgent,Critical',
            'driver_id'          => 'required_without:is_external|nullable|exists:users,id',
            'vehicle_id'         => 'required_without:is_external|nullable|exists:vehicles,id',
            'driver_ids'         => 'nullable|array',
            'driver_ids.*'       => 'exists:users,id',
            'vehicle_ids'        => 'nullable|array',
            'vehicle_ids.*'      => 'exists:vehicles,id',
            'notes'              => 'nullable|string|max:1000',
            'external_fleet_info'=> 'nullable|string|max:1000',
            'external_photo'     => 'nullable|file|image|max:10240',
            'external_trip_type' => 'nullable|string|in:round_trip,one_way',
            'external_departure_cost' => 'nullable|numeric|min:0',
            'external_return_cost'    => 'nullable|numeric|min:0',
            'external_return_fleet_info' => 'nullable|string|max:1000',
            'external_return_photo'      => 'nullable|file|image|max:10240',
            'external_driver_name'       => 'nullable|string|max:255',
            'external_license_plate'      => 'nullable|string|max:255',
            'external_return_driver_name' => 'nullable|string|max:255',
            'external_return_license_plate'=> 'nullable|string|max:255',
            'external_provider'           => 'nullable|string|max:255',

            // Second external vehicle rules:
            'external_driver_name_2'       => 'nullable|string|max:255',
            'external_license_plate_2'      => 'nullable|string|max:255',
            'external_fleet_info_2'        => 'nullable|string|max:1000',
            'external_photo_2'             => 'nullable|file|image|max:10240',
            'external_departure_cost_2'    => 'nullable|numeric|min:0',
            'external_return_cost_2'       => 'nullable|numeric|min:0',
            'external_return_driver_name_2' => 'nullable|string|max:255',
            'external_return_license_plate_2'=> 'nullable|string|max:255',
            'external_return_fleet_info_2' => 'nullable|string|max:1000',
            'external_return_photo_2'      => 'nullable|file|image|max:10240',
            'third_party_cost_2'           => 'nullable|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'request_id.required'  => 'Request ID harus diisi',
            'request_id.exists'    => 'Request tidak ditemukan',
            'driver_id.required'   => 'Driver harus dipilih',
            'driver_id.exists'     => 'Driver tidak ditemukan',
            'notes.max'            => 'Notes maksimal 1000 karakter',
        ];
    }
}