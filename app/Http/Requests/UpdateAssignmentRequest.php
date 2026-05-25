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
        $assignment = $this->route('assignment');
        return $this->user()->hasAnyRole(['Admin', 'GA']) || $this->user()->id === $assignment->driver_id;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $assignment = $this->route('assignment');

        return [
            // FIX: support flexible date format (ISO 8601 included)
            'returned_at' => 'required|date|after:' . $assignment->assigned_at,
            'notes'       => 'nullable|string|max:1000',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->returned_at) {
            try {
                $this->merge([
                    'returned_at' => date('Y-m-d H:i:s', strtotime($this->returned_at)),
                ]);
            } catch (\Throwable $e) {
                // leave as-is
            }
        }
    }

    /**
     * Get the error messages for the defined validation rules.
     */
    public function messages(): array
    {
        return [
            'returned_at.required' => 'Waktu kembali harus diisi',
            'returned_at.date'     => 'Format waktu kembali tidak valid',
            'returned_at.after'    => 'Waktu kembali harus setelah waktu assign',
            'notes.max'            => 'Notes maksimal 1000 karakter',
        ];
    }
}