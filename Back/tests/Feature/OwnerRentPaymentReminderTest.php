<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\RentalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class OwnerRentPaymentReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_secured_rentals_generate_idempotent_monthly_owner_reminders_for_agreed_rent(): void
    {
        $owner = $this->owner('owner@example.test');
        $otherOwner = $this->owner('other-owner@example.test');
        $student = User::factory()->create();
        $house = $owner->houses()->create([
            'title' => 'Agreed rent house',
            'governorate' => 'Tunis',
            'city' => 'Tunis',
            'monthly_rent_tnd' => '400.000',
            'bedrooms' => 2,
            'furnishing_status' => 'furnished',
            'available' => false,
            'verification_status' => 'verified',
        ]);
        $house->forceFill([
            'agreed_owner_rent_tnd' => '380.000',
            'public_listing_price_tnd' => '400.000',
        ])->save();
        $securedAt = now()->subMonths(2)->startOfDay();
        $rental = RentalRequest::query()->create([
            'student_id' => $student->id,
            'house_id' => $house->id,
            'status' => 'completed',
            'secured_house' => true,
            'secured_at' => $securedAt,
            'student_confirmed_secured_at' => $securedAt,
            'owner_confirmed_secured_at' => $securedAt,
        ]);
        $pendingHouse = $otherOwner->houses()->create([
            'title' => 'Pending house',
            'governorate' => 'Tunis',
            'city' => 'Tunis',
            'monthly_rent_tnd' => '300.000',
            'bedrooms' => 1,
            'furnishing_status' => 'furnished',
            'available' => true,
            'verification_status' => 'verified',
        ]);
        RentalRequest::query()->create([
            'student_id' => $student->id,
            'house_id' => $pendingHouse->id,
            'status' => 'accepted',
            'secured_house' => false,
        ]);

        Artisan::call('rentals:send-monthly-owner-reminders');

        $this->assertDatabaseCount('owner_rent_payment_reminders', 2);
        $this->assertDatabaseHas('owner_rent_payment_reminders', [
            'rental_request_id' => $rental->id,
            'owner_id' => $owner->id,
            'amount_tnd' => '380.000',
        ]);

        Artisan::call('rentals:send-monthly-owner-reminders');
        $this->assertDatabaseCount('owner_rent_payment_reminders', 2);

        $response = $this->actingAs($owner)->getJson('/api/owner/rent-payment-reminders')
            ->assertOk()
            ->assertJsonPath('unread_count', 2)
            ->assertJsonPath('payment_available', false)
            ->assertJsonPath('reminders.0.amount_tnd', '380.000');
        $this->assertStringContainsString(
            'has not collected or transferred',
            $response->json('reminders.0.message')
        );

        $reminderId = $response->json('reminders.0.id');
        $this->actingAs($otherOwner)->postJson('/api/owner/rent-payment-reminders/'.$reminderId.'/read')
            ->assertNotFound();
        $this->actingAs($owner)->postJson('/api/owner/rent-payment-reminders/'.$reminderId.'/read')
            ->assertOk();
    }

    private function owner(string $email): User
    {
        $owner = User::factory()->create(['email' => $email]);
        $owner->forceFill(['role' => 'owner'])->save();

        return $owner;
    }
}
