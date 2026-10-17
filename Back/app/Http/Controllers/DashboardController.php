<?php

namespace App\Http\Controllers;

use App\Models\OwnerSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function student(): JsonResponse
    {
        return response()->json([
            'dashboard' => 'student',
            'message' => 'Your student dashboard is ready.',
        ]);
    }

    public function owner(Request $request): JsonResponse
    {
        $owner = $request->user();
        $houses = $owner->houses();
        $subscriptions = OwnerSubscription::query()
            ->where('owner_id', $owner->id)
            ->with([
                'house:id,title',
                'payments' => fn ($query) => $query->where('payer_id', $owner->id)
                    ->latest('billing_period')
                    ->latest('id'),
            ])
            ->get(['id', 'house_id', 'monthly_price_tnd', 'status', 'payment_status', 'starts_on', 'next_renewal_on'])
            ->map(function (OwnerSubscription $subscription): array {
                $latestPayment = $subscription->payments->first();
                $nextRenewal = $subscription->next_renewal_on;

                return [
                    'id' => $subscription->id,
                    'house_id' => $subscription->house_id,
                    'house' => $subscription->house?->only(['id', 'title']),
                    'monthly_price_tnd' => $subscription->monthly_price_tnd,
                    'status' => $nextRenewal && $nextRenewal->lt(now()->startOfDay())
                        && $latestPayment?->status !== 'paid'
                        ? 'expired'
                        : $subscription->status,
                    'payment_status' => $latestPayment?->status ?? 'unpaid',
                    'starts_on' => $subscription->starts_on?->toDateString(),
                    'next_renewal_on' => $subscription->next_renewal_on?->toDateString(),
                    'payments' => $subscription->payments->map(fn ($payment) => [
                        'id' => $payment->id,
                        'reference' => $payment->payment_reference,
                        'amount' => $payment->amount_tnd,
                        'currency' => $payment->currency,
                        'status' => $payment->status,
                        'billing_period' => $payment->billing_period?->toDateString(),
                        'created_at' => $payment->created_at?->toIso8601String(),
                        'paid_at' => $payment->paid_at?->toIso8601String(),
                        'failed_at' => $payment->failed_at?->toIso8601String(),
                        'failure_reason' => $payment->failure_reason,
                        'refunded_at' => $payment->refunded_at?->toIso8601String(),
                    ])->values(),
                ];
            });
        $subscriptionPrices = OwnerSubscription::query()
            ->where('owner_id', $owner->id)
            ->pluck('monthly_price_tnd')
            ->all();

        return response()->json([
            'dashboard' => 'owner',
            'message' => 'Your property owner dashboard is ready.',
            'counts' => [
                'houses' => (clone $houses)->count(),
                'available_houses' => (clone $houses)->where('available', true)->count(),
                'awaiting_verification' => (clone $houses)
                    ->whereIn('verification_status', ['pending', 'pending_negotiation', 'negotiation_in_progress', 'pending_verification'])
                    ->count(),
                'pending_rental_requests' => DB::table('rental_requests')
                    ->whereIn('house_id', (clone $houses)->select('id'))
                    ->where('status', 'pending')
                    ->count(),
            ],
            'owner_subscription_monthly_tnd' => config('student_housing.owner_subscription_monthly_tnd', '20.000'),
            'subscriptions' => $subscriptions,
            'monthly_subscription_total_tnd' => $this->sumTnd($subscriptionPrices),
        ]);
    }

    private function sumTnd(array $amounts): string
    {
        $totalMillis = 0;
        foreach ($amounts as $amount) {
            [$whole, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '');
            $totalMillis += ((int) $whole * 1000) + (int) str_pad(substr($fraction, 0, 3), 3, '0');
        }

        return intdiv($totalMillis, 1000).'.'.str_pad((string) ($totalMillis % 1000), 3, '0', STR_PAD_LEFT);
    }
}
