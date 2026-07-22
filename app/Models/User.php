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
        'nik',
        'name',
        'email',
        'password',
        'department_id',
        'availability_status',
        'rank',
        'is_department_head',
        'sim_a_photo',
        'phone',
        'location',
        'avatar',
        'is_active',
        'can_request',
        'availability_start',
        'availability_end',
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
            'is_active' => 'boolean',
            'can_request' => 'boolean',
        ];
    }

    // Relationships
    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

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
        $names = Department::pluck('name')->toArray();
        if (empty($names)) {
            return [
                'Information and Technology',
                'Finance and Accounting',
                'HRD & GA',
                'Supply Chain',
                'Technical and Development',
                'Quality Assurance',
                'Quality Control',
                'Production',
                'Regulatory Affairs & PV',
                'Legal & Compliance',
                'Plant Management',
            ];
        }
        return $names;
    }

    public function departmentGroup(): array
    {
        return $this->department_id ? [$this->department_id] : [];
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
        return $this->department && $this->department->name === 'HRD & GA';
    }

    public function isHrGaHead(): bool
    {
        return $this->isHrGaDepartment() && $this->is_department_head && $this->hasRoleDirect(['Approver', 'GA']);
    }

    public function getAvailabilityStatusAttribute($value)
    {
        return $value ?? 'available';
    }
}
