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
                $filename = basename($value);
                $fullPath = storage_path('app/public/settings/' . $filename);
                $value = file_exists($fullPath) ? url('api/assets/settings/' . $filename) : null;
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
        $logoUrl = null;
        if ($logo) {
            $filename = basename($logo);
            $fullPath = storage_path('app/public/settings/' . $filename);
            if (file_exists($fullPath)) {
                $logoUrl = url('api/assets/settings/' . $filename);
            }
        }

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
    public function serveLogo($filename)
    {
        // Security Hardening: Use basename to prevent Directory Traversal attacks
        $safeFilename = basename($filename);
        $path = storage_path('app/public/settings/' . $safeFilename);
        if (!file_exists($path)) {
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="%2300236f"><rect width="24" height="24" rx="6" fill="%2300236f"/><path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.85 7h10.29l1.04 3H5.81l1.04-3zM19 17H5v-4h14v4z" fill="%23ffffff"/><circle cx="7.5" cy="15.5" r="1.5" fill="%23ffffff"/><circle cx="16.5" cy="15.5" r="1.5" fill="%23ffffff"/></svg>';
            return response($svg, 200, [
                'Content-Type' => 'image/svg+xml',
                'Cache-Control' => 'no-cache',
            ]);
        }

        return response()->file($path, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Pragma' => 'cache',
            'Expires' => gmdate('D, d M Y H:i:s \G\M\T', time() + 31536000),
        ]);
    }
}
