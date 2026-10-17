<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceVisit;
use App\Models\RentalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MaintenanceRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_tenant_can_submit_request_and_owner_gets_in_app_notification(): void
    {
        $disk = config('filesystems.default');
        Storage::fake($disk);
        [$house, $student] = $this->rentedHouseAndTenant();

        $this->actingAs($student)->postJson('/api/student/maintenance-requests', [
            'house_id' => $house->id,
            'category' => 'plumbing',
            'description' => 'The kitchen sink pipe is leaking.',
            'priority' => 'high',
            'reported_at' => now()->subHour()->toDateTimeString(),
            'photos' => [$this->pngUpload('pipe.png')],
            'reporter_id' => $this->otherStudent()->id,
            'status' => 'completed',
            'repair_cost_tnd' => 0,
        ])->assertCreated()
            ->assertJsonPath('maintenance_request.status', 'awaiting_owner_response')
            ->assertJsonPath('maintenance_request.category', 'plumbing')
            ->assertJsonPath('maintenance_request.priority', 'high')
            ->assertJsonPath('maintenance_request.covered_by_service', false)
            ->assertJsonPath('maintenance_request.photos.0.type', 'reported');

        $item = MaintenanceRequest::query()->firstOrFail();
        $this->assertSame($student->id, $item->reporter_id);
        $this->assertSame($house->owner_id, $item->house->owner_id);
        $this->assertCount(1, $item->photos);
        Storage::disk($disk)->assertExists($item->photos->first()->path);
        $this->assertDatabaseHas('maintenance_notifications', [
            'recipient_id' => $house->owner_id,
            'maintenance_request_id' => $item->id,
            'event_type' => 'request_submitted',
        ]);

        $photo = $item->photos->first();
        $this->actingAs($student)->get('/api/student/maintenance-requests/'.$item->id.'/photos/'.$photo->id)->assertOk();
        $this->actingAs($house->owner)->get('/api/owner/maintenance-requests/'.$item->id.'/photos/'.$photo->id)->assertOk();
        $this->actingAs($this->worker())->get('/api/worker/maintenance-requests/'.$item->id.'/photos/'.$photo->id)->assertNotFound();
    }

    public function test_student_without_confirmed_rental_cannot_report_house_problem(): void
    {
        $house = $this->house($this->owner());
        $student = $this->student();

        $this->actingAs($student)->postJson('/api/student/maintenance-requests', [
            'house_id' => $house->id,
            'category' => 'electricity',
            'description' => 'The hallway light does not work.',
            'priority' => 'normal',
        ])->assertForbidden();

        $this->assertDatabaseCount('maintenance_requests', 0);
    }

    public function test_invalid_maintenance_fields_and_executable_photos_are_rejected(): void
    {
        Storage::fake('public');
        [$house, $student] = $this->rentedHouseAndTenant();

        $this->actingAs($student)->postJson('/api/student/maintenance-requests', [
            'house_id' => $house->id,
            'category' => 'security_bypass',
            'description' => 'short',
            'priority' => 'emergency_now',
            'reported_at' => now()->addDay()->toDateTimeString(),
            'photos' => [UploadedFile::fake()->create('payload.php', 10, 'application/x-php')],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['category', 'description', 'priority', 'reported_at', 'photos.0']);

        $this->assertDatabaseCount('maintenance_requests', 0);
    }

    public function test_owner_only_sees_and_acknowledges_maintenance_requests_for_owned_houses(): void
    {
        $owner = $this->owner();
        $house = $this->house($owner);
        [$otherHouse, $student] = $this->rentedHouseAndTenant($this->owner('other-owner@example.test'), 'tenant@example.test');
        $firstTenant = $this->student('first-tenant@example.test');
        $this->confirmRental($firstTenant, $house);
        $ownRequest = $this->createMaintenanceRequest($firstTenant, $house);
        $otherRequest = $this->createMaintenanceRequest($student, $otherHouse);

        $this->actingAs($owner)->getJson('/api/owner/maintenance-requests')
            ->assertOk()
            ->assertJsonCount(1, 'maintenance_requests.data')
            ->assertJsonPath('maintenance_requests.data.0.id', $ownRequest->id);

        $this->actingAs($owner)->postJson('/api/owner/maintenance-requests/'.$otherRequest->id.'/acknowledge')
            ->assertNotFound();

        $this->actingAs($owner)->postJson('/api/owner/maintenance-requests/'.$ownRequest->id.'/acknowledge')
            ->assertOk()
            ->assertJsonPath('maintenance_request.status', 'reported')
            ->assertJsonPath('maintenance_request.owner_received_at', fn ($value) => is_string($value));

        $this->assertDatabaseHas('maintenance_notifications', [
            'recipient_id' => $firstTenant->id,
            'maintenance_request_id' => $ownRequest->id,
            'event_type' => 'owner_received',
        ]);

        $this->actingAs($student)->getJson('/api/owner/maintenance-requests')->assertForbidden();
    }

    public function test_only_governorate_authorized_admin_can_assign_workers_and_worker_is_notified(): void
    {
        $owner = $this->owner();
        $house = $this->house($owner);
        $student = $this->student();
        $this->confirmRental($student, $house);
        $item = $this->createMaintenanceRequest($student, $house, ['status' => 'reported', 'owner_received_at' => now()]);
        $worker = $this->worker();
        $wrongWorker = $this->worker('sousse-worker@example.test', 'Sousse');
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/maintenance-requests/'.$item->id.'/assignment', [
            'worker_id' => $wrongWorker->id,
        ])->assertUnprocessable();

        $this->actingAs($admin)->postJson('/api/admin/maintenance-requests/'.$item->id.'/assignment', [
            'worker_id' => $worker->id,
            'scheduled_at' => now()->addDay()->toDateTimeString(),
        ])->assertOk()
            ->assertJsonPath('maintenance_request.status', 'assigned')
            ->assertJsonPath('maintenance_request.assigned_worker.name', $worker->name);

        $this->assertDatabaseHas('maintenance_notifications', [
            'recipient_id' => $worker->id,
            'maintenance_request_id' => $item->id,
            'event_type' => 'worker_assigned',
        ]);
        $this->assertDatabaseHas('maintenance_visits', [
            'maintenance_request_id' => $item->id,
            'worker_id' => $worker->id,
        ]);
    }

    public function test_worker_can_only_view_and_update_assigned_requests_in_their_governorate(): void
    {
        $worker = $this->worker();
        $otherWorker = $this->worker('other-worker@example.test');
        [$house, $student] = $this->rentedHouseAndTenant();
        $item = $this->createMaintenanceRequest($student, $house, ['status' => 'assigned', 'assigned_worker_id' => $worker->id]);

        $this->actingAs($worker)->getJson('/api/worker/maintenance-requests')
            ->assertOk()
            ->assertJsonCount(1, 'maintenance_requests.data');

        $this->actingAs($otherWorker)->getJson('/api/worker/maintenance-requests')
            ->assertOk()
            ->assertJsonCount(0, 'maintenance_requests.data');
        $this->actingAs($otherWorker)->postJson('/api/worker/maintenance-requests/'.$item->id.'/updates', [
            'action' => 'start_work',
            'notes' => 'Started the repair.',
        ])->assertNotFound();

        $this->actingAs($worker)->postJson('/api/worker/maintenance-requests/'.$item->id.'/updates', [
            'action' => 'schedule_visit',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
        ])->assertOk()->assertJsonPath('maintenance_request.status', 'visit_scheduled');

        $this->assertSame(1, MaintenanceVisit::query()->where('maintenance_request_id', $item->id)->count());
    }

    public function test_owner_must_approve_estimate_before_worker_can_start_or_complete_repair(): void
    {
        [$house, $student] = $this->rentedHouseAndTenant();
        $worker = $this->worker();
        $item = $this->createMaintenanceRequest($student, $house, [
            'status' => 'assigned',
            'assigned_worker_id' => $worker->id,
        ]);

        $this->actingAs($worker)->postJson('/api/worker/maintenance-requests/'.$item->id.'/updates', [
            'action' => 'record_visit',
            'notes' => 'Inspected the leaking valve and measured the required part.',
        ])->assertOk()->assertJsonPath('maintenance_request.status', 'assigned');

        $this->actingAs($worker)->postJson('/api/worker/maintenance-requests/'.$item->id.'/updates', [
            'action' => 'submit_estimate',
            'estimated_repair_cost_tnd' => '175.500',
            'covered_by_service' => false,
            'cost_responsibility' => 'owner',
            'notes' => 'Replacement valve and labor are needed.',
        ])->assertOk()
            ->assertJsonPath('maintenance_request.status', 'awaiting_owner_response')
            ->assertJsonPath('maintenance_request.estimated_repair_cost_tnd', '175.500');

        $this->actingAs($worker)->postJson('/api/worker/maintenance-requests/'.$item->id.'/updates', [
            'action' => 'start_work',
            'notes' => 'Trying to start without approval.',
        ])->assertStatus(409);

        $this->actingAs($house->owner)->postJson('/api/owner/maintenance-requests/'.$item->id.'/estimate-decision', [
            'decision' => 'approve',
            'covered_by_service' => false,
            'cost_responsibility' => 'owner',
            'note' => 'Approved for repair.',
        ])->assertOk()
            ->assertJsonPath('maintenance_request.status', 'assigned')
            ->assertJsonPath('maintenance_request.covered_by_service', false)
            ->assertJsonPath('maintenance_request.cost_responsibility', 'owner');

        $this->actingAs($worker)->postJson('/api/worker/maintenance-requests/'.$item->id.'/updates', [
            'action' => 'submit_for_confirmation',
            'actual_repair_cost_tnd' => '160.000',
            'repair_outcome' => 'Replaced leaking valve.',
            'notes' => 'Completed the repair.',
        ])->assertStatus(409);

        $this->actingAs($worker)->postJson('/api/worker/maintenance-requests/'.$item->id.'/updates', [
            'action' => 'start_work',
            'notes' => 'Visited the house and started the approved repair.',
        ])->assertOk()->assertJsonPath('maintenance_request.status', 'in_progress');

        $this->actingAs($worker)->postJson('/api/worker/maintenance-requests/'.$item->id.'/updates', [
            'action' => 'submit_for_confirmation',
            'actual_repair_cost_tnd' => '160.000',
            'repair_outcome' => 'Replaced leaking valve and checked the connection.',
            'notes' => 'Repair completed; please review.',
            'photos' => [$this->pngUpload('repair.png')],
        ])->assertOk()->assertJsonPath('maintenance_request.status', 'awaiting_confirmation');

        $this->assertDatabaseHas('maintenance_requests', [
            'id' => $item->id,
            'estimated_repair_cost_tnd' => '175.500',
            'repair_cost_tnd' => '160.000',
            'cost_responsibility' => 'owner',
            'covered_by_service' => 0,
        ]);
        $this->assertDatabaseHas('maintenance_request_photos', [
            'maintenance_request_id' => $item->id,
            'photo_type' => 'evidence',
        ]);
    }

    public function test_owner_can_confirm_or_dispute_completion_and_student_tracks_status_and_notifications(): void
    {
        [$house, $student] = $this->rentedHouseAndTenant();
        $worker = $this->worker();
        $item = $this->createMaintenanceRequest($student, $house, [
            'status' => 'awaiting_confirmation',
            'assigned_worker_id' => $worker->id,
            'repair_cost_tnd' => '65.000',
            'resolved_at' => now(),
        ]);
        $item->visits()->create(['worker_id' => $worker->id, 'visited_at' => now(), 'notes' => 'First repair visit.']);

        $this->actingAs($house->owner)->postJson('/api/owner/maintenance-requests/'.$item->id.'/dispute-completion', [
            'note' => 'The leak is still visible under the sink.',
        ])->assertOk()->assertJsonPath('maintenance_request.status', 'in_progress');

        $this->actingAs($worker)->postJson('/api/worker/maintenance-requests/'.$item->id.'/updates', [
            'action' => 'submit_for_confirmation',
            'actual_repair_cost_tnd' => '70.000',
            'repair_outcome' => 'Replaced the pipe seal and retested.',
            'notes' => 'The repair has now been completed.',
        ])->assertOk();

        $this->actingAs($house->owner)->postJson('/api/owner/maintenance-requests/'.$item->id.'/confirm-completion')
            ->assertOk()
            ->assertJsonPath('maintenance_request.status', 'completed')
            ->assertJsonPath('maintenance_request.resolved_at', fn ($value) => is_string($value));

        $this->actingAs($student)->getJson('/api/student/maintenance-requests')
            ->assertOk()
            ->assertJsonCount(1, 'maintenance_requests.data')
            ->assertJsonPath('maintenance_requests.data.0.status', 'completed')
            ->assertJsonPath('maintenance_requests.data.0.repair_cost_tnd', '70.000');

        $this->actingAs($student)->getJson('/api/maintenance-notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 3);

        $notification = $student->maintenanceNotifications()->firstOrFail();
        $this->actingAs($house->owner)->postJson('/api/maintenance-notifications/'.$notification->id.'/read')
            ->assertNotFound();
        $this->actingAs($student)->postJson('/api/maintenance-notifications/'.$notification->id.'/read')
            ->assertOk();
        $this->assertNotNull($notification->fresh()->read_at);
    }

    private function owner(string $email = 'owner@example.test'): User
    {
        $owner = User::factory()->create(['email' => $email]);
        $owner->forceFill(['role' => 'owner'])->save();

        return $owner;
    }

    private function student(string $email = 'student@example.test'): User
    {
        $student = User::factory()->create(['email' => $email]);
        $student->forceFill(['role' => 'student'])->save();

        return $student;
    }

    private function otherStudent(): User
    {
        return $this->student('other-student@example.test');
    }

    private function worker(string $email = 'worker@example.test', string $governorate = 'Tunis'): User
    {
        $worker = User::factory()->create(['email' => $email]);
        $worker->forceFill(['role' => 'worker', 'governorate' => $governorate])->save();

        return $worker;
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();

        return $admin;
    }

    private function house(User $owner): House
    {
        return $owner->houses()->create([
            'title' => 'Tenant home',
            'description' => 'A home for student tenants.',
            'governorate' => 'Tunis',
            'city' => 'Tunis',
            'monthly_rent_tnd' => '500.000',
            'bedrooms' => 2,
            'furnishing_status' => 'furnished',
            'available' => false,
            'verification_status' => 'verified',
        ]);
    }

    private function rentedHouseAndTenant(?User $owner = null, string $studentEmail = 'student@example.test'): array
    {
        $owner ??= $this->owner();
        $house = $this->house($owner);
        $student = $this->student($studentEmail);
        $this->confirmRental($student, $house);

        return [$house, $student];
    }

    private function confirmRental(User $student, House $house): RentalRequest
    {
        return $student->rentalRequestsAsStudent()->create([
            'house_id' => $house->id,
            'requested_at' => now(),
            'status' => 'completed',
            'secured_house' => true,
            'secured_at' => now(),
            'student_confirmed_secured_at' => now(),
            'owner_confirmed_secured_at' => now(),
            'service_fee_tnd' => '20.000',
            'service_fee_payment_status' => 'due',
        ]);
    }

    private function createMaintenanceRequest(User $student, House $house, array $overrides = []): MaintenanceRequest
    {
        return $house->maintenanceRequests()->create(array_merge([
            'reporter_id' => $student->id,
            'category' => 'plumbing',
            'description' => 'The kitchen sink pipe is leaking.',
            'priority' => 'normal',
            'status' => 'awaiting_owner_response',
            'reported_at' => now(),
            'covered_by_service' => false,
        ], $overrides));
    }

    private function pngUpload(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
        );
    }
}
