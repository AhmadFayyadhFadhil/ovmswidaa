<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Enums\RequestPriority;
use Illuminate\Validation\Rules\Enum;

class UpdateRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // User can only update their own requests or be an admin
        $vehicleRequest = $this->route('vehicleRequest') ?? $this->route('request'); // fallback
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
            'department_id' => 'sometimes|nullable|string|max:255',
            'destination_city' => 'sometimes|required|string|max:255',
            'destination_place' => 'sometimes|required|string|max:255',
            'purpose' => 'sometimes|required|string|max:255',
            'start_time' => 'sometimes|required|date|after:now',
            'end_time' => 'sometimes|nullable|date|after:start_time',
            'passenger_count' => 'sometimes|required|integer|min:1',
            'priority' => ['sometimes', 'required', new Enum(RequestPriority::class)],
            'notes' => 'sometimes|nullable|string|max:1000',
            // Passengers validation
            'passengers' => 'sometimes|array|min:1',
            'passengers.*.name' => 'required|string|max:255',
            'passengers.*.department_id' => 'nullable|string|max:255',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     */
    public function messages(): array
    {
        return [
            'destination_city.required' => 'Kota tujuan harus diisi',
            'destination_place.required' => 'Tempat tujuan harus diisi',
            'purpose.required' => 'Tujuan/Keperluan harus diisi',
            'start_time.required' => 'Waktu berangkat harus diisi',
            'start_time.after' => 'Waktu berangkat harus di masa depan',
            'passenger_count.required' => 'Jumlah penumpang harus diisi',
            'passenger_count.min' => 'Jumlah penumpang minimal 1',
            'priority.required' => 'Prioritas harus dipilih',
            'passengers.required' => 'Data penumpang harus diisi',
            'passengers.min' => 'Minimal ada 1 penumpang',
            'passengers.*.name.required' => 'Nama penumpang harus diisi',
        ];
    }
}
