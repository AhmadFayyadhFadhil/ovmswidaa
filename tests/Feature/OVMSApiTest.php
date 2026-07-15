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
            'nik'                   => 'NIK12345',
            'name'                  => 'John Doe',
            'email'                 => 'john@ovms.test',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'data' => [
                    'user'  => ['id', 'name', 'email', 'roles']
                ]
            ]);

        // Activate the registered user
        $user = User::where('email', 'john@ovms.test')->first();
        $user->update(['is_active' => true]);

        $response = $this->postJson('/api/login', [
            'nik'      => 'NIK12345',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_user_index_can_filter_by_department_category()
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $qaDept = \App\Models\Department::where('name', 'Quality Assurance')->first();
        $itDept = \App\Models\Department::where('name', 'Information and Technology')->first();

        $qaUser = User::factory()->create(['department_id' => $qaDept->id]);
        $qaUser->assignRole('Employee');

        $otherUser = User::factory()->create(['department_id' => $itDept->id]);
        $otherUser->assignRole('Employee');

        $response = $this->actingAs($admin)
            ->getJson('/api/users?category=Quality Assurance');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $qaUser->id);
    }

    public function test_user_index_can_filter_approver_and_department_heads()
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $itDept = \App\Models\Department::where('name', 'Information and Technology')->first();
        $hrGaDept = \App\Models\Department::where('name', 'HRD & GA')->first();

        $approver = User::factory()->create(['department_id' => $itDept->id, 'is_department_head' => true]);
        $approver->assignRole('Approver');

        $gaHead = User::factory()->create(['department_id' => $hrGaDept->id, 'is_department_head' => true]);
        $gaHead->assignRole('GA');

        $nonApprover = User::factory()->create(['department_id' => $itDept->id]);
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
                'start_time'        => now()->addDay()->setTime(10, 0, 0)->format('Y-m-d H:i:s'),
                'end_time'          => now()->addDay()->setTime(12, 0, 0)->format('Y-m-d H:i:s'),
                'passenger_count'   => 1,
                'priority'          => 'Normal',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'submitted');
    }

    public function test_dept_head_can_approve_request()
    {
        $itDept = \App\Models\Department::where('name', 'Information and Technology')->first();

        $deptHead = User::factory()->create(['department_id' => $itDept->id, 'is_department_head' => true]);
        $deptHead->assignRole('Approver');

        $employee = User::factory()->create(['department_id' => $itDept->id]);
        $employee->assignRole('Employee');

        $vehicleRequest = VehicleRequest::create([
            'user_id'           => $employee->id,
            'department_id'     => $itDept->id,
            'destination_city'  => 'Bandung',
            'destination_place' => 'Kantor',
            'purpose'           => 'Delivery',
            'start_time'        => now()->addDay()->setTime(10, 0, 0),
            'end_time'          => now()->addDay()->setTime(14, 0, 0),
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
            ->assertJsonPath('data.status', 'driver_assigned');
    }

    public function test_assign_vehicle_validates_driver_role()
    {
        $ga = User::factory()->create();
        $ga->assignRole('GA');

        $employee = User::factory()->create();
        $employee->assignRole('Employee');

        $notADriver = User::factory()->create();
        $notADriver->assignRole('Employee'); // Not a Driver!

        $vehicle = Vehicle::create([
            'name' => 'Avanza Test',
            'plate_number' => 'B 1234 CD',
            'type' => 'Car',
            'status' => 'Available',
            'capacity' => 6,
        ]);

        $vehicleRequest = VehicleRequest::create([
            'user_id'           => $employee->id,
            'destination_city'  => 'Jakarta',
            'destination_place' => 'Gedung A',
            'purpose'           => 'Delivery',
            'start_time'        => now()->addDay()->setTime(10, 0, 0),
            'end_time'          => now()->addDay()->setTime(14, 0, 0),
            'passenger_count'   => 1,
            'priority'          => 'Normal',
            'status'            => RequestStatus::APPROVED_DEPARTMENT,
        ]);

        // Attempting to assign with non-driver user should fail
        $response = $this->actingAs($ga)
            ->postJson('/api/assignments', [
                'request_id' => $vehicleRequest->id,
                'driver_id'  => $notADriver->id,
                'vehicle_id' => $vehicle->id,
                'notes'      => 'Tolong antar',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'User yang dipilih bukan merupakan Driver');

        // Assign with real driver should succeed
        $driver = User::factory()->create(['is_active' => true]);
        $driver->assignRole('Driver');

        $response = $this->actingAs($ga)
            ->postJson('/api/assignments', [
                'request_id' => $vehicleRequest->id,
                'driver_id'  => $driver->id,
                'vehicle_id' => $vehicle->id,
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
            'start_time'        => now()->addDay()->setTime(10, 0, 0),
            'end_time'          => now()->addDay()->setTime(14, 0, 0),
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
            'start_datetime' => now()->addDay()->setTime(10, 0, 0),
            'end_datetime'   => now()->addDay()->setTime(14, 0, 0),
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
                'phone' => '0812345678',
                'location' => 'Surabaya Office',
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123'
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.email', 'updated@ovms.test')
            ->assertJsonPath('data.phone', '0812345678')
            ->assertJsonPath('data.location', 'Surabaya Office');

        // Confirm database has updated values
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Name',
            'email' => 'updated@ovms.test',
            'phone' => '0812345678',
            'location' => 'Surabaya Office'
        ]);

        // Verify password works
        $user->refresh();
        $this->assertTrue(Hash::check('newpassword123', $user->password));

        // Get profile and assert
        $response = $this->actingAs($user)
            ->getJson('/api/profile');

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.email', 'updated@ovms.test')
            ->assertJsonPath('data.phone', '0812345678')
            ->assertJsonPath('data.location', 'Surabaya Office');
    }

    public function test_user_can_update_avatar()
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $user = User::factory()->create();
        $user->assignRole('Employee');

        $file = UploadedFile::fake()->create('avatar.jpg', 100, 'image/jpeg');

        $response = $this->actingAs($user)
            ->postJson('/api/profile/avatar', [
                'avatar' => $file
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'status',
                'message',
                'data' => ['avatar_url']
            ]);

        $user->refresh();
        $this->assertNotNull($user->avatar);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($user->avatar);

        // Test max size validation (2049 KB is > 2MB limit)
        $largeFile = UploadedFile::fake()->create('large_avatar.jpg', 2049, 'image/jpeg');
        $response = $this->actingAs($user)
            ->postJson('/api/profile/avatar', [
                'avatar' => $largeFile
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['avatar']);
    }
}
