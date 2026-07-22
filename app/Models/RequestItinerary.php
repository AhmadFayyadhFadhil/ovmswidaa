<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class RequestItinerary extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'date',
        'morning_time',
        'morning_destination',
        'afternoon_time',
        'afternoon_destination',
        'passengers_notes',
        'driver_id',
        'vehicle_id',
        'is_external',
        'external_driver_name',
        'external_license_plate',
        'external_fleet_info',
        'third_party_cost',
        'security_checked_out_at',
        'security_checked_in_at',
        'status',
        'morning_checked_out_at',
        'morning_checked_in_at',
        'morning_status',
        'morning_checkout_by',
        'morning_checkin_by',
        'morning_checkout_notes',
        'morning_checkin_notes',
        'afternoon_checked_out_at',
        'afternoon_checked_in_at',
        'afternoon_status',
        'afternoon_checkout_by',
        'afternoon_checkin_by',
        'afternoon_checkout_notes',
        'afternoon_checkin_notes',
    ];

    protected $casts = [
        'date' => 'date',
        'is_external' => 'boolean',
        'third_party_cost' => 'decimal:2',
        'security_checked_out_at' => 'datetime',
        'security_checked_in_at' => 'datetime',
        'morning_checked_out_at' => 'datetime',
        'morning_checked_in_at' => 'datetime',
        'afternoon_checked_out_at' => 'datetime',
        'afternoon_checked_in_at' => 'datetime',
    ];

    // Relationships
    public function request()
    {
        return $this->belongsTo(Request::class);
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    // Overtime Calculations per Itinerary Segment
    public function getOvertimeMinutesAttribute(): int
    {
        $actualReturn = $this->security_checked_in_at;
        if (!$actualReturn) return 0;

        $returnCarbon = Carbon::parse($actualReturn);
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
}
