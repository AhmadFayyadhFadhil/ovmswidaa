<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Request as VehicleRequest;
use App\Enums\RequestStatus;
use Database\Seeders\RolePermissionSeeder;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Use the seeder which handles all guards
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_full_vehicle_request_workflow()
    {
        // 1. Create Users
        $itDept = \App\Models\Department::where('name', 'Information and Technology')->first();
        $hrGaDept = \App\Models\Department::where('name', 'HRD & GA')->first();

        $employee = User::factory()->create(['department_id' => $itDept->id, 'is_active' => true]);
        $employee->assignRole('Employee');

        $deptHead = User::factory()->create(['department_id' => $itDept->id, 'is_department_head' => true, 'is_active' => true]);
        $deptHead->assignRole('Approver');

        $hrdHead = User::factory()->create(['department_id' => $hrGaDept->id, 'is_department_head' => true, 'is_active' => true]);
        $hrdHead->assignRole('Approver');

        $ga = User::factory()->create(['is_active' => true]);
        $ga->assignRole('GA');

        $driver = User::factory()->create(['is_active' => true]);
        $driver->assignRole('Driver');

        // Vehicle
        $vehicle = Vehicle::create([
            'name' => 'Avanza Test',
            'plate_number' => 'B 1234 CD',
            'type' => 'Car',
            'status' => 'Available',
            'capacity' => 6,
        ]);

        // 2. Employee submits request
        $this->actingAs($employee);
        $response = $this->postJson('/api/requests', [
            'department_id' => $itDept->id,
            'destination_city' => 'Jakarta',
            'destination_place' => 'Sudirman',
            'purpose' => 'Meeting',
            'start_time' => now()->addDay()->setTime(10, 0, 0)->format('Y-m-d H:i:s'),
            'passenger_count' => 2,
            'priority' => 'Normal',
        ]);
        $response->assertStatus(201);
        $requestId = $response->json('data.id');
        $this->assertDatabaseHas('requests', ['id' => $requestId, 'status' => 'submitted']);

        // 3. GA assigns driver (Drafting assignment proposal directly on submitted request)
        $this->actingAs($ga);
        $response = $this->postJson("/api/assignments", [
            'request_id' => $requestId,
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'notes' => 'Tolong antar ya'
        ]);
        $response->assertStatus(201);
        $assignmentId = $response->json('data.id');
        $this->assertDatabaseHas('assignments', ['id' => $assignmentId, 'status' => 'pending_driver']);
        $this->assertDatabaseHas('requests', ['id' => $requestId, 'status' => 'waiting_driver']);

        // 4. Driver responds (Accepts & Picks Vehicle)
        $this->actingAs($driver);
        $response = $this->putJson("/api/assignments/{$assignmentId}", [
            'response' => 'accepted',
            'vehicle_id' => $vehicle->id,
        ]);
        $response->assertStatus(200);
        $this->assertDatabaseHas('assignments', ['id' => $assignmentId, 'status' => 'accepted']);
        $this->assertDatabaseHas('requests', ['id' => $requestId, 'status' => 'driver_assigned']);
        
        // Verify trip created
        $this->assertDatabaseHas('operational_trips', [
            'request_id' => $requestId,
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'scheduled'
        ]);

        // 7. Driver starts trip
        $response = $this->postJson("/api/requests/{$requestId}/start");
        $response->assertStatus(200);
        $this->assertDatabaseHas('requests', ['id' => $requestId, 'status' => 'on_going']);
        $this->assertDatabaseHas('operational_trips', ['request_id' => $requestId, 'status' => 'on_going']);

        // 8. Driver completes trip
        $response = $this->postJson("/api/requests/{$requestId}/complete");
        $response->assertStatus(200);
        $this->assertDatabaseHas('requests', ['id' => $requestId, 'status' => 'completed']);
        $this->assertDatabaseHas('operational_trips', ['request_id' => $requestId, 'status' => 'completed']);
    }

    public function test_cancel_assignment()
    {
        // 1. Create Users
        $itDept = \App\Models\Department::where('name', 'Information and Technology')->first();
        $hrGaDept = \App\Models\Department::where('name', 'HRD & GA')->first();

        $employee = User::factory()->create(['department_id' => $itDept->id, 'is_active' => true]);
        $employee->assignRole('Employee');

        $deptHead = User::factory()->create(['department_id' => $itDept->id, 'is_department_head' => true, 'is_active' => true]);
        $deptHead->assignRole('Approver');

        $hrdHead = User::factory()->create(['department_id' => $hrGaDept->id, 'is_department_head' => true, 'is_active' => true]);
        $hrdHead->assignRole('Approver');

        $ga = User::factory()->create(['is_active' => true]);
        $ga->assignRole('GA');

        $driver = User::factory()->create(['is_active' => true]);
        $driver->assignRole('Driver');

        // Vehicle
        $vehicle = Vehicle::create([
            'name' => 'Avanza Test',
            'plate_number' => 'B 1234 CD',
            'type' => 'Car',
            'status' => 'Available',
            'capacity' => 6,
        ]);

        // 2. Employee submits request
        $this->actingAs($employee);
        $response = $this->postJson('/api/requests', [
            'department_id' => $itDept->id,
            'destination_city' => 'Jakarta',
            'destination_place' => 'Sudirman',
            'purpose' => 'Meeting',
            'start_time' => now()->addDay()->setTime(10, 0, 0)->format('Y-m-d H:i:s'),
            'passenger_count' => 2,
            'priority' => 'Normal',
        ]);
        $response->assertStatus(201);
        $requestId = $response->json('data.id');

        // 3. GA assigns driver directly on submitted request
        $this->actingAs($ga);
        $response = $this->postJson("/api/assignments", [
            'request_id' => $requestId,
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'notes' => 'Tolong antar ya'
        ]);
        $response->assertStatus(201);
        $assignmentId = $response->json('data.id');
        $this->assertDatabaseHas('assignments', ['id' => $assignmentId, 'status' => 'pending_driver']);
        $this->assertDatabaseHas('requests', ['id' => $requestId, 'status' => 'waiting_driver']);

        // 4. GA cancels assignment
        $this->actingAs($ga);
        $response = $this->postJson("/api/assignments/{$assignmentId}/cancel");
        $response->assertStatus(200);

        $this->assertDatabaseMissing('assignments', ['id' => $assignmentId]);
        $this->assertDatabaseHas('requests', [
            'id' => $requestId,
            'status' => 'submitted',
            'driver_id' => null,
            'assigned_by' => null,
            'assigned_at' => null,
            'driver_response_status' => null,
        ]);
    }

    public function test_dept_head_can_see_pending_request()
    {
        $itDept = \App\Models\Department::where('name', 'Information and Technology')->first();

        $employee = User::factory()->create(['department_id' => $itDept->id, 'is_active' => true]);
        $employee->assignRole('Employee');

        $deptHead = User::factory()->create(['department_id' => $itDept->id, 'is_department_head' => true, 'is_active' => true]);
        $deptHead->assignRole('Approver');

        $this->actingAs($employee);
        $response = $this->postJson('/api/requests', [
            'department_id' => $itDept->id,
            'destination_city' => 'Jakarta',
            'destination_place' => 'Sudirman',
            'purpose' => 'Meeting',
            'start_time' => now()->addDay()->setTime(10, 0, 0)->format('Y-m-d H:i:s'),
            'passenger_count' => 2,
            'priority' => 'Normal',
        ]);
        $response->assertStatus(201);
        $requestId = $response->json('data.id');

        $this->actingAs($deptHead);
        $response = $this->getJson('/api/requests?status=pending');
        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $requestId])
            ->assertJsonPath('data.0.can_approve', true);
    }
}
