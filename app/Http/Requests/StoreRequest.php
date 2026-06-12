<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Enums\RequestPriority;
use Illuminate\Validation\Rules\Enum;

class StoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->hasRoleDirect(['Employee', 'Admin', 'GA', 'Approver', 'Driver']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'department_id' => 'nullable|string|max:255',
            'destination_city' => 'required|string|max:255',
            'destination_place' => 'required|string|max:255',
            'purpose' => 'required|string|max:255',
            'start_time' => 'required|date|after:now',
            'end_time' => 'nullable|date|after:start_time',
            'passenger_count' => 'required|integer|min:1',
            'priority' => 'required|in:Normal,Urgent,Critical',
            'notes' => 'nullable|string|max:1000',
            // Passengers validation (optional - can be provided or omitted)
            'passengers' => 'nullable|array|min:0',
            'passengers.*.name' => 'required_with:passengers|string|max:255',
            'passengers.*.department_id' => 'nullable|string|max:255',
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        \Log::error('StoreRequest validation failed:', [
            'errors' => $validator->errors()->all(),
            'input' => $this->all()
        ]);
        parent::failedValidation($validator);
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
