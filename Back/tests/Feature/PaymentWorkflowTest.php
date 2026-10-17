<?php

namespace Tests\Feature;

use App\Models\OwnerSubscription;
use App\Models\Payment;
use App\Models\RentalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_registered_house_gets_a_pending_monthly_subscription_record(): void
    {
        $owner = $this->owner();
        Storage::fake(config('filesystems.default'));
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');

        foreach (['First house', 'Second house', 'Third house'] as $title) {
            $this->actingAs($owner)->post('/api/owner/houses', [
                ...$this->houseData(),
                'title' => $title,
                'photos' => array_map(
                    fn (int $index) => UploadedFile::fake()->createWithContent($title.'-'.$index.'.png', $png),
                    range(1, 4)
                ),
            ])->assertCreated()
                ->assertJsonPath('house.verification_status', 'pending_negotiation');
        }

        $this->assertSame(3, OwnerSubscription::query()->where('owner_id', $owner->id)->count());
        $this->assertSame(3, Payment::query()->where('payer_id', $owner->id)->count());
        $this->assertDatabaseHas('payments', [
            'payer_id' => $owner->id,
            'payment_type' => Payment::TYPE_OWNER_SUBSCRIPTION,
            'amount_tnd' => config('student_housing.owner_subscription_monthly_tnd'),
            'currency' => 'TND',
            'status' => Payment::STATUS_PENDING,
        ]);

        $this->actingAs($owner)->getJson('/api/owner/dashboard')
            ->assertOk()
            ->assertJsonPath('monthly_subscription_total_tnd', '60.000')
            ->assertJsonCount(3, 'subscriptions')
            ->assertJsonPath('subscriptions.0.payment_status', 'pending')
            ->assertJsonPath('subscriptions.0.payments.0.status', 'pending')
            ->assertJsonPath('subscriptions.0.payments.0.currency', 'TND');
    }

    public function test_student_fee_is_created_only_after_both_parties_confirm_and_is_not_marked_paid(): void
    {
        [$owner, $student, $house, $rental] = $this->acceptedRental();

        $this->assertSame(0, Payment::query()->where('payment_type', Payment::TYPE_STUDENT_SERVICE_FEE)->count());
        $this->assertSame('not_due', $rental->service_fee_payment_status);
        $this->actingAs($student)->getJson('/api/student/payments')
            ->assertOk()
            ->assertJsonCount(0, 'payments');

        $this->actingAs($student)->postJson('/api/student/rental-requests/'.$rental->id.'/confirm-secured')
            ->assertOk()
            ->assertJsonPath('rental_request.status', 'accepted')
            ->assertJsonPath('rental_request.service_fee_payment_status', 'not_due');
        $this->assertSame(0, Payment::query()->where('payment_type', Payment::TYPE_STUDENT_SERVICE_FEE)->count());

        $this->actingAs($owner)->postJson('/api/owner/rental-requests/'.$rental->id.'/confirm-secured')
            ->assertOk()
            ->assertJsonPath('rental_request.status', 'completed')
            ->assertJsonPath('rental_request.service_fee_payment_status', 'due');

        $payment = Payment::query()->where('rental_request_id', $rental->id)->firstOrFail();
        $this->assertSame($student->id, $payment->payer_id);
        $this->assertSame(config('student_housing.student_service_fee_tnd'), $payment->amount_tnd);
        $this->assertSame('TND', $payment->currency);
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertNull($payment->paid_at);

        $this->actingAs($student)->getJson('/api/student/payments')
            ->assertOk()
            ->assertJsonPath('checkout_available', false)
            ->assertJsonPath('payments.0.status', 'pending')
            ->assertJsonPath('payments.0.amount', config('student_housing.student_service_fee_tnd'))
            ->assertJsonPath('payments.0.rental_request.reference', 'RNT-'.$rental->id)
            ->assertJsonPath('payments.0.house.title', $house->title);
    }

    public function test_payment_preparation_is_idempotent_and_ignores_forged_amount_payer_and_status(): void
    {
        [$owner, $student, , $rental] = $this->acceptedRental();
        $this->completeRental($owner, $student, $rental);

        $this->actingAs($student)
            ->postJson('/api/student/rental-requests/'.$rental->id.'/payment', [
                'amount_tnd' => '0.001',
                'payer_id' => $owner->id,
                'status' => 'paid',
                'provider_transaction_reference' => 'forged',
            ])
            ->assertUnprocessable();

        $first = $this->actingAs($student)->postJson('/api/student/rental-requests/'.$rental->id.'/payment')
            ->assertOk()
            ->assertJsonPath('checkout_available', false)
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('payment.amount', config('student_housing.student_service_fee_tnd'))
            ->assertJsonPath('payment.provider_transaction_reference', null);
        $second = $this->actingAs($student)->postJson('/api/student/rental-requests/'.$rental->id.'/payment')
            ->assertOk()
            ->assertJsonPath('payment.id', $first->json('payment.id'))
            ->assertJsonPath('payment.status', 'pending');

        $this->assertSame(1, Payment::query()->where('rental_request_id', $rental->id)->count());
        $this->assertDatabaseMissing('payments', [
            'rental_request_id' => $rental->id,
            'status' => 'paid',
        ]);
        $this->actingAs($student)->postJson('/api/payments/'.$first->json('payment.id').'/confirm')
            ->assertNotFound();
    }

    public function test_failed_payment_is_shown_as_failed_and_never_as_paid(): void
    {
        [$owner, $student, , $rental] = $this->acceptedRental();
        $this->completeRental($owner, $student, $rental);
        $payment = Payment::query()->where('rental_request_id', $rental->id)->firstOrFail();
        $payment->update([
            'status' => Payment::STATUS_FAILED,
            'failed_at' => now(),
            'failure_reason' => 'Provider did not confirm this transaction.',
        ]);

        $this->actingAs($student)->getJson('/api/student/payments')
            ->assertOk()
            ->assertJsonPath('payments.0.status', 'failed')
            ->assertJsonPath('payments.0.failure_reason', 'Provider did not confirm this transaction.')
            ->assertJsonPath('payments.0.paid_at', null);
    }

    public function test_student_and_owner_cannot_access_other_users_payment_records(): void
    {
        [$owner, $student, , $rental] = $this->acceptedRental();
        $this->completeRental($owner, $student, $rental);
        $payment = Payment::query()->where('rental_request_id', $rental->id)->firstOrFail();
        $otherStudent = $this->student('other-student@example.test');
        $otherOwner = $this->owner('other-owner@example.test');
        $otherSubscription = $otherOwner->ownerSubscriptions()->create([
            'house_id' => $otherOwner->houses()->create($this->houseData())->id,
            'monthly_price_tnd' => '20.000',
            'status' => 'pending',
            'starts_on' => now()->toDateString(),
            'next_renewal_on' => now()->addMonthNoOverflow()->toDateString(),
            'payment_status' => 'unpaid',
        ]);
        $this->actingAs($otherOwner)->postJson('/api/owner/subscriptions/'.$otherSubscription->id.'/payment')
            ->assertOk();
        $otherOwnerPayment = Payment::query()->where('owner_subscription_id', $otherSubscription->id)->firstOrFail();

        $this->actingAs($otherStudent)->getJson('/api/student/payments')
            ->assertOk()
            ->assertJsonCount(0, 'payments');
        $this->actingAs($student)->postJson('/api/student/rental-requests/'.$payment->rental_request_id.'/payment')
            ->assertOk();
        $this->actingAs($otherStudent)->postJson('/api/student/rental-requests/'.$rental->id.'/payment')
            ->assertNotFound();

        $this->actingAs($owner)->getJson('/api/owner/dashboard')
            ->assertOk()
            ->assertJsonCount(0, 'subscriptions');
        $this->actingAs($otherOwner)->getJson('/api/owner/dashboard')
            ->assertOk()
            ->assertJsonPath('subscriptions.0.payments.0.id', $otherOwnerPayment->id);
        $this->assertNotSame($payment->id, $otherOwnerPayment->id);
    }

    public function test_subscription_payment_record_cannot_be_marked_paid_by_a_requestor(): void
    {
        $owner = $this->owner();
        $house = $owner->houses()->create($this->houseData());
        $subscription = $owner->ownerSubscriptions()->create([
            'house_id' => $house->id,
            'monthly_price_tnd' => '20.000',
            'status' => 'pending',
            'starts_on' => now()->toDateString(),
            'next_renewal_on' => now()->addMonthNoOverflow()->toDateString(),
            'payment_status' => 'unpaid',
        ]);

        $this->actingAs($owner)->postJson('/api/owner/subscriptions/'.$subscription->id.'/payment', [
            'amount' => 60,
            'status' => 'paid',
        ])->assertUnprocessable();

        $this->actingAs($owner)->postJson('/api/owner/subscriptions/'.$subscription->id.'/payment')
            ->assertOk()
            ->assertJsonPath('payment.amount', '20.000')
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('payment.currency', 'TND');

        $this->assertDatabaseHas('owner_subscriptions', [
            'id' => $subscription->id,
            'payment_status' => 'unpaid',
            'status' => 'pending',
        ]);
    }

    private function acceptedRental(): array
    {
        $owner = $this->owner();
        $student = $this->student();
        $house = $owner->houses()->create($this->houseData());
        $rental = $student->rentalRequestsAsStudent()->create([
            'house_id' => $house->id,
            'requested_at' => now(),
            'status' => 'accepted',
            'secured_house' => false,
            'service_fee_tnd' => config('student_housing.student_service_fee_tnd'),
            'service_fee_payment_status' => 'not_due',
        ]);

        return [$owner, $student, $house, $rental];
    }

    private function completeRental(User $owner, User $student, RentalRequest $rental): void
    {
        $this->actingAs($student)->postJson('/api/student/rental-requests/'.$rental->id.'/confirm-secured')->assertOk();
        $this->actingAs($owner)->postJson('/api/owner/rental-requests/'.$rental->id.'/confirm-secured')->assertOk();
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

    private function houseData(): array
    {
        return [
            'title' => 'Student house',
            'description' => 'A test student home.',
            'governorate' => 'Tunis',
            'city' => 'Tunis',
            'monthly_rent_tnd' => '500.000',
            'bedrooms' => 2,
            'furnishing_status' => 'furnished',
            'available' => true,
            'verification_status' => 'pending_verification',
        ];
    }
}
