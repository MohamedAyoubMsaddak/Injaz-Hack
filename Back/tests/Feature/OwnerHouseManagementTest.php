<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\RentalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OwnerHouseManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_a_house_with_a_randomly_stored_photo(): void
    {
        $owner = $this->owner();
        $disk = config('filesystems.default');
        Storage::fake($disk);

        $response = $this->actingAs($owner)->post('/api/owner/houses', [
            ...$this->houseData(),
            'owner_id' => 999999,
            'verification_status' => 'verified',
            'photos' => array_map(
                fn (int $index) => UploadedFile::fake()->createWithContent(
                    'my-secret-original-name-'.$index.'.png',
                    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
                ),
                range(1, 4)
            ),
        ]);

        $response->assertCreated()
            ->assertJsonPath('house.verification_status', 'pending_negotiation')
            ->assertJsonMissingPath('house.owner_id');

        $house = House::with('photos')->firstOrFail();
        $this->assertSame($owner->id, $house->owner_id);
        $this->assertTrue($house->available);
        $this->assertCount(4, $house->photos);
        $this->assertStringNotContainsString('my-secret-original-name', $house->photos[0]->path);
        Storage::disk($disk)->assertExists($house->photos[0]->path);
        $this->get($response->json('house.photos.0.url'))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $otherOwner = $this->owner('photo-owner@example.test');
        $this->actingAs($otherOwner)->get($response->json('house.photos.0.url'))->assertNotFound();
    }

    public function test_owner_can_view_and_update_only_their_own_house(): void
    {
        $owner = $this->owner();
        $otherOwner = $this->owner('other-owner@example.test');
        $ownHouse = $this->createHouse($owner);
        $otherHouse = $this->createHouse($otherOwner, ['title' => 'Private house']);

        $this->actingAs($owner)
            ->getJson('/api/owner/houses')
            ->assertOk()
            ->assertJsonCount(1, 'houses')
            ->assertJsonPath('houses.0.id', $ownHouse->id);

        $this->actingAs($owner)
            ->getJson('/api/owner/houses/'.$ownHouse->id)
            ->assertOk()
            ->assertJsonPath('house.title', $ownHouse->title);

        $this->putJson('/api/owner/houses/'.$ownHouse->id, [
            ...$this->houseData(),
            'title' => 'Updated owner house',
            'available' => false,
        ])->assertOk()->assertJsonPath('house.available', false);

        $this->getJson('/api/owner/houses/'.$otherHouse->id)->assertNotFound();
        $this->putJson('/api/owner/houses/'.$otherHouse->id, [
            ...$this->houseData(),
            'title' => 'Tampered title',
        ])->assertNotFound();

        $this->assertSame('Updated owner house', $ownHouse->fresh()->title);
        $this->assertSame('Private house', $otherHouse->fresh()->title);
    }

    public function test_invalid_house_fields_are_rejected(): void
    {
        $this->actingAs($this->owner())
            ->postJson('/api/owner/houses', [
                ...$this->houseData(),
                'monthly_rent_tnd' => '-5',
                'bedrooms' => '0',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['monthly_rent_tnd', 'bedrooms']);
    }

    public function test_executable_or_unsupported_photo_upload_is_rejected(): void
    {
        $response = $this->actingAs($this->owner())
            ->withHeaders(['Accept' => 'application/json'])
            ->post('/api/owner/houses', [
                ...$this->houseData(),
                'photos' => [UploadedFile::fake()->create('shell.php', 10, 'application/x-php')],
            ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('photos.0');
        $this->assertDatabaseCount('houses', 0);
    }

    public function test_photo_larger_than_five_megabytes_is_rejected(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $oversizedPhoto = UploadedFile::fake()->createWithContent(
            'large-house.png',
            $png.str_repeat('x', 5 * 1024 * 1024)
        );

        $response = $this->actingAs($this->owner())
            ->withHeaders(['Accept' => 'application/json'])
            ->post('/api/owner/houses', [
                ...$this->houseData(),
                'photos' => [$oversizedPhoto],
            ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('photos.0');
        $this->assertDatabaseCount('houses', 0);
    }

    public function test_students_cannot_use_owner_house_endpoints(): void
    {
        $student = User::factory()->create();
        $student->forceFill(['role' => 'student'])->save();

        $this->actingAs($student)
            ->getJson('/api/owner/houses')
            ->assertForbidden();
    }

    public function test_owner_dashboard_counts_only_the_authenticated_owners_records(): void
    {
        $owner = $this->owner();
        $anotherOwner = $this->owner('another-owner@example.test');
        $availableHouse = $this->createHouse($owner, [
            'available' => true,
            'verification_status' => 'pending_verification',
        ]);
        $this->createHouse($owner, [
            'available' => false,
            'verification_status' => 'verified',
        ]);
        $this->createHouse($anotherOwner, ['available' => true]);

        $student = User::factory()->create();
        RentalRequest::create([
            'student_id' => $student->id,
            'house_id' => $availableHouse->id,
        ]);

        $this->actingAs($owner)
            ->getJson('/api/owner/dashboard')
            ->assertOk()
            ->assertJsonPath('counts.houses', 2)
            ->assertJsonPath('counts.available_houses', 1)
            ->assertJsonPath('counts.awaiting_verification', 1)
            ->assertJsonPath('counts.pending_rental_requests', 1)
            ->assertJsonPath('owner_subscription_monthly_tnd', config('student_housing.owner_subscription_monthly_tnd'));
    }

    public function test_subscription_information_is_scoped_to_the_owner_and_not_marked_paid(): void
    {
        $owner = $this->owner();
        $otherOwner = $this->owner('other-owner@example.test');
        $house = $this->createHouse($owner);
        $otherHouse = $this->createHouse($otherOwner);
        $owner->ownerSubscriptions()->create([
            'house_id' => $house->id,
            'monthly_price_tnd' => '20.000',
            'status' => 'pending',
            'starts_on' => now()->toDateString(),
            'payment_status' => 'unpaid',
        ]);
        $otherOwner->ownerSubscriptions()->create([
            'house_id' => $otherHouse->id,
            'monthly_price_tnd' => '20.000',
            'status' => 'active',
            'starts_on' => now()->toDateString(),
            'payment_status' => 'paid',
        ]);

        $this->actingAs($owner)
            ->getJson('/api/owner/dashboard')
            ->assertOk()
            ->assertJsonCount(1, 'subscriptions')
            ->assertJsonPath('subscriptions.0.status', 'pending')
            ->assertJsonPath('subscriptions.0.payment_status', 'unpaid');
    }

    private function owner(string $email = 'owner@example.test'): User
    {
        $owner = User::factory()->create(['email' => $email]);
        $owner->forceFill(['role' => 'owner'])->save();

        return $owner;
    }

    private function createHouse(User $owner, array $attributes = []): House
    {
        return $owner->houses()->create([
            ...$this->houseData(),
            'verification_status' => 'pending_verification',
            ...$attributes,
        ]);
    }

    private function houseData(): array
    {
        return [
            'title' => 'Student-friendly flat',
            'description' => 'A bright home near campus.',
            'governorate' => 'Tunis',
            'city' => 'Tunis',
            'address' => 'Near the university',
            'monthly_rent_tnd' => '450.000',
            'bedrooms' => 2,
            'furnishing_status' => 'furnished',
            'available' => true,
        ];
    }
}
