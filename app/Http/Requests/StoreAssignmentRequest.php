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
            'request_id'  => 'required|exists:requests,id',
            'driver_id'   => 'required|exists:users,id',
            'notes'       => 'nullable|string|max:1000',
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