<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles, HasApiTokens;

    /**
     * Guard name untuk Spatie Permission.
     * Wajib di-set ke 'sanctum' agar hasRole/hasAnyRole
     * bisa mengenali role saat request lewat API (auth:sanctum).
     */
    protected string $guard_name = 'sanctum';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'department_id',
        'availability_status',
        'rank',
        'is_department_head',
        'sim_a_photo',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_department_head' => 'boolean',
        ];
    }

    // Relationships
    public function requests()
    {
        return $this->hasMany(Request::class);
    }

    public function approvedRequests()
    {
        return $this->hasMany(Request::class, 'approver_id');
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }

    public function assignments()
    {
        return $this->hasMany(Assignment::class, 'driver_id');
    }

    /**
     * Kembalikan daftar departemen valid yang digunakan di front/back.
     */
    public static function validDepartments(): array
    {
        return [
            'IT', 'FA', 'HR&GA', 'QC', 'QA',
            'HRD', 'GA', 'TECHNICAL', 'ENGINEERING', 'SUPPLY CHAIN', 'HSE', 'PRODUKSI',
            'HRD&GA',
        ];
    }

    public function departmentGroup(): array
    {
        return match ($this->department_id) {
            'HR&GA' => ['HR&GA', 'HRD', 'GA'],
            'HRD&GA' => ['HRD&GA'],
            default => [$this->department_id],
        };
    }

    public function hasRoleDirect(string|array $roles): bool
    {
        $roles = is_array($roles) ? $roles : [$roles];
        $lowercaseRoles = array_map('strtolower', $roles);
        return $this->roles()
            ->whereIn(\Illuminate\Support\Facades\DB::raw('LOWER(name)'), $lowercaseRoles)
            ->exists();
    }

    public function hasPermissionDirect(string $permission): bool
    {
        $hasDirect = $this->permissions()
            ->where('name', $permission)
            ->exists();

        if ($hasDirect) {
            return true;
        }

        return $this->roles()
            ->whereHas('permissions', function ($q) use ($permission) {
                $q->where('name', $permission);
            })
            ->exists();
    }

    public function isHrGaDepartment(): bool
    {
        return in_array($this->department_id, ['HR&GA', 'HRD&GA'], true);
    }

    public function isHrGaHead(): bool
    {
        return $this->isHrGaDepartment() && $this->is_department_head && $this->hasRoleDirect(['Approver', 'GA']);
    }
}
