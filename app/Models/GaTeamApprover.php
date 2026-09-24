<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GaTeamApprover extends Model
{
    use HasFactory;

    protected $table = 'ga_team_approvers';

    protected $fillable = [
        'name',
        'position',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}
