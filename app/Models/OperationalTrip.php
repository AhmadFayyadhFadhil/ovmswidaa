<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OperationalTrip extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'driver_id',
        'vehicle_id',
        'start_datetime',
        'end_datetime',
        'status',
        'security_checked_out_at',
        'security_checked_in_at',
        'security_checkout_by',
        'security_checkin_by',
        'security_checkout_notes',
        'security_checkin_notes',
    ];

    protected $casts = [
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'security_checked_out_at' => 'datetime',
        'security_checked_in_at' => 'datetime',
    ];

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
        return $this->belongsTo(Vehicle::class);
    }
}
