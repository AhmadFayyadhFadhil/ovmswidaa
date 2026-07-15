<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    public function users()
    {
        return $this->hasMany(User::class, 'department_id');
    }

    public function requests()
    {
        return $this->hasMany(Request::class, 'department_id');
    }

    public function passengers()
    {
        return $this->hasMany(Passenger::class, 'department_id');
    }
}
