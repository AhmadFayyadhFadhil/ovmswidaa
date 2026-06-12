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
        $employee = User::factory()->create(['department_id' => 'IT']);
        $employee->assignRole('Employee');

        $deptHead = User::factory()->create(['department_id' => 'IT', 'is_department_head' => true]);
        $deptHead->assignRole('Approver');

        $hrdHead = User::factory()->create(['department_id' => 'HR&GA', 'is_department_head' => true]);
        $hrdHead->assignRole('Approver');

        $ga = User::factory()->create();
        $ga->assignRole('GA');
        fwrite(STDERR, "GA roles count: " . $ga->roles()->count() . "\n");
        fwrite(STDERR, "GA roles name: " . json_encode($ga->roles->pluck('name')->toArray()) . "\n");

        $driver = User::factory()->create();
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
            'department_id' => 'IT',
            'destination_city' => 'Jakarta',
            'destination_place' => 'Sudirman',
            'purpose' => 'Meeting',
            'start_time' => now()->addDay()->format('Y-m-d H:i:s'),
            'passenger_count' => 2,
            'priority' => 'Normal',
        ]);
        $response->assertStatus(201);
        $requestId = $response->json('data.id');
        $this->assertDatabaseHas('requests', ['id' => $requestId, 'status' => 'submitted']);

        // 3. Dept Head approves
        $this->actingAs($deptHead);
        $response = $this->postJson("/api/requests/{$requestId}/approve", [
            'role' => 'dept_head',
            'notes' => 'ACC Dept'
        ]);
        $response->assertStatus(200);
        $this->assertDatabaseHas('requests', ['id' => $requestId, 'status' => 'approved_department']);

        // 4. HRD Head approves
        $this->actingAs($hrdHead);
        $response = $this->postJson("/api/requests/{$requestId}/approve", [
            'role' => 'hrd_head',
            'notes' => 'ACC HRD'
        ]);
        $response->assertStatus(200);
        $this->assertDatabaseHas('requests', ['id' => $requestId, 'status' => 'approved_hrd_ga']);

        // 5. GA assigns driver
        $this->actingAs($ga);
        $response = $this->postJson("/api/assignments", [
            'request_id' => $requestId,
            'driver_id' => $driver->id,
            'notes' => 'Tolong antar ya'
        ]);
        $status = $response->status();
        fwrite(STDERR, "Assignment creation response status: $status\n");
        if ($status !== 201) {
            fwrite(STDERR, json_encode($response->json(), JSON_PRETTY_PRINT) . "\n");
        }
        $response->assertStatus(201);
        $assignmentId = $response->json('data.id');
        $this->assertDatabaseHas('assignments', ['id' => $assignmentId, 'status' => 'pending_driver']);
        $this->assertDatabaseHas('requests', ['id' => $requestId, 'status' => 'waiting_driver']);

        // 6. Driver responds (Accepts & Picks Vehicle)
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
        $employee = User::factory()->create(['department_id' => 'IT']);
        $employee->assignRole('Employee');

        $deptHead = User::factory()->create(['department_id' => 'IT', 'is_department_head' => true]);
        $deptHead->assignRole('Approver');

        $hrdHead = User::factory()->create(['department_id' => 'HR&GA', 'is_department_head' => true]);
        $hrdHead->assignRole('Approver');

        $ga = User::factory()->create();
        $ga->assignRole('GA');

        $driver = User::factory()->create();
        $driver->assignRole('Driver');

        // 2. Employee submits request
        $this->actingAs($employee);
        $response = $this->postJson('/api/requests', [
            'department_id' => 'IT',
            'destination_city' => 'Jakarta',
            'destination_place' => 'Sudirman',
            'purpose' => 'Meeting',
            'start_time' => now()->addDay()->format('Y-m-d H:i:s'),
            'passenger_count' => 2,
            'priority' => 'Normal',
        ]);
        $response->assertStatus(201);
        $requestId = $response->json('data.id');

        // 3. Dept Head approves
        $this->actingAs($deptHead);
        $this->postJson("/api/requests/{$requestId}/approve", [
            'role' => 'dept_head',
            'notes' => 'ACC Dept'
        ])->assertStatus(200);

        // 4. HRD Head approves
        $this->actingAs($hrdHead);
        $this->postJson("/api/requests/{$requestId}/approve", [
            'role' => 'hrd_head',
            'notes' => 'ACC HRD'
        ])->assertStatus(200);

        // 5. GA assigns driver
        $this->actingAs($ga);
        $response = $this->postJson("/api/assignments", [
            'request_id' => $requestId,
            'driver_id' => $driver->id,
            'notes' => 'Tolong antar ya'
        ]);
        $response->assertStatus(201);
        $assignmentId = $response->json('data.id');
        $this->assertDatabaseHas('assignments', ['id' => $assignmentId, 'status' => 'pending_driver']);
        $this->assertDatabaseHas('requests', ['id' => $requestId, 'status' => 'waiting_driver']);

        // 6. GA cancels assignment
        $this->actingAs($ga);
        $response = $this->postJson("/api/assignments/{$assignmentId}/cancel");
        $response->assertStatus(200);

        $this->assertDatabaseMissing('assignments', ['id' => $assignmentId]);
        $this->assertDatabaseHas('requests', [
            'id' => $requestId,
            'status' => 'approved_hrd_ga',
            'driver_id' => null,
            'assigned_by' => null,
            'assigned_at' => null,
            'driver_response_status' => null,
        ]);
    }

    public function test_dept_head_can_see_pending_request()
    {
        $employee = User::factory()->create(['department_id' => 'IT']);
        $employee->assignRole('Employee');

        $deptHead = User::factory()->create(['department_id' => 'IT', 'is_department_head' => true]);
        $deptHead->assignRole('Approver');

        $this->actingAs($employee);
        $response = $this->postJson('/api/requests', [
            'department_id' => 'IT',
            'destination_city' => 'Jakarta',
            'destination_place' => 'Sudirman',
            'purpose' => 'Meeting',
            'start_time' => now()->addDay()->format('Y-m-d H:i:s'),
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
