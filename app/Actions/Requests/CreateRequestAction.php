<?php

namespace App\Actions\Requests;

use App\Models\Request;
use App\Models\Passenger;
use App\Enums\RequestStatus;
use Illuminate\Support\Facades\DB;

class CreateRequestAction
{
    public function execute(array $data): Request
    {
        return DB::transaction(function () use ($data) {
            $request = Request::create([
                'user_id' => auth()->id(),
                'department_id' => $data['department_id'] ?? auth()->user()->department_id,
                'destination_city' => $data['destination_city'],
                'destination_place' => $data['destination_place'],
                'purpose' => $data['purpose'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'] ?? null,
                'passenger_count' => $data['passenger_count'] ?? 1,
                'priority' => $data['priority'] ?? 'Normal',
                'status' => RequestStatus::SUBMITTED,
                'notes' => $data['notes'] ?? null,
            ]);

            // Create passengers if provided
            if (!empty($data['passengers'])) {
                foreach ($data['passengers'] as $passengerData) {
                    Passenger::create([
                        'request_id' => $request->id,
                        'name' => $passengerData['name'],
                        'department_id' => $passengerData['department_id'] ?? null,
                    ]);
                }
            }

            return $request;
        });
    }
}
