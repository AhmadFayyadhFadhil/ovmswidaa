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
        $user = $this->user();
        if (!$user) return false;
        
        $roles = $user->roles()->pluck('name')->map(fn($r) => strtolower($r))->toArray();
        return in_array('admin', $roles, true) || in_array('driver', $roles, true);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'response' => 'required|in:accepted,rejected',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'reject_reason' => 'required_if:response,rejected|string|max:1000',
            'start_photo' => 'nullable|image|mimes:jpeg,png,jpg|max:5120',
            'end_photo' => 'nullable|image|mimes:jpeg,png,jpg|max:5120',
        ];
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