<?php

namespace App\Services;

use App\Models\House;
use App\Models\OwnerSubscription;
use App\Models\Payment;
use App\Models\RentalRequest;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentObligationService
{
    public function createInitialStudentRentalPayment(RentalRequest $rentalRequest, House $house): Payment
    {
        $rentMilliunits = $this->toMilliunits((string) $house->public_listing_price_tnd);
        $feeMilliunits = $this->toMilliunits((string) $rentalRequest->service_fee_tnd);
        $totalMilliunits = $rentMilliunits + $feeMilliunits;
        if ($totalMilliunits > 9_999_999_999) {
            throw new HttpException(422, 'The initial rental payment exceeds the supported amount.');
        }

        return Payment::query()->firstOrCreate(
            ['rental_request_id' => $rentalRequest->id],
            [
                'payment_reference' => (string) Str::uuid(),
                'payer_id' => $rentalRequest->student_id,
                'payment_type' => Payment::TYPE_STUDENT_RENT_AND_SERVICE_FEE,
                'amount_tnd' => intdiv($totalMilliunits, 1000).'.'.str_pad((string) ($totalMilliunits % 1000), 3, '0', STR_PAD_LEFT),
                'currency' => config('student_housing.currency', 'TND'),
                'status' => Payment::STATUS_PENDING,
            ]
        );
    }

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

        $existingPayment = Payment::query()->where('rental_request_id', $rentalRequest->id)->first();
        if ($existingPayment) {
            return $existingPayment;
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

    private function toMilliunits(string $amount): int
    {
        $parts = explode('.', $amount, 2);
        $whole = $parts[0];
        $fraction = $parts[1] ?? '';

        return ((int) $whole * 1000) + (int) str_pad(substr($fraction, 0, 3), 3, '0');
    }
}
