<?php

namespace App\Services;

use App\Models\OwnerSubscription;
use App\Models\Payment;
use App\Models\RentalRequest;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentObligationService
{
    public function createStudentServiceFee(RentalRequest $rentalRequest): Payment
    {
        if (
            $rentalRequest->status !== 'completed'
            || ! $rentalRequest->secured_house
            || ! $rentalRequest->student_confirmed_secured_at
            || ! $rentalRequest->owner_confirmed_secured_at
        ) {
            throw new HttpException(409, 'A service fee is due only after both parties confirm the secured rental.');
        }

        return Payment::query()->firstOrCreate(
            ['rental_request_id' => $rentalRequest->id],
            [
                'payment_reference' => (string) Str::uuid(),
                'payer_id' => $rentalRequest->student_id,
                'payment_type' => Payment::TYPE_STUDENT_SERVICE_FEE,
                'amount_tnd' => $rentalRequest->service_fee_tnd,
                'currency' => config('student_housing.currency', 'TND'),
                'status' => Payment::STATUS_PENDING,
            ]
        );
    }

    public function createOwnerSubscriptionPayment(
        OwnerSubscription $subscription,
        ?string $billingPeriod = null
    ): Payment {
        $period = $billingPeriod ?? $subscription->starts_on->toDateString();

        return Payment::query()->firstOrCreate(
            [
                'owner_subscription_id' => $subscription->id,
                'billing_period' => $period,
            ],
            [
                'payment_reference' => (string) Str::uuid(),
                'payer_id' => $subscription->owner_id,
                'payment_type' => Payment::TYPE_OWNER_SUBSCRIPTION,
                'amount_tnd' => $subscription->monthly_price_tnd,
                'currency' => config('student_housing.currency', 'TND'),
                'status' => Payment::STATUS_PENDING,
            ]
        );
    }
}
