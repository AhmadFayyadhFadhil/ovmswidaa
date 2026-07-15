<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Passenger extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'name',
        'department_id',
        'user_id',
    ];

    /**
     * Get the request that this passenger belongs to.
     */
    public function request()
    {
        return $this->belongsTo(Request::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /**
     * Get the user account associated with this passenger.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
