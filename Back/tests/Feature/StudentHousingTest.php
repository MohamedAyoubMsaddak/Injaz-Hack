<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\Payment;
use App\Models\RentalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentHousingTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_search_only_returns_admin_verified_available_houses_and_applies_filters(): void
    {
        $owner = $this->owner();
        $verified = $this->verifiedHouse($owner, [
            'governorate' => 'Tunis',
            'city' => 'Ariana',
            'monthly_rent_tnd' => '450.000',
            'bedrooms' => 2,
            'furnishing_status' => 'furnished',
        ]);
        $pending = $owner->houses()->create($this->houseAttributes([
            'title' => 'Pending house',
            'verification_status' => 'pending_verification',
        ]));
        $rejected = $this->verifiedHouse($owner, [
            'title' => 'Rejected house',
            'verification_status' => 'rejected',
        ]);
        $unavailable = $this->verifiedHouse($owner, [
            'title' => 'Unavailable house',
            'available' => false,
        ]);
        $unreviewed = $owner->houses()->create($this->houseAttributes([
            'title' => 'House without an authorized decision',
            'verification_status' => 'verified',
        ]));
        $unreviewed->forceFill([
            'verification_decided_by' => $owner->id,
            'verification_decided_at' => now(),
        ])->save();

        $student = $this->student();
        $response = $this->actingAs($student)->getJson(
            '/api/student/houses?governorate=Tunis&city=Ariana&max_rent=500&min_bedrooms=2&furnishing_status=furnished'
        )->assertOk()
            ->assertJsonCount(1, 'houses.data')
            ->assertJsonPath('houses.data.0.id', $verified->id)
            ->assertJsonPath('houses.data.0.verification_status', 'verified');

        $this->assertNotSame($pending->id, $response->json('houses.data.0.id'));
        $this->assertNotSame($rejected->id, $response->json('houses.data.0.id'));
        $this->assertNotSame($unavailable->id, $response->json('houses.data.0.id'));
        $this->assertNotSame($unreviewed->id, $response->json('houses.data.0.id'));

        $this->actingAs($student)->getJson('/api/student/houses?available=0')
            ->assertOk()
            ->assertJsonCount(0, 'houses.data');
        $this->actingAs($student)->getJson('/api/student/houses/'.$unavailable->id)->assertNotFound();
    }

    public function test_invalid_search_filters_are_rejected_and_results_are_paginated(): void
    {
        $owner = $this->owner();
        for ($index = 0; $index < 13; $index++) {
            $this->verifiedHouse($owner, ['title' => 'Verified house '.$index]);
        }

        $this->actingAs($this->student())->getJson('/api/student/houses?page=1')
            ->assertOk()
            ->assertJsonCount(12, 'houses.data')
            ->assertJsonPath('houses.last_page', 2);

        $this->actingAs($this->student())->getJson('/api/student/houses?max_rent=-1')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('max_rent');

        $this->actingAs($this->student())->getJson('/api/student/houses?furnishing_status=unknown-value')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('furnishing_status');
    }

    public function test_max_rent_filter_uses_public_price_not_the_private_owner_rent(): void
    {
        $house = $this->verifiedHouse($this->owner(), [
            'monthly_rent_tnd' => '400.000',
            'public_listing_price_tnd' => '420.000',
        ]);
        $student = $this->student();

        $this->actingAs($student)->getJson('/api/student/houses?max_rent=410')
            ->assertOk()
            ->assertJsonCount(0, 'houses.data');
        $this->actingAs($student)->getJson('/api/student/houses?max_rent=420')
            ->assertOk()
            ->assertJsonCount(1, 'houses.data')
            ->assertJsonPath('houses.data.0.monthly_rent_tnd', '420.000')
            ->assertJsonMissingPath('houses.data.0.original_owner_rent_tnd');
    }

    public function test_student_house_details_include_public_inspection_summary_but_hide_private_notes_and_owner_contact(): void
    {
        $house = $this->verifiedHouse($this->owner());

        $this->actingAs($this->student())->getJson('/api/student/houses/'.$house->id)
            ->assertOk()
            ->assertJsonPath('house.owner.name', $house->owner->name)
            ->assertJsonPath('house.inspection.checklist.electricity', 'good')
            ->assertJsonPath('house.inspection.defects_found', 'Small window crack')
            ->assertJsonMissingPath('house.inspection.notes')
            ->assertJsonMissingPath('house.owner.email')
            ->assertJsonMissingPath('house.owner.phone')
            ->assertJsonMissingPath('house.inspection.worker');
    }

    public function test_verified_house_photos_are_served_only_to_authenticated_students(): void
    {
        Storage::fake('public');
        $house = $this->verifiedHouse($this->owner());
        $path = 'houses/'.$house->id.'/student-photo.jpg';
        Storage::disk('public')->put($path, 'image contents');
        $photo = $house->photos()->create(['disk' => 'public', 'path' => $path]);
        $student = $this->student();

        $this->get('/api/student/houses/'.$house->id.'/photos/'.$photo->id)->assertUnauthorized();

        $this->actingAs($student)->getJson('/api/student/houses/'.$house->id)
            ->assertOk()
            ->assertJsonPath('house.photos.0.url', '/api/student/houses/'.$house->id.'/photos/'.$photo->id);

        $this->actingAs($student)->get('/api/student/houses/'.$house->id.'/photos/'.$photo->id)
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_house_details_and_photos_are_not_available_for_pending_or_rejected_houses(): void
    {
        $this->getJson('/api/student/houses')->assertUnauthorized();

        $owner = $this->owner();
        $pending = $owner->houses()->create($this->houseAttributes());
        $rejected = $this->verifiedHouse($owner, ['verification_status' => 'rejected']);

        $this->actingAs($this->student())->getJson('/api/student/houses/'.$pending->id)->assertNotFound();
        $this->actingAs($this->student())->getJson('/api/student/houses/'.$rejected->id)->assertNotFound();
        $this->actingAs($owner)->getJson('/api/student/houses')->assertForbidden();
    }

    public function test_student_can_request_only_verified_available_houses_and_cannot_duplicate_an_active_request(): void
    {
        $owner = $this->owner();
        $house = $this->verifiedHouse($owner);
        $student = $this->student();
        $otherStudent = $this->student('other-student@example.test');

        $this->actingAs($student)->postJson('/api/student/rental-requests', [
            'house_id' => $house->id,
            'student_id' => $otherStudent->id,
        ])->assertCreated()
            ->assertJsonPath('rental_request.status', 'pending')
            ->assertJsonPath('rental_request.service_fee_payment_status', 'not_due')
            ->assertJsonPath('rental_request.student_confirmed_secured', false);

        $rentalRequest = RentalRequest::query()->firstOrFail();
        $this->assertSame($student->id, $rentalRequest->student_id);
        $this->assertSame('not_due', $rentalRequest->service_fee_payment_status);
        $this->assertSame(0, Payment::query()->where('payment_type', Payment::TYPE_STUDENT_SERVICE_FEE)->count());

        $this->actingAs($student)->postJson('/api/student/rental-requests', ['house_id' => $house->id])
            ->assertStatus(409);

        $pending = $owner->houses()->create($this->houseAttributes(['verification_status' => 'pending_verification']));
        $unavailable = $this->verifiedHouse($owner, ['available' => false]);
        $this->actingAs($otherStudent)->postJson('/api/student/rental-requests', ['house_id' => $pending->id])
            ->assertStatus(409);
        $this->actingAs($otherStudent)->postJson('/api/student/rental-requests', ['house_id' => $unavailable->id])
            ->assertStatus(409);

        $this->actingAs($student)->getJson('/api/student/rental-requests')
            ->assertOk()
            ->assertJsonCount(1, 'rental_requests')
            ->assertJsonPath('rental_requests.0.id', $rentalRequest->id);
    }

    public function test_owner_can_only_manage_requests_for_owned_houses_and_can_accept_or_reject_pending_requests(): void
    {
        $house = $this->verifiedHouse($this->owner());
        $otherHouse = $this->verifiedHouse($this->owner('another-owner@example.test'));
        $student = $this->student();
        $mine = $this->createRentalRequest($student, $house);
        $other = $this->createRentalRequest($student, $otherHouse);

        $owner = $house->owner;
        $this->actingAs($owner)->getJson('/api/owner/rental-requests')
            ->assertOk()
            ->assertJsonCount(1, 'rental_requests')
            ->assertJsonPath('rental_requests.0.id', $mine->id)
            ->assertJsonPath('rental_requests.0.student.email', $student->email);

        $this->actingAs($owner)->postJson('/api/owner/rental-requests/'.$other->id.'/decision', ['decision' => 'accepted'])
            ->assertNotFound();

        $this->actingAs($owner)->postJson('/api/owner/rental-requests/'.$mine->id.'/decision', ['decision' => 'accepted'])
            ->assertOk()
            ->assertJsonPath('rental_request.status', 'accepted');

        $rejected = $this->createRentalRequest($this->student('second-student@example.test'), $house);
        $this->actingAs($owner)->postJson('/api/owner/rental-requests/'.$rejected->id.'/decision', ['decision' => 'rejected'])
            ->assertOk()
            ->assertJsonPath('rental_request.status', 'rejected');

        $this->actingAs($student)->getJson('/api/owner/rental-requests')->assertForbidden();
    }

    public function test_only_one_student_request_for_a_house_can_be_accepted(): void
    {
        $house = $this->verifiedHouse($this->owner());
        $first = $this->createRentalRequest($this->student(), $house);
        $second = $this->createRentalRequest($this->student('second-student@example.test'), $house);
        $owner = $house->owner;

        $this->actingAs($owner)->postJson('/api/owner/rental-requests/'.$first->id.'/decision', ['decision' => 'accepted'])
            ->assertOk();
        $this->actingAs($owner)->postJson('/api/owner/rental-requests/'.$second->id.'/decision', ['decision' => 'accepted'])
            ->assertStatus(409);
        $this->assertSame('pending', $second->fresh()->status);
    }

    public function test_rental_is_completed_only_after_both_confirm_and_fee_becomes_due_not_paid(): void
    {
        config(['student_housing.student_service_fee_tnd' => '21.250']);
        $house = $this->verifiedHouse($this->owner());
        $student = $this->student();
        $owner = $house->owner;
        $rentalRequest = $this->createRentalRequest($student, $house, ['status' => 'accepted']);

        $this->actingAs($student)->postJson('/api/student/rental-requests/'.$rentalRequest->id.'/confirm-secured')
            ->assertOk()
            ->assertJsonPath('rental_request.status', 'accepted')
            ->assertJsonPath('rental_request.student_confirmed_secured', true)
            ->assertJsonPath('rental_request.owner_confirmed_secured', false)
            ->assertJsonPath('rental_request.service_fee_payment_status', 'not_due');

        $this->assertFalse($rentalRequest->fresh()->secured_house);

        $this->actingAs($owner)->postJson('/api/owner/rental-requests/'.$rentalRequest->id.'/confirm-secured')
            ->assertOk()
            ->assertJsonPath('rental_request.status', 'completed')
            ->assertJsonPath('rental_request.secured_house', true)
            ->assertJsonPath('rental_request.service_fee_payment_status', 'due');

        $completed = $rentalRequest->fresh();
        $this->assertNotNull($completed->secured_at);
        $this->assertSame('21.250', $completed->service_fee_tnd);
        $this->assertSame('due', $completed->service_fee_payment_status);
        $this->assertFalse($completed->service_fee_payment_status === 'paid');
        $this->assertFalse($house->fresh()->available);
    }

    public function test_student_cannot_change_request_owner_or_cancel_another_students_request(): void
    {
        $house = $this->verifiedHouse($this->owner());
        $student = $this->student();
        $otherStudent = $this->student('other-student@example.test');
        $request = $this->createRentalRequest($student, $house);

        $this->actingAs($otherStudent)->postJson('/api/student/rental-requests/'.$request->id.'/cancel')
            ->assertNotFound();
        $this->actingAs($otherStudent)->postJson('/api/student/rental-requests/'.$request->id.'/confirm-secured')
            ->assertNotFound();
        $this->actingAs($student)->postJson('/api/student/rental-requests/'.$request->id.'/cancel')
            ->assertOk()
            ->assertJsonPath('rental_request.status', 'cancelled');
        $this->assertSame($student->id, $request->fresh()->student_id);
    }

    public function test_student_dashboard_uses_database_requests_fee_and_verified_recent_houses(): void
    {
        $house = $this->verifiedHouse($this->owner());
        $student = $this->student();
        $request = $this->createRentalRequest($student, $house);

        $this->actingAs($student)->getJson('/api/student/dashboard')
            ->assertOk()
            ->assertJsonPath('dashboard', 'student')
            ->assertJsonPath('account.email', $student->email)
            ->assertJsonPath('student_service_fee_tnd', '20.000')
            ->assertJsonCount(1, 'recent_houses')
            ->assertJsonPath('recent_houses.0.verification_status', 'verified')
            ->assertJsonCount(1, 'active_requests')
            ->assertJsonPath('active_requests.0.id', $request->id)
            ->assertJsonCount(0, 'request_history');
    }

    private function owner(?string $email = null): User
    {
        $owner = User::factory()->create($email ? ['email' => $email] : []);
        $owner->forceFill(['role' => 'owner'])->save();

        return $owner;
    }

    private function student(?string $email = null): User
    {
        $student = User::factory()->create($email ? ['email' => $email] : []);
        $student->forceFill(['role' => 'student'])->save();

        return $student;
    }

    private function worker(?string $email = null): User
    {
        $worker = User::factory()->create($email ? ['email' => $email] : []);
        $worker->forceFill(['role' => 'worker', 'governorate' => 'Tunis'])->save();

        return $worker;
    }

    private function admin(?string $email = null): User
    {
        $admin = User::factory()->create($email ? ['email' => $email] : []);
        $admin->forceFill(['role' => 'admin'])->save();

        return $admin;
    }

    private function verifiedHouse(User $owner, array $overrides = []): House
    {
        $house = $owner->houses()->create($this->houseAttributes($overrides));
        $house->forceFill([
            'public_listing_price_tnd' => $overrides['public_listing_price_tnd']
                ?? $overrides['monthly_rent_tnd']
                ?? '500.000',
        ])->save();
        $admin = $this->admin();
        $worker = $this->worker();

        $house->forceFill([
            'verification_status' => $overrides['verification_status'] ?? 'verified',
            'verification_decided_by' => $admin->id,
            'verification_decided_at' => now(),
        ])->save();

        $house->inspectionReports()->create([
            'worker_id' => $worker->id,
            'inspection_date' => now()->subDay(),
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
            'defects_found' => 'Small window crack',
            'notes' => 'Internal worker note that should not be public.',
            'status' => 'completed',
            'recommendation' => 'approve',
            'completed_at' => now()->subDay(),
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        return $house;
    }

    private function houseAttributes(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Student-friendly house',
            'description' => 'A comfortable place near campus.',
            'governorate' => 'Tunis',
            'city' => 'Tunis',
            'address' => 'Central area',
            'monthly_rent_tnd' => '500.000',
            'bedrooms' => 2,
            'furnishing_status' => 'unfurnished',
            'available' => true,
            'verification_status' => 'pending_verification',
        ], $overrides);
    }

    private function createRentalRequest(User $student, House $house, array $overrides = []): RentalRequest
    {
        return $student->rentalRequestsAsStudent()->create(array_merge([
            'house_id' => $house->id,
            'requested_at' => now(),
            'status' => 'pending',
            'service_fee_tnd' => '20.000',
            'service_fee_payment_status' => 'not_due',
        ], $overrides));
    }
}
