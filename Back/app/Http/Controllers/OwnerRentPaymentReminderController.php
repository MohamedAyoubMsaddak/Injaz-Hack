<?php

namespace App\Http\Controllers;

use App\Models\OwnerRentPaymentReminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OwnerRentPaymentReminderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $reminders = OwnerRentPaymentReminder::query()->where('owner_id', $request->user()->id);

        return response()->json([
            'unread_count' => (clone $reminders)->whereNull('read_at')->count(),
            'reminders' => $reminders->latest('due_date')
                ->limit(30)
                ->get(['id', 'rental_request_id', 'due_date', 'amount_tnd', 'message', 'read_at', 'created_at']),
            'payment_available' => false,
            'provider_message' => 'Sakani does not collect or transfer rent payments. The owner and student must arrange payment directly.',
        ]);
    }

    public function read(Request $request, int $reminder): JsonResponse
    {
        OwnerRentPaymentReminder::query()
            ->where('owner_id', $request->user()->id)
            ->whereKey($reminder)
            ->firstOrFail()
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Rent reminder marked as read.']);
    }
}
