<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->hasRole(['Admin', 'GA']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $vehicleId = $this->route('vehicle')->id;

        return [
            'name' => 'sometimes|required|string|max:255',
            'plate_number' => 'sometimes|required|string|unique:vehicles,plate_number,' . $vehicleId . '|max:20',
            'type' => 'sometimes|required|string|max:100',
            'capacity' => 'sometimes|nullable|integer|min:1',
            'status' => 'sometimes|in:Available,In Use,Maintenance,Retired',
            'last_maintained' => 'sometimes|nullable|date',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama kendaraan harus diisi',
            'name.max' => 'Nama kendaraan maksimal 255 karakter',
            'plate_number.required' => 'Nomor plat harus diisi',
            'plate_number.unique' => 'Nomor plat sudah terdaftar',
            'plate_number.max' => 'Nomor plat maksimal 20 karakter',
            'type.required' => 'Tipe kendaraan harus diisi',
            'type.max' => 'Tipe kendaraan maksimal 100 karakter',
            'capacity.integer' => 'Kapasitas harus berupa angka',
            'capacity.min' => 'Kapasitas minimal 1',
        ];
    }
}