<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Setting;
use App\Models\AuditLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Facades\Cache;

class SettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed roles and permissions
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_can_retrieve_settings()
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $response = $this->actingAs($admin)
            ->getJson('/api/system-config');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'data' => [
                    'systemName',
                    'timezone',
                    'dateFormat',
                    'systemLanguage',
                    'companyName',
                    'supportEmail',
                    'hqAddress',
                    'emailAlerts',
                    'smsAlerts',
                    'pushNotifs',
                    'digestMode'
                ]
            ]);
    }

    public function test_non_admin_cannot_retrieve_settings()
    {
        $employee = User::factory()->create();
        $employee->assignRole('Employee');

        $response = $this->actingAs($employee)
            ->getJson('/api/system-config');

        $response->assertStatus(403);
    }

    public function test_admin_can_update_settings()
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $response = $this->actingAs($admin)
            ->putJson('/api/system-config', [
                'systemName' => 'New System Name',
                'companyName' => 'New Company Name',
                'emailAlerts' => false
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.systemName', 'New System Name')
            ->assertJsonPath('data.companyName', 'New Company Name')
            ->assertJsonPath('data.emailAlerts', false);

        // Verify database records
        $this->assertEquals('New System Name', Setting::getValue('system_name'));
        $this->assertEquals('New Company Name', Setting::getValue('company_name'));
        $this->assertFalse(Setting::getValue('email_alerts'));
    }

    public function test_admin_can_retrieve_system_stats()
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $response = $this->actingAs($admin)
            ->getJson('/api/system-config/stats');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'data' => [
                    'total_users',
                    'total_vehicles',
                    'active_sessions',
                    'db_status',
                    'total_audit_logs',
                    'timezone'
                ]
            ]);
    }

    public function test_admin_can_purge_audit_logs()
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        // Create a fake audit log using DB or factory (we don't have AuditLog factory, so insert directly or use save)
        $log = new AuditLog();
        $log->user_id = $admin->id;
        $log->action = 'test';
        $log->auditable_id = 1;
        $log->auditable_type = 'App\Models\User';
        $log->save();

        $this->assertEquals(1, AuditLog::count());

        $response = $this->actingAs($admin)
            ->postJson('/api/system-config/purge-logs');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertEquals(0, AuditLog::count());
    }

    public function test_admin_can_flush_cache()
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        Cache::put('test_key', 'test_value', 10);
        $this->assertEquals('test_value', Cache::get('test_key'));

        $response = $this->actingAs($admin)
            ->postJson('/api/system-config/flush-cache');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertNull(Cache::get('test_key'));
    }

    public function test_admin_can_upload_company_logo()
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $file = \Illuminate\Http\UploadedFile::fake()->create('logo.png', 100, 'image/png');

        $response = $this->actingAs($admin)
            ->postJson('/api/system-config/logo', [
                'logo' => $file,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'data' => [
                    'logo_url'
                ]
            ]);

        $this->assertEquals('settings/' . $file->hashName(), Setting::getValue('company_logo'));
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists('settings/' . $file->hashName());
    }

    public function test_anyone_can_retrieve_public_stats()
    {
        // Create 2 vehicles
        \App\Models\Vehicle::create([
            'name' => 'Avanza Test 1',
            'plate_number' => 'B 1234 CD',
            'type' => 'Car',
            'status' => 'Available',
            'capacity' => 6,
        ]);
        \App\Models\Vehicle::create([
            'name' => 'Avanza Test 2',
            'plate_number' => 'B 5678 CD',
            'type' => 'Car',
            'status' => 'Available',
            'capacity' => 6,
        ]);

        // Create 1 request today
        $user = User::factory()->create();
        \App\Models\Request::create([
            'user_id'           => $user->id,
            'destination_city'  => 'Jakarta',
            'destination_place' => 'Gedung A',
            'purpose'           => 'Meeting',
            'start_time'        => now()->addDay()->setTime(10, 0, 0),
            'end_time'          => now()->addDay()->setTime(14, 0, 0),
            'passenger_count'   => 1,
            'priority'          => 'Normal',
            'status'            => \App\Enums\RequestStatus::SUBMITTED,
            'created_at'        => now(),
        ]);

        // Create 1 driver user
        $driver = User::factory()->create();
        $driver->assignRole('Driver');

        $response = $this->getJson('/api/public-stats');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJson([
                'data' => [
                    'active_vehicles' => 2,
                    'daily_requests' => 1,
                    'active_drivers' => 1,
                    'system_name' => 'OVMS',
                    'company_logo' => null,
                ]
            ]);
    }
}
