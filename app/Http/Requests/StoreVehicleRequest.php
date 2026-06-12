<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->hasRoleDirect('Admin') || $this->user()->isHrGaHead();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'plate_number' => 'required|string|unique:vehicles,plate_number|max:20',
            'type' => 'required|string|max:100',
            'capacity' => 'nullable|integer|min:1',
            'odometer' => 'nullable|integer|min:0',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:1024',
            'status' => 'sometimes|in:Available,In Use,Maintenance,Retired',
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
            'odometer.integer' => 'Odometer harus berupa angka',
            'odometer.min' => 'Odometer tidak boleh kurang dari 0',
            'photo.image' => 'Foto kendaraan harus berupa file gambar',
            'photo.mimes' => 'Foto kendaraan harus berformat jpeg, png, jpg, atau webp',
            'photo.max' => 'Ukuran foto kendaraan maksimal 1MB',
        ];
    }
}