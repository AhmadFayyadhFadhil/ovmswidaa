<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\Request as VehicleRequest;
use App\Models\Assignment;
use App\Models\OperationalTrip;
use Illuminate\Http\UploadedFile;
use App\Enums\RequestStatus;
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
            'name'                  => 'John Doe',
            'email'                 => 'john@ovms.test',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'data' => [
                    'user'  => ['id', 'name', 'email', 'roles'],
                    'token',
                    'token_type'
                ]
            ]);

        $response = $this->postJson('/api/login', [
            'email'    => 'john@ovms.test',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_user_index_can_filter_by_department_category()
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $qaUser = User::factory()->create(['department_id' => 'QA']);
        $qaUser->assignRole('Employee');

        $otherUser = User::factory()->create(['department_id' => 'IT']);
        $otherUser->assignRole('Employee');

        $response = $this->actingAs($admin)
            ->getJson('/api/users?category=QA');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $qaUser->id);
    }

    public function test_user_index_can_filter_approver_and_department_heads()
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $approver = User::factory()->create(['department_id' => 'IT', 'is_department_head' => true]);
        $approver->assignRole('Approver');

        $gaHead = User::factory()->create(['department_id' => 'HR&GA', 'is_department_head' => true]);
        $gaHead->assignRole('GA');

        $nonApprover = User::factory()->create(['department_id' => 'IT']);
        $nonApprover->assignRole('Employee');

        $response = $this->actingAs($admin)
            ->getJson('/api/users?category=Approver');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $approver->id])
            ->assertJsonFragment(['id' => $gaHead->id]);
    }

    public function test_admin_can_create_driver_with_sim_a_photo()
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $response = $this->actingAs($admin)
            ->post('/api/users', [
                'name' => 'Driver Test',
                'email' => 'driver-test@ovms.test',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'role' => 'Driver',
                'sim_a_photo' => UploadedFile::fake()->create('sim_a_photo.jpg', 100, 'image/jpeg'),
            ], ['Accept' => 'application/json']);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.roles.0', 'Driver')
            ->assertJsonPath('data.sim_a_photo_url', fn ($value) => !empty($value));
    }

    public function test_employee_can_create_request_with_null_vehicle()
    {
        $employee = User::factory()->create();
        $employee->assignRole('Employee');

        $response = $this->actingAs($employee)
            ->postJson('/api/requests', [
                'destination_city'  => 'Jakarta',
                'destination_place' => 'Sudirman',
                'purpose'           => 'Meeting client',
                'start_time'        => now()->addDay()->format('Y-m-d H:i:s'),
                'end_time'          => now()->addDay()->addHours(2)->format('Y-m-d H:i:s'),
                'passenger_count'   => 1,
                'priority'          => 'Normal',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'submitted');
    }

    public function test_dept_head_can_approve_request()
    {
        $deptHead = User::factory()->create(['department_id' => 'IT', 'is_department_head' => true]);
        $deptHead->assignRole('Approver');

        $employee = User::factory()->create(['department_id' => 'IT']);
        $employee->assignRole('Employee');

        $vehicleRequest = VehicleRequest::create([
            'user_id'           => $employee->id,
            'department_id'     => 'IT',
            'destination_city'  => 'Bandung',
            'destination_place' => 'Kantor',
            'purpose'           => 'Delivery',
            'start_time'        => now()->addDay(),
            'end_time'          => now()->addDay()->addHours(4),
            'passenger_count'   => 1,
            'priority'          => 'Normal',
            'status'            => RequestStatus::SUBMITTED,
        ]);

        $response = $this->actingAs($deptHead)
            ->postJson("/api/requests/{$vehicleRequest->id}/approve", [
                'role'  => 'dept_head',
                'notes' => 'Disetujui',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'approved_department');
    }

    public function test_assign_vehicle_validates_driver_role()
    {
        $ga = User::factory()->create();
        $ga->assignRole('GA');

        $employee = User::factory()->create();
        $employee->assignRole('Employee');

        $notADriver = User::factory()->create();
        $notADriver->assignRole('Employee'); // Not a Driver!

        $vehicleRequest = VehicleRequest::create([
            'user_id'           => $employee->id,
            'destination_city'  => 'Jakarta',
            'destination_place' => 'Gedung A',
            'purpose'           => 'Delivery',
            'start_time'        => now()->addDay(),
            'end_time'          => now()->addDay()->addHours(4),
            'passenger_count'   => 1,
            'priority'          => 'Normal',
            'status'            => RequestStatus::APPROVED_HRD, // ready to be assigned
        ]);

        // Attempting to assign with non-driver user should fail
        $response = $this->actingAs($ga)
            ->postJson('/api/assignments', [
                'request_id' => $vehicleRequest->id,
                'driver_id'  => $notADriver->id,
                'notes'      => 'Tolong antar',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'User yang dipilih bukan merupakan Driver');

        // Assign with real driver should succeed
        $driver = User::factory()->create();
        $driver->assignRole('Driver');

        $response = $this->actingAs($ga)
            ->postJson('/api/assignments', [
                'request_id' => $vehicleRequest->id,
                'driver_id'  => $driver->id,
                'notes'      => 'Tolong antar',
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
            'name'         => 'Toyota Avanza',
            'plate_number' => 'B 1234 ABC',
            'type'         => 'Car',
            'capacity'     => 7,
            'status'       => 'Available',
        ]);

        $vehicleRequest = VehicleRequest::create([
            'user_id'           => $employee->id,
            'destination_city'  => 'Surabaya',
            'destination_place' => 'Kantor Pusat',
            'purpose'           => 'Delivery',
            'start_time'        => now()->addDay(),
            'end_time'          => now()->addDay()->addHours(4),
            'passenger_count'   => 1,
            'priority'          => 'Normal',
            'status'            => RequestStatus::DRIVER_ASSIGNED,
            'driver_id'         => $driver->id,
            'vehicle_id'        => $vehicle->id,
        ]);

        // Create operational trip linking this vehicle
        OperationalTrip::create([
            'request_id'     => $vehicleRequest->id,
            'driver_id'      => $driver->id,
            'vehicle_id'     => $vehicle->id,
            'start_datetime' => now()->addDay(),
            'end_datetime'   => now()->addDay()->addHours(4),
            'status'         => 'scheduled',
        ]);

        // Attempting to delete the vehicle should return 422 error
        $response = $this->actingAs($admin)
            ->deleteJson("/api/vehicles/{$vehicle->id}");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Tidak dapat menghapus kendaraan yang memiliki riwayat penugasan');
    }

    public function test_user_can_update_profile()
    {
        $user = User::factory()->create([
            'name' => 'Original Name',
            'email' => 'original@ovms.test',
            'password' => Hash::make('secret123')
        ]);
        $user->assignRole('Employee');

        // Update profile
        $response = $this->actingAs($user)
            ->putJson('/api/profile', [
                'name' => 'Updated Name',
                'email' => 'updated@ovms.test',
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123'
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.email', 'updated@ovms.test');

        // Confirm database has updated values
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Name',
            'email' => 'updated@ovms.test'
        ]);

        // Verify password works
        $user->refresh();
        $this->assertTrue(Hash::check('newpassword123', $user->password));

        // Get profile and assert
        $response = $this->actingAs($user)
            ->getJson('/api/profile');

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.email', 'updated@ovms.test');
    }
}
