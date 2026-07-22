<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class SettingController extends Controller
{
    /**
     * Check if current user is Admin.
     */
    private function checkAdmin(): bool
    {
        $user = Auth::user();
        return $user && $user->hasRoleDirect('Admin');
    }

    /**
     * Get all settings mapped to camelCase.
     */
    public function index(): JsonResponse
    {
        if (!$this->checkAdmin()) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $settings = Setting::all();
        $formatted = [];
        
        $keyMap = [
            'system_name' => 'systemName',
            'timezone' => 'timezone',
            'date_format' => 'dateFormat',
            'system_language' => 'systemLanguage',
            'company_name' => 'companyName',
            'support_email' => 'supportEmail',
            'hq_address' => 'hqAddress',
            'email_alerts' => 'emailAlerts',
            'sms_alerts' => 'smsAlerts',
            'push_notifs' => 'pushNotifs',
            'digest_mode' => 'digestMode',
            'company_logo' => 'companyLogo',
            'min_lead_time_hours' => 'minLeadTimeHours',
        ];

        foreach ($settings as $setting) {
            $frontendKey = $keyMap[$setting->key] ?? $setting->key;
            $value = $setting->value;
            if ($setting->key === 'company_logo' && $value) {
                // Prepend custom caching assets route URL
                $value = url('api/assets/settings/' . basename($value));
            } elseif ($setting->type === 'boolean') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
            $formatted[$frontendKey] = $value;
        }

        $dbStatus = 'Connected';
        try {
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            $dbStatus = 'Disconnected';
        }

        $formatted['stats'] = [
            'total_users' => User::count(),
            'total_vehicles' => Vehicle::count(),
            'active_sessions' => DB::table('personal_access_tokens')->count(),
            'db_status' => $dbStatus,
            'total_audit_logs' => AuditLog::count(),
            'timezone' => Setting::getValue('timezone', 'Asia/Jakarta'),
        ];

        return response()->json([
            'status' => 'success',
            'data' => $formatted
        ]);
    }

    /**
     * Update settings.
     */
    public function update(Request $request): JsonResponse
    {
        if (!$this->checkAdmin()) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $data = $request->all();
        
        $keyMap = [
            'systemName' => 'system_name',
            'timezone' => 'timezone',
            'dateFormat' => 'date_format',
            'systemLanguage' => 'system_language',
            'companyName' => 'company_name',
            'supportEmail' => 'support_email',
            'hqAddress' => 'hq_address',
            'emailAlerts' => 'email_alerts',
            'smsAlerts' => 'sms_alerts',
            'pushNotifs' => 'push_notifs',
            'digestMode' => 'digest_mode',
            'companyLogo' => 'company_logo',
            'minLeadTimeHours' => 'min_lead_time_hours',
        ];

        DB::transaction(function () use ($data, $keyMap) {
            foreach ($data as $frontendKey => $value) {
                $dbKey = $keyMap[$frontendKey] ?? null;
                if ($dbKey) {
                    $setting = Setting::firstOrCreate(
                        ['key' => $dbKey],
                        [
                            'type' => in_array($dbKey, ['email_alerts', 'sms_alerts', 'push_notifs', 'digest_mode']) ? 'boolean' : 'string',
                            'group' => 'general'
                        ]
                    );
                    if ($setting) {
                        // Ignore full URL for company logo if it was not changed (starts with http)
                        if ($dbKey === 'company_logo' && str_starts_with($value, 'http')) {
                            continue;
                        }
                        
                        if ($setting->type === 'boolean') {
                            $setting->value = $value ? '1' : '0';
                        } else {
                            $setting->value = $value;
                        }
                        $setting->save();
                    }
                }
            }
        });

        // Clear branding cache
        Cache::forget('ovms_branding_config');

        return $this->index();
    }

    /**
     * Upload company logo file.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        if (!$this->checkAdmin()) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            // Store the file in public/settings disk
            $path = $file->store('settings', 'public');
            
            // Save path to setting DB
            $setting = Setting::firstOrCreate(['key' => 'company_logo'], [
                'type' => 'string',
                'group' => 'company'
            ]);
            $setting->value = $path;
            $setting->save();

            // Clear branding cache
            Cache::forget('ovms_branding_config');

            return response()->json([
                'status' => 'success',
                'data' => [
                    'logo_url' => url('api/assets/settings/' . basename($path))
                ]
            ]);
        }

        return response()->json(['status' => 'error', 'message' => 'File logo tidak ditemukan.'], 400);
    }

    /**
     * Get real system statistics.
     */
    public function getStats(): JsonResponse
    {
        if (!$this->checkAdmin()) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $dbStatus = 'Connected';
        try {
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            $dbStatus = 'Disconnected';
        }

        $stats = [
            'total_users' => User::count(),
            'total_vehicles' => Vehicle::count(),
            'active_sessions' => DB::table('personal_access_tokens')->count(),
            'db_status' => $dbStatus,
            'total_audit_logs' => AuditLog::count(),
            'timezone' => Setting::getValue('timezone', 'Asia/Jakarta'),
        ];

        return response()->json([
            'status' => 'success',
            'data' => $stats
        ]);
    }

    /**
     * Purge all audit logs.
     */
    public function purgeLogs(): JsonResponse
    {
        if (!$this->checkAdmin()) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        AuditLog::query()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Semua audit log berhasil dihapus.'
        ]);
    }

    /**
     * Flush application cache.
     */
    public function flushCache(): JsonResponse
    {
        if (!$this->checkAdmin()) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        Cache::flush();

        return response()->json([
            'status' => 'success',
            'message' => 'Cache aplikasi berhasil dibersihkan.'
        ]);
    }

    /**
     * Get public statistics for the login page.
     */
    public function getPublicStats(): JsonResponse
    {
        $activeVehicles = Vehicle::count();
        $dailyRequests = \App\Models\Request::whereDate('created_at', today())->count();
        $activeDrivers = User::whereHas('roles', function($q) {
            $q->where('name', 'Driver');
        })->count();

        $systemName = Setting::getValue('system_name', 'OVMS');
        $logo = Setting::getValue('company_logo');
        $logoUrl = $logo ? url('api/assets/settings/' . basename($logo)) : null;

        return response()->json([
            'status' => 'success',
            'data' => [
                'active_vehicles' => $activeVehicles,
                'daily_requests' => $dailyRequests,
                'active_drivers' => $activeDrivers,
                'system_name' => $systemName,
                'company_logo' => $logoUrl,
            ]
        ]);
    }

    /**
     * Serve setting logo file with optimal caching headers.
     */
    public function serveLogo($filename): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        // Security Hardening: Use basename to prevent Directory Traversal attacks
        $safeFilename = basename($filename);
        $path = storage_path('app/public/settings/' . $safeFilename);
        if (!file_exists($path)) {
            abort(404);
        }

        return response()->file($path, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Pragma' => 'cache',
            'Expires' => gmdate('D, d M Y H:i:s \G\M\T', time() + 31536000),
        ]);
    }
}
