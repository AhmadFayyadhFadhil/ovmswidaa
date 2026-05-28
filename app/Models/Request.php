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
        'driver_id',
        'vehicle_id',
        'assigned_by',
        'assigned_at',
        'started_at',
        'completed_at',
        'rejected_reason',
        'driver_response_status',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'status' => RequestStatus::class,
        'priority' => RequestPriority::class,
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function approvals()
    {
        return $this->hasMany(RequestApproval::class);
    }

    public function assignments()
    {
        return $this->hasMany(Assignment::class);
    }

    public function operationalTrip()
    {
        return $this->hasOne(OperationalTrip::class);
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
