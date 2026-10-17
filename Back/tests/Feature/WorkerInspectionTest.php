<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\InspectionReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkerInspectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_can_log_in_and_view_their_assigned_inspection(): void
    {
        $worker = $this->worker();
        $report = $this->assignReport($worker);

        $this->postJson('/api/auth/login', [
            'email' => $worker->email,
            'password' => 'a-secure-password',
        ])->assertOk()->assertJsonPath('user.role', 'worker')
            ->assertJsonPath('user.governorate', 'Tunis');

        $this->getJson('/api/worker/dashboard')
            ->assertOk()
            ->assertJsonPath('counts.assigned_inspections', 1)
            ->assertJsonPath('counts.pending_inspections', 1)
            ->assertJsonPath('counts.completed_inspections', 0);

        $this->getJson('/api/worker/inspections')
            ->assertOk()
            ->assertJsonCount(1, 'inspections')
            ->assertJsonPath('inspections.0.id', $report->id)
            ->assertJsonPath('inspections.0.owner.email', 'owner@example.test');
    }

    public function test_staff_accounts_are_created_only_through_the_authorized_console_command(): void
    {
        $this->artisan('housing:create-staff', ['role' => 'worker'])
            ->expectsQuestion('Full name', 'Console Worker')
            ->expectsQuestion('Email address', 'console-worker@example.test')
            ->expectsQuestion('Phone number', '+216 20 000 020')
            ->expectsQuestion('Authorized governorate', 'Sousse')
            ->expectsQuestion('Password (at least 12 characters)', 'a-secure-staff-password')
            ->expectsQuestion('Confirm password', 'a-secure-staff-password')
            ->expectsOutputToContain('Worker account created')
            ->assertExitCode(0);

        $worker = User::where('email', 'console-worker@example.test')->firstOrFail();
        $this->assertSame('worker', $worker->role);
        $this->assertSame('Sousse', $worker->governorate);
        $this->assertTrue(Hash::check('a-secure-staff-password', $worker->password));

        $this->postJson('/api/auth/register', [
            'name' => 'Public Worker',
            'email' => 'public-worker@example.test',
            'phone' => '+216 20 000 021',
            'password' => 'a-secure-password',
            'password_confirmation' => 'a-secure-password',
            'role' => 'worker',
        ])->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_unauthenticated_students_and_owners_cannot_access_worker_endpoints(): void
    {
        $this->getJson('/api/worker/dashboard')->assertUnauthorized();

        $student = User::factory()->create();
        $student->forceFill(['role' => 'student'])->save();
        $this->actingAs($student)->getJson('/api/worker/dashboard')->assertForbidden();

        $owner = $this->owner();
        $this->actingAs($owner)->getJson('/api/worker/inspections')->assertForbidden();
    }

    public function test_worker_cannot_view_or_submit_another_workers_inspection(): void
    {
        $assignedWorker = $this->worker();
        $otherWorker = $this->worker('other-worker@example.test');
        $report = $this->assignReport($assignedWorker);

        $this->actingAs($otherWorker)
            ->getJson('/api/worker/inspections/'.$report->id)
            ->assertNotFound();

        $this->actingAs($otherWorker)
            ->postJson('/api/worker/inspections/'.$report->id.'/complete', $this->completedReportData())
            ->assertNotFound();

        $this->assertSame('assigned', $report->fresh()->status);
    }

    public function test_inspection_requires_every_checklist_item_and_report_notes(): void
    {
        $worker = $this->worker();
        $report = $this->assignReport($worker);

        $this->actingAs($worker)
            ->postJson('/api/worker/inspections/'.$report->id.'/complete', [
                'condition_checklist' => ['electricity' => 'good'],
                'recommendation' => 'approve',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'condition_checklist.description_match',
                'condition_checklist.water',
                'notes',
            ]);

        $this->assertSame('assigned', $report->fresh()->status);
    }

    public function test_worker_submission_saves_notes_checklist_photos_and_pending_approval_state(): void
    {
        $worker = $this->worker();
        $report = $this->assignReport($worker);
        $disk = config('filesystems.default');
        Storage::fake($disk);
        $photo = UploadedFile::fake()->createWithContent(
            'inspection-photo.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
        );

        $response = $this->actingAs($worker)->post('/api/worker/inspections/'.$report->id.'/complete', [
            ...$this->completedReportData(),
            'photos' => [$photo],
        ]);

        $response->assertOk()
            ->assertJsonPath('inspection.status', 'completed')
            ->assertJsonPath('inspection.recommendation', 'approve');

        $report->refresh();
        $this->assertSame($worker->id, $report->worker_id);
        $this->assertNotNull($report->completed_at);
        $this->assertSame('A visit was completed.', $report->notes);
        $this->assertSame('good', $report->condition_checklist['electricity']);
        $this->assertCount(1, $report->photos);
        Storage::disk($disk)->assertExists($report->photos->first()->path);
        $this->assertSame('inspection_completed', $report->house->fresh()->verification_status);
        $this->assertNull($report->house->fresh()->verification_decided_by);
    }

    public function test_problem_checklist_item_requires_a_defect_description(): void
    {
        $worker = $this->worker();
        $report = $this->assignReport($worker);
        $data = $this->completedReportData();
        $data['condition_checklist']['water'] = 'problem_found';
        $data['defects_found'] = '';

        $this->actingAs($worker)
            ->postJson('/api/worker/inspections/'.$report->id.'/complete', $data)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('defects_found');
    }

    public function test_inspection_rejects_executable_photo_uploads(): void
    {
        $worker = $this->worker();
        $report = $this->assignReport($worker);

        $this->actingAs($worker)
            ->withHeaders(['Accept' => 'application/json'])
            ->post('/api/worker/inspections/'.$report->id.'/complete', [
                ...$this->completedReportData(),
                'photos' => [UploadedFile::fake()->create('executable.php', 10, 'application/x-php')],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photos.0');

        $this->assertSame('assigned', $report->fresh()->status);
    }

    public function test_only_an_admin_can_assign_a_worker_in_the_house_governorate(): void
    {
        $owner = $this->owner();
        $house = $this->house($owner);
        $worker = $this->worker();
        $payload = ['worker_id' => $worker->id, 'scheduled_at' => now()->addDay()->toIso8601String()];

        $this->postJson('/api/admin/houses/'.$house->id.'/inspection-assignment', $payload)
            ->assertUnauthorized();

        $this->actingAs($owner)
            ->postJson('/api/admin/houses/'.$house->id.'/inspection-assignment', $payload)
            ->assertForbidden();

        $admin = $this->admin();
        $this->actingAs($admin)
            ->postJson('/api/admin/houses/'.$house->id.'/inspection-assignment', $payload)
            ->assertCreated()
            ->assertJsonPath('status', 'assigned');

        $this->assertSame('inspection_assigned', $house->fresh()->verification_status);
        $this->assertDatabaseHas('inspection_reports', [
            'house_id' => $house->id,
            'worker_id' => $worker->id,
            'status' => 'assigned',
        ]);
    }

    public function test_admin_cannot_assign_worker_outside_governorate(): void
    {
        $house = $this->house($this->owner());
        $worker = $this->worker('sousse-worker@example.test', 'Sousse');

        $this->actingAs($this->admin())
            ->postJson('/api/admin/houses/'.$house->id.'/inspection-assignment', [
                'worker_id' => $worker->id,
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('inspection_reports', 0);
        $this->assertSame('pending_verification', $house->fresh()->verification_status);
    }

    public function test_house_cannot_be_verified_without_completed_approved_report(): void
    {
        $worker = $this->worker();
        $report = $this->assignReport($worker);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/admin/inspections/'.$report->id.'/decision', ['decision' => 'verified'])
            ->assertStatus(409);

        $this->actingAs($worker)
            ->postJson('/api/admin/inspections/'.$report->id.'/decision', ['decision' => 'verified'])
            ->assertForbidden();

        $this->actingAs($this->owner('decision-owner@example.test'))
            ->postJson('/api/admin/inspections/'.$report->id.'/decision', ['decision' => 'verified'])
            ->assertForbidden();

        $this->assertSame('pending_verification', $report->house->fresh()->verification_status);
    }

    public function test_worker_recommendation_is_not_a_final_decision_and_admin_records_approval(): void
    {
        $worker = $this->worker();
        $report = $this->assignReport($worker);
        $this->actingAs($worker)
            ->postJson('/api/worker/inspections/'.$report->id.'/complete', $this->completedReportData())
            ->assertOk();

        $admin = $this->admin();
        $this->actingAs($admin)
            ->postJson('/api/admin/inspections/'.$report->id.'/decision', ['decision' => 'rejected'])
            ->assertOk()
            ->assertJsonPath('verification_status', 'rejected');

        $secondHouse = $this->house($this->owner('second-owner@example.test'));
        $secondReport = $this->assignReport($worker, $secondHouse);
        $this->actingAs($worker)
            ->postJson('/api/worker/inspections/'.$secondReport->id.'/complete', $this->completedReportData())
            ->assertOk();

        $this->actingAs($admin)
            ->postJson('/api/admin/inspections/'.$secondReport->id.'/decision', [
                'decision' => 'verified',
                'review_notes' => 'Report checked and approved.',
            ])
            ->assertOk()
            ->assertJsonPath('verification_status', 'verified');

        $secondReport->refresh();
        $this->assertSame($admin->id, $secondReport->reviewed_by);
        $this->assertNotNull($secondReport->reviewed_at);
        $this->assertSame($admin->id, $secondReport->house->verification_decided_by);
    }

    public function test_admin_can_review_pending_inspections_and_accept_or_reject_house_listings(): void
    {
        Storage::fake(config('filesystems.default'));
        $worker = $this->worker();
        $approvedHouse = $this->house($this->owner('approved-owner@example.test'));
        $approvedHouse->forceFill(['public_listing_price_tnd' => '420.000'])->save();
        $approvedReport = $this->assignReport($worker, $approvedHouse);
        $this->actingAs($worker)->postJson('/api/worker/inspections/'.$approvedReport->id.'/complete', $this->completedReportData())
            ->assertOk();
        $photoPath = 'inspections/'.$approvedReport->id.'/inspection.jpg';
        Storage::disk(config('filesystems.default'))->put($photoPath, 'inspection photo bytes');
        $approvedReport->photos()->create([
            'disk' => config('filesystems.default'),
            'path' => $photoPath,
        ]);

        $rejectedHouse = $this->house($this->owner('rejected-owner@example.test'));
        $rejectedHouse->forceFill(['public_listing_price_tnd' => '400.000'])->save();
        $rejectedReport = $this->assignReport($worker, $rejectedHouse);
        $rejectedData = $this->completedReportData();
        $rejectedData['recommendation'] = 'reject';
        $this->actingAs($worker)->postJson('/api/worker/inspections/'.$rejectedReport->id.'/complete', $rejectedData)
            ->assertOk();

        $student = User::factory()->create();
        $student->forceFill(['role' => 'student'])->save();
        $this->actingAs($student)->getJson('/api/admin/inspection-reviews')->assertForbidden();
        $this->actingAs($worker)->getJson('/api/admin/inspection-reviews')->assertForbidden();

        $admin = $this->admin();
        $reviews = $this->actingAs($admin)->getJson('/api/admin/inspection-reviews')
            ->assertOk()
            ->assertJsonCount(2, 'inspections')
            ->assertJsonPath('inspections.0.house.public_listing_price_tnd', '420.000')
            ->assertJsonPath('inspections.0.owner.email', 'approved-owner@example.test')
            ->assertJsonPath('inspections.0.worker.name', $worker->name)
            ->assertJsonPath('inspections.0.recommendation', 'approve')
            ->assertJsonPath('inspections.0.photos.0.id', $approvedReport->photos()->firstOrFail()->id);
        $photoUrl = $reviews->json('inspections.0.photos.0.url');
        $this->actingAs($worker)->get($photoUrl)->assertForbidden();
        $this->actingAs($admin)->get($photoUrl)
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($admin)->postJson('/api/admin/inspections/'.$approvedReport->id.'/decision', [
            'decision' => 'verified',
            'review_notes' => 'Listing approved after reviewing the report.',
        ])->assertOk()->assertJsonPath('verification_status', 'verified');
        $this->actingAs($admin)->postJson('/api/admin/inspections/'.$rejectedReport->id.'/decision', [
            'decision' => 'rejected',
            'review_notes' => 'Inspection did not meet the requirements.',
        ])->assertOk()->assertJsonPath('verification_status', 'rejected');

        $this->actingAs($admin)->getJson('/api/admin/inspection-reviews')
            ->assertOk()
            ->assertJsonCount(0, 'inspections');
    }

    public function test_admin_cannot_verify_when_worker_recommended_rejection(): void
    {
        $worker = $this->worker();
        $report = $this->assignReport($worker);
        $payload = $this->completedReportData();
        $payload['recommendation'] = 'reject';

        $this->actingAs($worker)
            ->postJson('/api/worker/inspections/'.$report->id.'/complete', $payload)
            ->assertOk();

        $this->actingAs($this->admin())
            ->postJson('/api/admin/inspections/'.$report->id.'/decision', ['decision' => 'verified'])
            ->assertUnprocessable();

        $this->assertSame('inspection_completed', $report->house->fresh()->verification_status);
    }

    private function worker(string $email = 'worker@example.test', string $governorate = 'Tunis'): User
    {
        $worker = User::factory()->create([
            'email' => $email,
            'password' => Hash::make('a-secure-password'),
        ]);
        $worker->forceFill(['role' => 'worker', 'governorate' => $governorate])->save();

        return $worker;
    }

    private function owner(string $email = 'owner@example.test'): User
    {
        $owner = User::factory()->create(['email' => $email]);
        $owner->forceFill(['role' => 'owner'])->save();

        return $owner;
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $admin->forceFill(['role' => 'admin'])->save();

        return $admin;
    }

    private function house(User $owner, array $attributes = []): House
    {
        return $owner->houses()->create([
            'title' => 'Inspected student flat',
            'description' => 'A description-matching flat.',
            'governorate' => 'Tunis',
            'city' => 'Tunis',
            'address' => 'Near campus',
            'monthly_rent_tnd' => '400.000',
            'bedrooms' => 2,
            'furnishing_status' => 'furnished',
            'available' => true,
            'verification_status' => 'pending_verification',
            ...$attributes,
        ]);
    }

    private function assignReport(User $worker, ?House $house = null): InspectionReport
    {
        $house ??= $this->house($this->owner());

        return $house->inspectionReports()->create([
            'worker_id' => $worker->id,
            'inspection_date' => now()->addDay(),
            'status' => 'assigned',
        ]);
    }

    private function completedReportData(): array
    {
        return [
            'condition_checklist' => [
                'description_match' => 'good',
                'electricity' => 'good',
                'water' => 'good',
                'doors_windows' => 'good',
                'bathroom_kitchen' => 'good',
                'furniture' => 'not_applicable',
                'leaks_damage' => 'good',
                'rooms_facilities' => 'good',
            ],
            'notes' => 'A visit was completed.',
            'defects_found' => '',
            'recommendation' => 'approve',
        ];
    }
}
