<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\Request as VehicleRequest;
use App\Models\Assignment;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Facades\Hash;

class OVMSApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed roles and permissions
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_user_can_register_and_login()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'John Doe',
            'email' => 'john@ovms.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'name', 'email', 'roles'],
                    'token',
                    'token_type'
                ]
            ]);

        $response = $this->postJson('/api/login', [
            'email' => 'john@ovms.test',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_employee_can_create_request_with_null_vehicle()
    {
        $employee = User::factory()->create();
        $employee->assignRole('Employee');

        $response = $this->actingAs($employee)
            ->postJson('/api/requests', [
                'purpose' => 'Meeting client',
                'start_time' => now()->addDay()->format('Y-m-d H:i:s'),
                'end_time' => now()->addDay()->addHours(2)->format('Y-m-d H:i:s'),
                'vehicle_id' => null,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.vehicle', null);
    }

    public function test_admin_can_approve_request()
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $employee = User::factory()->create();
        $employee->assignRole('Employee');

        $vehicleRequest = VehicleRequest::create([
            'user_id' => $employee->id,
            'purpose' => 'Delivery',
            'start_time' => now()->addDay(),
            'end_time' => now()->addDay()->addHours(4),
            'status' => 'Pending',
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/api/requests/{$vehicleRequest->id}/approve");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'Approved');
    }

    public function test_assign_vehicle_validates_driver_role()
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $employee = User::factory()->create();
        $employee->assignRole('Employee');

        $notADriver = User::factory()->create();
        $notADriver->assignRole('Employee'); // Not a Driver!

        $vehicle = Vehicle::create([
            'name' => 'Toyota Avanza',
            'plate_number' => 'B 1234 ABC',
            'type' => 'Car',
            'capacity' => 7,
            'status' => 'Available',
        ]);

        $vehicleRequest = VehicleRequest::create([
            'user_id' => $employee->id,
            'vehicle_id' => $vehicle->id,
            'purpose' => 'Delivery',
            'start_time' => now()->addDay(),
            'end_time' => now()->addDay()->addHours(4),
            'status' => 'Approved', // Pre-approved
        ]);

        // Attempting to assign with non-driver user should fail
        $response = $this->actingAs($admin)
            ->postJson('/api/assignments', [
                'request_id' => $vehicleRequest->id,
                'vehicle_id' => $vehicle->id,
                'driver_id' => $notADriver->id,
                'assigned_at' => now()->format('Y-m-d H:i:s'),
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'User yang dipilih bukan merupakan Driver');

        // Assign with real driver should succeed
        $driver = User::factory()->create();
        $driver->assignRole('Driver');

        $response = $this->actingAs($admin)
            ->postJson('/api/assignments', [
                'request_id' => $vehicleRequest->id,
                'vehicle_id' => $vehicle->id,
                'driver_id' => $driver->id,
                'assigned_at' => now()->format('Y-m-d H:i:s'),
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success');
    }

    public function test_cannot_delete_vehicle_with_active_assignments()
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $employee = User::factory()->create();
        $employee->assignRole('Employee');

        $driver = User::factory()->create();
        $driver->assignRole('Driver');

        $vehicle = Vehicle::create([
            'name' => 'Toyota Avanza',
            'plate_number' => 'B 1234 ABC',
            'type' => 'Car',
            'capacity' => 7,
            'status' => 'Available',
        ]);

        $vehicleRequest = VehicleRequest::create([
            'user_id' => $employee->id,
            'vehicle_id' => $vehicle->id,
            'purpose' => 'Delivery',
            'start_time' => now()->addDay(),
            'end_time' => now()->addDay()->addHours(4),
            'status' => 'Approved',
        ]);

        Assignment::create([
            'request_id' => $vehicleRequest->id,
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
            'assigned_at' => now(),
            'status' => 'Active',
        ]);

        // Attempting to delete the vehicle should return 422 error
        $response = $this->actingAs($admin)
            ->deleteJson("/api/vehicles/{$vehicle->id}");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Tidak dapat menghapus kendaraan yang memiliki riwayat penugasan');
    }
}
