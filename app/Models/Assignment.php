<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Assignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'driver_id',
        'assigned_by',
        'assigned_at',
        'notes',
        'reject_reason',
        'status', // pending_driver, accepted, rejected
        'start_photo',
        'end_photo',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
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

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
