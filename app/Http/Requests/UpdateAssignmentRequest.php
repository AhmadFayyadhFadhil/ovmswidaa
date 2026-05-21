<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAssignmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // GA, Admin, or the assigned driver can update
        $assignment = $this->route('assignment');
        return $this->user()->hasAnyRole(['Admin', 'GA']) || $this->user()->id === $assignment->driver_id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $assignment = $this->route('assignment');
        
        return [
            'returned_at' => 'required|date_format:Y-m-d H:i:s|after:' . $assignment->assigned_at,
            'notes' => 'nullable|string|max:1000',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     */
    public function messages(): array
    {
        return [
            'returned_at.required' => 'Waktu kembali harus diisi',
            'returned_at.date_format' => 'Format waktu kembali: YYYY-MM-DD HH:MM:SS',
            'returned_at.after' => 'Waktu kembali harus setelah waktu assign',
            'notes.max' => 'Notes maksimal 1000 karakter',
        ];
    }
}
