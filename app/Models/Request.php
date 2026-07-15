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
