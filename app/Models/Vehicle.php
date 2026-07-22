<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Vehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'plate_number',
        'type',
        'capacity',
        'status',
        'odometer',
        'photo',
        'stnk_photo',
        'last_maintained',
    ];

    protected $casts = [
        'last_maintained' => 'datetime',
    ];

    // Relationships
    public function requests()
    {
        return $this->hasMany(Request::class);
    }

    public function operationalTrips()
    {
        return $this->hasMany(OperationalTrip::class);
    }

    // Scopes
    public function scopeAvailable($query)
    {
        return $query->where('status', 'Available');
    }

    public function scopeInUse($query)
    {
        return $query->where('status', 'In Use');
    }

    public function scopeMaintenance($query)
    {
        return $query->where('status', 'Maintenance');
    }
}
