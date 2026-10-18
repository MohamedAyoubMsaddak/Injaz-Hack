<?php

namespace App\Http\Controllers;

use App\Models\OwnerRentalNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OwnerRentalNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = OwnerRentalNotification::query()
            ->where('owner_id', $request->user()->id);

        return response()->json([
            'unread_count' => (clone $notifications)->whereNull('read_at')->count(),
            'notifications' => $notifications->latest()
                ->limit(30)
                ->get([
                    'id',
                    'rental_request_id',
                    'event_type',
                    'house_title',
                    'student_name',
                    'amount_tnd',
                    'read_at',
                    'created_at',
                ]),
            'payment_available' => false,
        ]);
    }

    public function read(Request $request, int $notification): JsonResponse
    {
        OwnerRentalNotification::query()
            ->where('owner_id', $request->user()->id)
            ->whereKey($notification)
            ->firstOrFail()
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Rental notification marked as read.']);
    }
}
