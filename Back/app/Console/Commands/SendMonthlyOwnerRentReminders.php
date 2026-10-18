<?php

namespace App\Console\Commands;

use App\Models\RentalRequest;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendMonthlyOwnerRentReminders extends Command
{
    protected $signature = 'rentals:send-monthly-owner-reminders';

    protected $description = 'Create monthly owner rent reminders for secured rentals';

    public function handle(): int
    {
        $today = CarbonImmutable::today();
        $created = 0;

        RentalRequest::query()
            ->where('status', 'completed')
            ->where('secured_house', true)
            ->whereNotNull('secured_at')
            ->with('house:id,title,owner_id,monthly_rent_tnd,agreed_owner_rent_tnd')
            ->chunkById(100, function ($rentals) use ($today, &$created): void {
                foreach ($rentals as $rental) {
                    $house = $rental->house;
                    if ($house === null) {
                        continue;
                    }

                    $amount = $house->agreed_owner_rent_tnd ?? $house->monthly_rent_tnd;
                    $securedAt = CarbonImmutable::instance($rental->secured_at);

                    for ($month = 1; ; $month++) {
                        $dueDate = $securedAt->addMonthsNoOverflow($month)->startOfDay();
                        if ($dueDate->greaterThan($today)) {
                            break;
                        }

                        $now = now();
                        $inserted = DB::table('owner_rent_payment_reminders')->insertOrIgnore([
                            'rental_request_id' => $rental->id,
                            'due_date' => $dueDate->toDateString(),
                            'owner_id' => $house->owner_id,
                            'amount_tnd' => $amount,
                            'message' => sprintf(
                                'Monthly rent of %s TND for %s is due. Sakani has not collected or transferred this payment; arrange it directly until secure payment and payout are configured.',
                                $amount,
                                $house->title
                            ),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                        if ($inserted > 0) {
                            $created++;
                        }
                    }
                }
            });

        $this->info("Created {$created} monthly owner rent reminder(s).");

        return self::SUCCESS;
    }
}
