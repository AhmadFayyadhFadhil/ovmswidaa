<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Enums\RequestStatus;
use App\Enums\RequestPriority;

class Request extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'department_id',
        'destination_city',
        'destination_place',
        'purpose',
        'start_time',
        'end_time',
        'passenger_count',
        'priority',
        'status',
        'notes',
        'itinerary_file_path',
        'driver_id',
        'vehicle_id',
        'assigned_by',
        'assigned_at',
        'started_at',
        'completed_at',
        'rejected_reason',
        'driver_response_status',
        'estimated_duration',
        'is_external',
        'third_party_cost',
        'qr_code_token',
        'security_checked_out_at',
        'security_checked_in_at',
        'security_checkout_by',
        'security_checkin_by',
        'security_checkout_notes',
        'security_checkin_notes',
        'external_fleet_info',
        'external_photo_path',
        'external_trip_type',
        'external_departure_cost',
        'external_return_cost',
        'external_return_fleet_info',
        'external_return_photo_path',
        'external_driver_name',
        'external_license_plate',
        'external_return_driver_name',
        'external_return_license_plate',
        'external_provider',
        // Second external vehicle:
        'external_driver_name_2',
        'external_license_plate_2',
        'external_fleet_info_2',
        'external_photo_path_2',
        'external_departure_cost_2',
        'external_return_cost_2',
        'external_return_driver_name_2',
        'external_return_license_plate_2',
        'external_return_fleet_info_2',
        'external_return_photo_path_2',
        'third_party_cost_2',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'status' => RequestStatus::class,
        'priority' => RequestPriority::class,
        'is_external' => 'boolean',
        'security_checked_out_at' => 'datetime',
        'security_checked_in_at' => 'datetime',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function approvals()
    {
        return $this->hasMany(RequestApproval::class);
    }

    public function assignments()
    {
        return $this->hasMany(Assignment::class);
    }

    public function itineraries()
    {
        return $this->hasMany(RequestItinerary::class)->orderBy('date', 'asc');
    }

    public function operationalTrip()
    {
        return $this->hasOne(OperationalTrip::class);
    }

    public function operationalTrips()
    {
        return $this->hasMany(OperationalTrip::class);
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function passengers()
    {
        return $this->hasMany(Passenger::class);
    }

    // Status Sync helper for multi-day requests
    public function syncStatus()
    {
        if ($this->relationLoaded('itineraries') && $this->itineraries->isNotEmpty()) {
            $allCount = $this->itineraries->count();
            $doneCount = $this->itineraries->where('status', 'completed')->count();

            if ($doneCount >= $allCount) {
                if ($this->status !== RequestStatus::COMPLETED) {
                    $this->update([
                        'status' => RequestStatus::COMPLETED,
                        'completed_at' => $this->completed_at ?? now(),
                    ]);
                    $this->status = RequestStatus::COMPLETED;
                }
            } else {
                if ($this->status === RequestStatus::COMPLETED) {
                    // Check if any itinerary is on_going or completed
                    $anyStarted = $this->itineraries->contains(function ($it) {
                        return in_array($it->status, ['on_going', 'completed'], true) ||
                               $it->morning_status === 'on_going' ||
                               $it->afternoon_status === 'on_going';
                    });
                    $newStatus = $anyStarted ? RequestStatus::ON_GOING : RequestStatus::DRIVER_ASSIGNED;
                    $this->update([
                        'status' => $newStatus,
                        'completed_at' => null,
                    ]);
                    $this->status = $newStatus;
                }
            }
        }
    }

    // Overtime Calculations
    public function getOvertimeMinutesAttribute(): int
    {
        $actualReturn = $this->security_checked_in_at ?? $this->completed_at;
        if (!$actualReturn) return 0;

        $returnCarbon = \Carbon\Carbon::parse($actualReturn);
        $shiftEndStr = $this->driver?->availability_end ?? '16:30:00';
        
        $shiftEndCarbon = $returnCarbon->copy()->setTimeFromTimeString($shiftEndStr);

        if ($returnCarbon->greaterThan($shiftEndCarbon)) {
            return (int) $shiftEndCarbon->diffInMinutes($returnCarbon);
        }

        return 0;
    }

    public function getIsOvertimeAttribute(): bool
    {
        return $this->getOvertimeMinutesAttribute() > 0;
    }

    public function getOvertimeFormattedAttribute(): ?string
    {
        $minutes = $this->getOvertimeMinutesAttribute();
        if ($minutes <= 0) return null;

        $hours = floor($minutes / 60);
        $remainingMin = $minutes % 60;

        if ($hours > 0 && $remainingMin > 0) {
            return "{$hours} Jam {$remainingMin} Menit";
        } elseif ($hours > 0) {
            return "{$hours} Jam";
        } else {
            return "{$remainingMin} Menit";
        }
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', RequestStatus::SUBMITTED);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', RequestStatus::COMPLETED);
    }
}
