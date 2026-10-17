<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\InspectionReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HousePriceNegotiationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
    }

    public function test_new_house_is_private_until_staff_records_a_phone_price_agreement(): void
    {
        $owner = $this->user('owner', 'owner@example.test');
        $houseResponse = $this->actingAs($owner)->post('/api/owner/houses', $this->submissionData());
        $houseResponse->assertCreated()
            ->assertJsonPath('house.monthly_rent_tnd', '400.000')
            ->assertJsonPath('house.public_listing_price_tnd', null)
            ->assertJsonPath('house.negotiation_status', 'pending')
            ->assertJsonPath('house.verification_status', 'pending_negotiation');
        $houseId = $houseResponse->json('house.id');

        $this->actingAs($owner)->putJson('/api/owner/houses/'.$houseId, [
            ...$this->houseData(),
            'public_listing_price_tnd' => '1.000',
            'agreed_owner_rent_tnd' => '1.000',
            'negotiation_status' => 'price_agreed',
            'verification_status' => 'verified',
        ])->assertOk()
            ->assertJsonPath('house.public_listing_price_tnd', null)
            ->assertJsonPath('house.verification_status', 'pending_negotiation')
            ->assertJsonPath('house.negotiation_status', 'pending');

        $student = $this->user('student', 'student@example.test');
        $this->actingAs($student)->getJson('/api/student/houses')
            ->assertOk()
            ->assertJsonCount(0, 'houses.data');
        $this->actingAs($student)->getJson('/api/student/houses/'.$houseId)->assertNotFound();
        $this->actingAs($student)->get('/api/admin/houses/'.$houseId.'/photos/'.$houseResponse->json('house.photos.0.id'))
            ->assertForbidden();
        $this->actingAs($student)->getJson('/api/admin/price-negotiations')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/admin/price-negotiations')->assertForbidden();
        $this->actingAs($owner)->patchJson('/api/admin/houses/'.$houseId.'/price-negotiation', [
            'negotiation_status' => 'price_agreed',
            'agreed_owner_rent_tnd' => '400.000',
        ])->assertForbidden();

        $admin = $this->user('admin', 'admin@example.test');
        $worker = $this->user('worker', 'worker@example.test', ['governorate' => 'Tunis']);
        $this->actingAs($admin)->postJson('/api/admin/houses/'.$houseId.'/inspection-assignment', [
            'worker_id' => $worker->id,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertStatus(409);
        $this->actingAs($admin)->patchJson('/api/admin/houses/'.$houseId.'/price-negotiation', [
            'negotiation_status' => 'price_agreed',
            'agreed_owner_rent_tnd' => '300.000',
        ])->assertStatus(409);
        $this->actingAs($worker)->getJson('/api/admin/price-negotiations')->assertForbidden();

        $this->actingAs($admin)->getJson('/api/admin/price-negotiations')
            ->assertOk()
            ->assertJsonPath('houses.0.original_owner_rent_tnd', '400.000')
            ->assertJsonPath('houses.0.negotiation_status', 'pending')
            ->assertJsonPath('houses.0.negotiation_notes', null)
            ->assertJsonPath('houses.0.owner.email', $owner->email)
            ->assertJsonPath('houses.0.negotiation_last_contact_at', null);
        $this->actingAs($student)->getJson('/api/student/houses')
            ->assertOk()
            ->assertJsonCount(0, 'houses.data');
    }

    public function test_admin_accepts_or_rejects_new_submissions_and_owners_see_review_status(): void
    {
        $owner = $this->user('owner', 'owner@example.test');
        $ownerHouseResponse = $this->actingAs($owner)->post('/api/owner/houses', $this->submissionData())
            ->assertCreated()
            ->assertJsonPath('house.verification_status', 'pending_negotiation')
            ->assertJsonPath('house.negotiation_status', 'pending')
            ->assertJsonCount(4, 'house.photos');
        $ownerHouseId = $ownerHouseResponse->json('house.id');

        $student = $this->user('student', 'student@example.test');
        $admin = $this->user('admin', 'admin@example.test');
        $this->actingAs($student)->getJson('/api/admin/price-negotiations')->assertForbidden();
        $this->actingAs($owner)->postJson('/api/admin/houses/'.$ownerHouseId.'/submission-decision', [
            'decision' => 'accept',
        ])->assertForbidden();

        $this->actingAs($admin)->getJson('/api/admin/price-negotiations')
            ->assertOk()
            ->assertJsonCount(1, 'houses')
            ->assertJsonPath('houses.0.id', $ownerHouseId)
            ->assertJsonPath('houses.0.negotiation_status', 'pending')
            ->assertJsonPath('houses.0.photo_count', 4)
            ->assertJsonPath('houses.0.suggested_owner_rent_tnd', '380.000')
            ->assertJsonPath('houses.0.refusal_public_price_tnd', '420.000')
            ->assertJsonCount(4, 'houses.0.photos');

        $this->actingAs($admin)->postJson('/api/admin/houses/'.$ownerHouseId.'/submission-decision', [
            'decision' => 'accept',
        ])->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->actingAs($admin)->postJson('/api/admin/houses/'.$ownerHouseId.'/submission-decision', [
            'decision' => 'owner_accepted',
            'public_listing_price_tnd' => '1.000',
        ])->assertUnprocessable()->assertJsonValidationErrors('public_listing_price_tnd');

        $this->actingAs($admin)->postJson('/api/admin/houses/'.$ownerHouseId.'/submission-decision', [
            'decision' => 'owner_accepted',
        ])->assertOk()
            ->assertJsonPath('house.verification_status', 'verified')
            ->assertJsonPath('house.negotiation_status', 'owner_accepted')
            ->assertJsonPath('house.proposed_owner_rent_tnd', '380.000')
            ->assertJsonPath('house.agreed_owner_rent_tnd', '380.000')
            ->assertJsonPath('house.public_listing_price_tnd', '400.000')
            ->assertJsonPath('house.submission_reviewed_at', fn ($value) => is_string($value));

        $this->actingAs($owner)->getJson('/api/owner/houses')
            ->assertOk()
            ->assertJsonPath('houses.0.id', $ownerHouseId)
            ->assertJsonPath('houses.0.verification_status', 'verified')
            ->assertJsonPath('houses.0.public_listing_price_tnd', '400.000')
            ->assertJsonPath('houses.0.agreed_owner_rent_tnd', '380.000')
            ->assertJsonPath('houses.0.submission_reviewed_at', fn ($value) => is_string($value));

        $this->actingAs($student)->getJson('/api/student/houses')
            ->assertOk()
            ->assertJsonCount(1, 'houses.data')
            ->assertJsonPath('houses.data.0.id', $ownerHouseId)
            ->assertJsonPath('houses.data.0.public_listing_price_tnd', '400.000')
            ->assertJsonMissingPath('houses.data.0.original_owner_rent_tnd')
            ->assertJsonMissingPath('houses.data.0.negotiation_status');
        $this->actingAs($student)->getJson('/api/student/houses/'.$ownerHouseId)
            ->assertOk()
            ->assertJsonPath('house.monthly_rent_tnd', '400.000')
            ->assertJsonMissingPath('house.negotiation_notes');

        $rejectedOwner = $this->user('owner', 'rejected-owner@example.test');
        $rejectedResponse = $this->actingAs($rejectedOwner)->post('/api/owner/houses', $this->submissionData())
            ->assertCreated();
        $rejectedHouseId = $rejectedResponse->json('house.id');

        $this->actingAs($admin)->postJson('/api/admin/houses/'.$rejectedHouseId.'/submission-decision', [
            'decision' => 'reject',
        ])->assertOk()
            ->assertJsonPath('house.verification_status', 'rejected')
            ->assertJsonPath('house.negotiation_status', 'rejected');
        $this->actingAs($admin)->postJson('/api/admin/houses/'.$rejectedHouseId.'/submission-decision', [
            'decision' => 'owner_refused',
        ])->assertStatus(409);

        $this->actingAs($rejectedOwner)->getJson('/api/owner/houses')
            ->assertOk()
            ->assertJsonPath('houses.0.verification_status', 'rejected')
            ->assertJsonPath('houses.0.negotiation_status', 'rejected')
            ->assertJsonPath('houses.0.public_listing_price_tnd', null)
            ->assertJsonPath('houses.0.submission_reviewed_at', fn ($value) => is_string($value));
        $this->actingAs($student)->getJson('/api/student/houses')
            ->assertOk()
            ->assertJsonCount(1, 'houses.data')
            ->assertJsonPath('houses.data.0.id', $ownerHouseId);
    }

    public function test_owner_refusal_increases_public_price_by_five_percent_and_rounds_to_nearest_ten(): void
    {
        $admin = $this->user('admin', 'admin@example.test');
        $student = $this->user('student', 'student@example.test');
        $ownerBelowFive = $this->user('owner', 'owner-480@example.test');
        $ownerAboveFive = $this->user('owner', 'owner-491@example.test');
        $belowFiveResponse = $this->actingAs($ownerBelowFive)->post('/api/owner/houses', [
            ...$this->houseData(),
            'monthly_rent_tnd' => '480.000',
            'photos' => $this->photos(),
        ])->assertCreated();
        $aboveFiveResponse = $this->actingAs($ownerAboveFive)->post('/api/owner/houses', [
            ...$this->houseData(),
            'monthly_rent_tnd' => '491.000',
            'photos' => $this->photos(),
        ])->assertCreated();
        $belowFiveId = $belowFiveResponse->json('house.id');
        $aboveFiveId = $aboveFiveResponse->json('house.id');

        $this->actingAs($admin)->postJson('/api/admin/houses/'.$belowFiveId.'/submission-decision', [
            'decision' => 'owner_refused',
        ])->assertOk()
            ->assertJsonPath('house.negotiation_status', 'owner_refused')
            ->assertJsonPath('house.agreed_owner_rent_tnd', '480.000')
            ->assertJsonPath('house.public_listing_price_tnd', '500.000');

        $this->actingAs($admin)->postJson('/api/admin/houses/'.$aboveFiveId.'/submission-decision', [
            'decision' => 'owner_refused',
        ])->assertOk()
            ->assertJsonPath('house.public_listing_price_tnd', '520.000');

        $this->actingAs($student)->getJson('/api/student/houses')
            ->assertOk()
            ->assertJsonCount(2, 'houses.data')
            ->assertJsonFragment(['public_listing_price_tnd' => '520.000'])
            ->assertJsonFragment(['public_listing_price_tnd' => '500.000'])
            ->assertJsonMissingPath('houses.data.0.agreed_owner_rent_tnd');
    }

    public function test_refusal_price_that_exceeds_supported_limit_is_not_published(): void
    {
        $owner = $this->user('owner', 'owner-high-rent@example.test');
        $admin = $this->user('admin', 'admin@example.test');
        $houseResponse = $this->actingAs($owner)->post('/api/owner/houses', [
            ...$this->houseData(),
            'monthly_rent_tnd' => '9999999.999',
            'photos' => $this->photos(),
        ])->assertCreated();
        $houseId = $houseResponse->json('house.id');

        $this->actingAs($admin)->postJson('/api/admin/houses/'.$houseId.'/submission-decision', [
            'decision' => 'owner_refused',
        ])->assertUnprocessable()->assertJsonValidationErrors('decision');

        $this->assertSame('pending_negotiation', House::findOrFail($houseId)->verification_status);
        $this->assertNull(House::findOrFail($houseId)->public_listing_price_tnd);
    }

    public function test_owner_must_upload_at_least_four_photos_to_submit_a_house(): void
    {
        $owner = $this->user('owner', 'owner@example.test');

        $this->actingAs($owner)->post('/api/owner/houses', $this->submissionData(3))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photos');

        $this->assertDatabaseCount('houses', 0);
    }

    public function test_admin_cannot_accept_a_listing_without_four_stored_photos(): void
    {
        $owner = $this->user('owner', 'owner@example.test');
        $admin = $this->user('admin', 'admin@example.test');
        $house = $this->pendingHouse($owner, '400.000', 'Legacy listing with too few photos', 'pending');

        $this->actingAs($admin)->postJson('/api/admin/houses/'.$house->id.'/submission-decision', [
            'decision' => 'owner_accepted',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'At least four owner-uploaded photos are required before this house can be accepted and published.');

        $this->assertSame('pending_negotiation', $house->fresh()->verification_status);
        $this->assertNull($house->fresh()->public_listing_price_tnd);
    }

    public function test_public_price_is_calculated_from_the_confirmed_owner_rent(): void
    {
        config(['student_housing.listing_margin_tnd' => '20.000']);
        $owner = $this->user('owner', 'owner@example.test');
        $admin = $this->user('admin', 'admin@example.test');
        $keepPrice = $this->pendingHouse($owner, '400.000', 'Keep original rent');
        $acceptLowerPrice = $this->pendingHouse($owner, '400.000', 'Accept lower rent');

        $this->actingAs($admin)->patchJson('/api/admin/houses/'.$keepPrice->id.'/price-negotiation', [
            'negotiation_status' => 'price_agreed',
            'agreed_owner_rent_tnd' => '400.000',
        ])->assertOk()
            ->assertJsonPath('house.agreed_owner_rent_tnd', '400.000')
            ->assertJsonPath('house.public_listing_price_tnd', '420.000')
            ->assertJsonPath('house.verification_status', 'pending_verification');

        $this->actingAs($admin)->patchJson('/api/admin/houses/'.$acceptLowerPrice->id.'/price-negotiation', [
            'negotiation_status' => 'price_agreed',
            'proposed_owner_rent_tnd' => '380.000',
            'agreed_owner_rent_tnd' => '380.000',
        ])->assertOk()
            ->assertJsonPath('house.agreed_owner_rent_tnd', '380.000')
            ->assertJsonPath('house.public_listing_price_tnd', '400.000')
            ->assertJsonPath('house.verification_status', 'pending_verification');

        $this->assertSame('400.000', $keepPrice->fresh()->monthly_rent_tnd);
        $this->assertSame('420.000', $keepPrice->fresh()->public_listing_price_tnd);
        $this->assertSame('400.000', $acceptLowerPrice->fresh()->public_listing_price_tnd);
    }

    public function test_price_agreement_does_not_publish_until_approved_inspection_and_public_api_hides_private_fields(): void
    {
        $owner = $this->user('owner', 'owner@example.test');
        $admin = $this->user('admin', 'admin@example.test');
        $worker = $this->user('worker', 'worker@example.test', ['governorate' => 'Tunis']);
        $student = $this->user('student', 'student@example.test');
        $house = $this->pendingHouse($owner, '400.000', 'Approved workflow home');

        $this->actingAs($admin)->patchJson('/api/admin/houses/'.$house->id.'/price-negotiation', [
            'negotiation_status' => 'price_agreed',
            'agreed_owner_rent_tnd' => '380.000',
            'negotiation_notes' => 'Private notes: owner confirmed by phone.',
        ])->assertOk();
        $this->actingAs($admin)->getJson('/api/admin/price-negotiations')
            ->assertOk()
            ->assertJsonPath('houses.0.id', $house->id);
        $this->actingAs($student)->getJson('/api/student/houses')
            ->assertOk()
            ->assertJsonCount(0, 'houses.data');

        $this->actingAs($admin)->postJson('/api/admin/houses/'.$house->id.'/inspection-assignment', [
            'worker_id' => $worker->id,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated();
        $inspection = InspectionReport::where('house_id', $house->id)->firstOrFail();

        $this->actingAs($worker)->postJson('/api/worker/inspections/'.$inspection->id.'/complete', [
            ...$this->completedReportData(),
        ])->assertOk();
        $this->actingAs($student)->getJson('/api/student/houses')
            ->assertOk()
            ->assertJsonCount(0, 'houses.data');

        $this->actingAs($admin)->postJson('/api/admin/inspections/'.$inspection->id.'/decision', [
            'decision' => 'verified',
        ])->assertOk();

        $studentResponse = $this->actingAs($student)->getJson('/api/student/houses')
            ->assertOk()
            ->assertJsonCount(1, 'houses.data')
            ->assertJsonPath('houses.data.0.monthly_rent_tnd', '400.000')
            ->assertJsonPath('houses.data.0.public_listing_price_tnd', '400.000')
            ->assertJsonMissingPath('houses.data.0.original_owner_rent_tnd')
            ->assertJsonMissingPath('houses.data.0.agreed_owner_rent_tnd')
            ->assertJsonMissingPath('houses.data.0.proposed_owner_rent_tnd')
            ->assertJsonMissingPath('houses.data.0.negotiation_status')
            ->assertJsonMissingPath('houses.data.0.negotiation_notes')
            ->assertJsonMissingPath('houses.data.0.negotiation_last_contact_at');
        $this->actingAs($admin)->getJson('/api/admin/price-negotiations')
            ->assertOk()
            ->assertJsonCount(0, 'houses');

        $this->assertSame($house->id, $studentResponse->json('houses.data.0.id'));
        $this->actingAs($student)->getJson('/api/student/houses/'.$house->id)
            ->assertOk()
            ->assertJsonPath('house.monthly_rent_tnd', '400.000')
            ->assertJsonMissingPath('house.negotiation_notes')
            ->assertJsonMissingPath('house.original_owner_rent_tnd');

        $rentalRequest = $this->actingAs($student)->postJson('/api/student/rental-requests', [
            'house_id' => $house->id,
        ])->assertCreated()
            ->assertJsonPath('rental_request.house.monthly_rent_tnd', '400.000')
            ->json('rental_request');

        $this->actingAs($student)->getJson('/api/student/rental-requests')
            ->assertOk()
            ->assertJsonPath('rental_requests.0.house.monthly_rent_tnd', $rentalRequest['house']['monthly_rent_tnd']);
    }

    public function test_staff_cannot_agree_to_a_rent_above_the_original_or_change_a_locked_agreement(): void
    {
        $owner = $this->user('owner', 'owner@example.test');
        $admin = $this->user('admin', 'admin@example.test');
        $house = $this->pendingHouse($owner, '400.000');

        $this->actingAs($admin)->patchJson('/api/admin/houses/'.$house->id.'/price-negotiation', [
            'negotiation_status' => 'negotiation_in_progress',
            'proposed_owner_rent_tnd' => '401.000',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('proposed_owner_rent_tnd');

        $this->actingAs($admin)->patchJson('/api/admin/houses/'.$house->id.'/price-negotiation', [
            'negotiation_status' => 'negotiation_in_progress',
            'proposed_owner_rent_tnd' => '380.000',
        ])->assertOk();
        $this->actingAs($admin)->patchJson('/api/admin/houses/'.$house->id.'/price-negotiation', [
            'negotiation_status' => 'negotiation_in_progress',
            'proposed_owner_rent_tnd' => null,
        ])->assertOk()
            ->assertJsonPath('house.proposed_owner_rent_tnd', null);

        $this->actingAs($admin)->patchJson('/api/admin/houses/'.$house->id.'/price-negotiation', [
            'negotiation_status' => 'price_agreed',
            'agreed_owner_rent_tnd' => '401.000',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('agreed_owner_rent_tnd');

        $this->actingAs($admin)->patchJson('/api/admin/houses/'.$house->id.'/price-negotiation', [
            'negotiation_status' => 'price_agreed',
            'agreed_owner_rent_tnd' => '400.000',
        ])->assertOk();

        $this->actingAs($admin)->patchJson('/api/admin/houses/'.$house->id.'/price-negotiation', [
            'negotiation_status' => 'no_agreement',
        ])->assertStatus(409);

        $this->actingAs($owner)->putJson('/api/owner/houses/'.$house->id, [
            ...$this->houseData(),
            'monthly_rent_tnd' => '399.000',
            'public_listing_price_tnd' => '1.000',
            'verification_status' => 'verified',
            'agreed_owner_rent_tnd' => '1.000',
        ])->assertStatus(409);

        $this->assertSame('400.000', $house->fresh()->monthly_rent_tnd);
        $this->assertSame('420.000', $house->fresh()->public_listing_price_tnd);
        $this->assertSame('pending_verification', $house->fresh()->verification_status);
    }

    public function test_agreement_is_rejected_if_the_margin_would_overflow_the_public_price_column(): void
    {
        $owner = $this->user('owner', 'owner@example.test');
        $admin = $this->user('admin', 'admin@example.test');
        $house = $this->pendingHouse($owner, '9999999.999');

        $this->actingAs($admin)->patchJson('/api/admin/houses/'.$house->id.'/price-negotiation', [
            'negotiation_status' => 'price_agreed',
            'agreed_owner_rent_tnd' => '9999999.999',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('agreed_owner_rent_tnd');

        $this->assertNull($house->fresh()->public_listing_price_tnd);
        $this->assertSame('pending_negotiation', $house->fresh()->verification_status);
    }

    private function pendingHouse(
        User $owner,
        string $rent,
        string $title = 'Negotiation test house',
        string $negotiationStatus = 'negotiation_in_progress'
    ): House {
        $house = $owner->houses()->create([
            ...$this->houseData(),
            'title' => $title,
            'monthly_rent_tnd' => $rent,
            'verification_status' => 'pending_negotiation',
            'available' => true,
        ]);
        $house->forceFill(['negotiation_status' => $negotiationStatus])->save();

        return $house;
    }

    private function user(string $role, string $email, array $extra = []): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->forceFill(['role' => $role, ...$extra])->save();

        return $user;
    }

    private function houseData(): array
    {
        return [
            'title' => 'Negotiation test house',
            'description' => 'A home near the university.',
            'governorate' => 'Tunis',
            'city' => 'Tunis',
            'address' => 'Central area',
            'monthly_rent_tnd' => '400.000',
            'bedrooms' => 2,
            'furnishing_status' => 'furnished',
            'available' => true,
        ];
    }

    private function submissionData(int $photoCount = 4): array
    {
        return [
            ...$this->houseData(),
            'photos' => $this->photos($photoCount),
        ];
    }

    private function photos(int $photoCount = 4): array
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');

        return array_map(
            fn (int $index) => UploadedFile::fake()->createWithContent('house-'.$index.'.png', $png),
            range(1, $photoCount)
        );
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
            'notes' => 'Inspection completed.',
            'defects_found' => '',
            'recommendation' => 'approve',
        ];
    }
}
