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
    ];

    /**
     * Get the request that this passenger belongs to.
     */
    public function request()
    {
        return $this->belongsTo(Request::class);
    }
}
