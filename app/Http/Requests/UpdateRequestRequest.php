<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // User can only update their own requests or be an admin
        $vehicleRequest = $this->route('vehicleRequest');
        return $this->user()->id === $vehicleRequest->user_id || $this->user()->hasRole('Admin');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'purpose' => 'sometimes|required|string|max:255',
            'start_time' => 'sometimes|required|date_format:Y-m-d H:i:s|after:now',
            'end_time' => 'sometimes|required|date_format:Y-m-d H:i:s|after:start_time',
            'vehicle_id' => 'sometimes|nullable|exists:vehicles,id',
            'notes' => 'sometimes|nullable|string|max:1000',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     */
    public function messages(): array
    {
        return [
            'purpose.required' => 'Tujuan peminjaman harus diisi',
            'purpose.max' => 'Tujuan peminjaman maksimal 255 karakter',
            'start_time.required' => 'Waktu mulai harus diisi',
            'start_time.date_format' => 'Format waktu mulai: YYYY-MM-DD HH:MM:SS',
            'start_time.after' => 'Waktu mulai harus di masa depan',
            'end_time.required' => 'Waktu berakhir harus diisi',
            'end_time.date_format' => 'Format waktu berakhir: YYYY-MM-DD HH:MM:SS',
            'end_time.after' => 'Waktu berakhir harus setelah waktu mulai',
            'vehicle_id.exists' => 'Kendaraan tidak ditemukan',
        ];
    }
}
