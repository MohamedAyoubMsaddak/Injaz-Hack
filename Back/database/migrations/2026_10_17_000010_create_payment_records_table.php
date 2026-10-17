<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('payment_reference')->unique();
            $table->foreignId('payer_id')->constrained('users')->restrictOnDelete();
            $table->string('payment_type', 32);
            $table->foreignId('owner_subscription_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('rental_request_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('billing_period')->nullable();
            $table->decimal('amount_tnd', 10, 3);
            $table->char('currency', 3)->default('TND');
            $table->string('status', 16)->default('pending');
            $table->string('provider', 64)->nullable();
            $table->string('provider_transaction_reference', 128)->nullable();
            $table->string('provider_event_reference', 128)->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->unique('rental_request_id', 'pay_rental_obligation_uq');
            $table->unique(['owner_subscription_id', 'billing_period'], 'pay_sub_period_uq');
            $table->unique(['provider', 'provider_transaction_reference'], 'pay_provider_txn_uq');
            $table->unique(['provider', 'provider_event_reference'], 'pay_provider_event_uq');
            $table->index(['payer_id', 'status'], 'pay_payer_status_idx');
        });

        $this->createSubscriptionsForExistingHouses();
        $this->createPendingPaymentsForExistingObligations();
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }

    private function createSubscriptionsForExistingHouses(): void
    {
        $houses = DB::table('houses')
            ->leftJoin('owner_subscriptions', 'owner_subscriptions.house_id', '=', 'houses.id')
            ->whereNull('owner_subscriptions.id')
            ->get(['houses.id', 'houses.owner_id', 'houses.created_at']);

        foreach ($houses as $house) {
            $start = CarbonImmutable::parse($house->created_at ?? now())->startOfDay();
            DB::table('owner_subscriptions')->insert([
                'owner_id' => $house->owner_id,
                'house_id' => $house->id,
                'monthly_price_tnd' => config('student_housing.owner_subscription_monthly_tnd', '20.000'),
                'status' => 'pending',
                'starts_on' => $start->toDateString(),
                'next_renewal_on' => $start->addMonthNoOverflow()->toDateString(),
                'payment_status' => 'unpaid',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function createPendingPaymentsForExistingObligations(): void
    {
        foreach (DB::table('owner_subscriptions')->where('payment_status', '!=', 'paid')->get() as $subscription) {
            $this->insertPendingPayment(
                $subscription->owner_id,
                'owner_subscription',
                $subscription->monthly_price_tnd,
                $subscription->starts_on,
                $subscription->id,
                null
            );
        }

        foreach (
            DB::table('rental_requests')
                ->where('status', 'completed')
                ->where('secured_house', true)
                ->whereNotNull('student_confirmed_secured_at')
                ->whereNotNull('owner_confirmed_secured_at')
                ->where('service_fee_payment_status', '!=', 'paid')
                ->get() as $rental
        ) {
            $this->insertPendingPayment(
                $rental->student_id,
                'student_service_fee',
                $rental->service_fee_tnd,
                null,
                null,
                $rental->id
            );
        }
    }

    private function insertPendingPayment(
        int $payerId,
        string $type,
        string $amount,
        ?string $period,
        ?int $subscriptionId,
        ?int $rentalRequestId
    ): void {
        DB::table('payments')->insert([
            'payment_reference' => (string) Str::uuid(),
            'payer_id' => $payerId,
            'payment_type' => $type,
            'owner_subscription_id' => $subscriptionId,
            'rental_request_id' => $rentalRequestId,
            'billing_period' => $period,
            'amount_tnd' => $amount,
            'currency' => config('student_housing.currency', 'TND'),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
