<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\RentalRequest;
use App\Services\PaymentObligationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function studentIndex(Request $request): JsonResponse
    {
        $payments = $request->user()->rentalRequestsAsStudent()
            ->where('status', 'completed')
            ->where('secured_house', true)
            ->whereNotNull('student_confirmed_secured_at')
            ->whereNotNull('owner_confirmed_secured_at')
            ->with([
                'house:id,title,city,governorate',
                'payments' => fn ($query) => $query->where('payer_id', $request->user()->id),
            ])
            ->latest('requested_at')
            ->get()
            ->flatMap(fn (RentalRequest $rental) => $rental->payments
                ->whereIn('payment_type', [
                    Payment::TYPE_STUDENT_SERVICE_FEE,
                    Payment::TYPE_STUDENT_RENT_AND_SERVICE_FEE,
                ])
                ->map(fn (Payment $payment) => $this->paymentData($payment, $rental)));

        return response()->json([
            'student_service_fee_tnd' => config('student_housing.student_service_fee_tnd'),
            'payments' => $payments->values(),
            'checkout_available' => false,
            'provider_message' => 'Online payment is not configured. No money has been collected.',
        ]);
    }

    public function prepareStudentFee(
        Request $request,
        int $rentalRequest,
        PaymentObligationService $obligations
    ): JsonResponse {
        $this->rejectClientPaymentFields($request);

        $payment = DB::transaction(function () use ($request, $rentalRequest, $obligations): Payment {
            $rental = $request->user()->rentalRequestsAsStudent()
                ->whereKey($rentalRequest)
                ->lockForUpdate()
                ->firstOrFail();

            return $obligations->createStudentServiceFee($rental);
        });

        return $this->preparedResponse($payment, $payment->rentalRequest);
    }

    public function prepareOwnerSubscription(
        Request $request,
        int $subscription,
        PaymentObligationService $obligations
    ): JsonResponse {
        $this->rejectClientPaymentFields($request);

        $payment = DB::transaction(function () use ($request, $subscription, $obligations): Payment {
            $record = $request->user()->ownerSubscriptions()
                ->whereKey($subscription)
                ->lockForUpdate()
                ->firstOrFail();
            $latest = $record->payments()
                ->where('payer_id', $request->user()->id)
                ->latest('billing_period')
                ->first();

            if ($latest && $latest->status !== Payment::STATUS_PAID) {
                return $latest;
            }
            if ($latest && $record->next_renewal_on?->isFuture()) {
                return $latest;
            }

            $period = $latest
                ? ($record->next_renewal_on?->toDateString() ?? $record->starts_on->toDateString())
                : $record->starts_on->toDateString();

            return $obligations->createOwnerSubscriptionPayment($record, $period);
        });

        return $this->preparedResponse($payment, null);
    }

    private function rejectClientPaymentFields(Request $request): void
    {
        $request->validate([
            'amount' => ['prohibited'],
            'amount_tnd' => ['prohibited'],
            'payer_id' => ['prohibited'],
            'payment_type' => ['prohibited'],
            'status' => ['prohibited'],
            'provider' => ['prohibited'],
            'provider_transaction_reference' => ['prohibited'],
            'paid_at' => ['prohibited'],
        ]);
    }

    private function preparedResponse(Payment $payment, ?RentalRequest $rental): JsonResponse
    {
        $payment->loadMissing(['ownerSubscription.house:id,title', 'rentalRequest.house:id,title,city,governorate']);

        return response()->json([
            'payment' => $this->paymentData($payment, $rental),
            'checkout_available' => false,
            'message' => 'The payment obligation is recorded as '.$payment->status.'. Online payment is not configured; no money has been collected.',
        ]);
    }

    private function paymentData(Payment $payment, ?RentalRequest $rental = null): array
    {
        $rental ??= $payment->rentalRequest;
        $house = $rental?->house ?? $payment->ownerSubscription?->house;

        return [
            'id' => $payment->id,
            'reference' => $payment->payment_reference,
            'type' => $payment->payment_type,
            'amount' => $payment->amount_tnd,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'billing_period' => $payment->billing_period?->toDateString(),
            'provider' => $payment->provider,
            'provider_transaction_reference' => $payment->provider_transaction_reference,
            'created_at' => $payment->created_at?->toIso8601String(),
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'failed_at' => $payment->failed_at?->toIso8601String(),
            'failure_reason' => $payment->failure_reason,
            'refunded_at' => $payment->refunded_at?->toIso8601String(),
            'rental_request' => $rental ? [
                'id' => $rental->id,
                'reference' => 'RNT-'.$rental->id,
            ] : null,
            'house' => $house ? [
                'id' => $house->id,
                'title' => $house->title,
                'city' => $house->city,
                'governorate' => $house->governorate,
            ] : null,
        ];
    }
}
