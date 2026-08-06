<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Enums\RequestPriority;
use Illuminate\Validation\Rules\Enum;

class StoreRequest extends FormRequest
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->passengers)) {
            $decoded = json_decode($this->passengers, true);
            if (is_array($decoded)) {
                $this->merge(['passengers' => $decoded]);
            }
        }

        if (is_string($this->itineraries)) {
            $decoded = json_decode($this->itineraries, true);
            if (is_array($decoded)) {
                $this->merge(['itineraries' => $decoded]);
            }
        }

        if (!$this->hasFile('itinerary_file')) {
            $this->offsetUnset('itinerary_file');
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user) return false;
        return !isset($user->can_request) || (bool)$user->can_request;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();
        $isGA = $user->hasRoleDirect(['GA', 'ga', 'Admin', 'admin']) || $user->isHrGaHead();
        $minLeadTime = (int)\App\Models\Setting::getValue('min_lead_time_hours', 24);
        
        if ($isGA) {
            $startTimeRule = 'required|date';
        } else {
            $minTime = now()->addHours($minLeadTime);
            $startTimeRule = [
                'required',
                'date',
                function ($attribute, $value, $fail) use ($minTime) {
                    try {
                        $dateTime = \Carbon\Carbon::parse($value);
                        if ($dateTime->lt($minTime)) {
                            $formattedMin = $minTime->format('d/m/Y H:i');
                            $fail("Waktu keberangkatan kurang dari 24 jam (keberangkatan tercepat: {$formattedMin} WIB). Silakan menghubungi GA KOORDINATOR (Bu Melodi) untuk pengajuan urgent.");
                        }
                    } catch (\Throwable $e) {
                        $fail("Format waktu keberangkatan tidak valid.");
                    }
                }
            ];
        }

        return [
            'department_id' => 'nullable',
            'destination_city' => 'required|string|max:255',
            'destination_place' => 'required|string|max:255',
            'purpose' => 'required|string|max:255',
            'start_time' => $startTimeRule,
            'end_time' => 'nullable|date|after:start_time',
            'passenger_count' => 'required|integer|min:1|max:12',
            'priority' => 'required|in:Normal,Urgent,Critical',
            'notes' => 'nullable|string|max:1000',
            // Passengers validation (optional - can be provided or omitted)
            'passengers' => 'nullable|array|min:0',
            'passengers.*.name' => 'required_with:passengers|string|max:255',
            'passengers.*.department_id' => 'nullable',
            'passengers.*.user_id' => 'nullable',
            'passengers.*.is_pic' => 'nullable|boolean',
            // Optional driver/vehicle for GA urgent request
            'driver_id' => 'nullable|integer|exists:users,id',
            'vehicle_id' => 'nullable|integer|exists:vehicles,id',
            // Third party / external fleet fields
            'is_external' => 'nullable|boolean',
            'third_party_cost' => 'nullable|numeric',
            'external_fleet_info' => 'nullable|string|max:255',
            'external_driver_name' => 'nullable|string|max:255',
            'external_license_plate' => 'nullable|string|max:255',
            'external_trip_type' => 'nullable|string|in:round_trip,one_way',
            'external_departure_cost' => 'nullable|numeric',
            'external_return_cost' => 'nullable|numeric',
            // Multi-Day Itinerary fields
            'itinerary_file' => 'nullable|file|max:10240',
            'itineraries' => 'nullable|array',
            'itineraries.*.date' => 'required_with:itineraries|date',
            'itineraries.*.morning_time' => 'nullable|string',
            'itineraries.*.morning_destination' => 'nullable|string',
            'itineraries.*.afternoon_time' => 'nullable|string',
            'itineraries.*.afternoon_destination' => 'nullable|string',
            'itineraries.*.passengers_notes' => 'nullable|string',
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        $firstError = $validator->errors()->first();
        \Log::error('StoreRequest validation failed: ' . $firstError, [
            'errors' => $validator->errors()->all()
        ]);

        throw new \Illuminate\Http\Exceptions\HttpResponseException(
            response()->json([
                'status'  => 'error',
                'message' => $firstError ?: 'Data pengajuan belum sesuai format.',
                'errors'  => $validator->errors(),
            ], 422)
        );
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
            'start_time.after_or_equal' => 'Permintaan kendaraan harus diajukan maksimal H-1 (mulai besok atau seterusnya)',
            'passenger_count.required' => 'Jumlah penumpang harus diisi',
            'passenger_count.min' => 'Jumlah penumpang minimal 1',
            'passenger_count.max' => 'Jumlah penumpang maksimal adalah 12 orang',
            'priority.required' => 'Prioritas harus dipilih',
            'passengers.required' => 'Data penumpang harus diisi',
            'passengers.min' => 'Minimal ada 1 penumpang',
            'passengers.*.name.required' => 'Nama penumpang harus diisi',
        ];
    }
}
