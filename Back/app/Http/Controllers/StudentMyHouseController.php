<?php

namespace App\Http\Controllers;

use App\Models\RentalRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentMyHouseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rentals = RentalRequest::query()
            ->where('student_id', $request->user()->id)
            ->where('status', 'completed')
            ->where('secured_house', true)
            ->with(['house.photos', 'moveOutReport'])
            ->latest('secured_at')
            ->get();

        return response()->json([
            'houses' => $rentals->map(fn (RentalRequest $rental) => [
                'rental_request_id' => $rental->id,
                'secured_at' => $rental->secured_at?->toIso8601String(),
                'house' => [
                    'id' => $rental->house->id,
                    'title' => $rental->house->title,
                    'description' => $rental->house->description,
                    'city' => $rental->house->city,
                    'governorate' => $rental->house->governorate,
                    'address' => $rental->house->address,
                    'monthly_rent_tnd' => $rental->house->public_listing_price_tnd,
                    'bedrooms' => $rental->house->bedrooms,
                    'photos' => $rental->house->photos->map(fn ($photo) => [
                        'id' => $photo->id,
                        'caption' => $photo->caption,
                        'url' => route('student.houses.photos.show', [
                            'house' => $rental->house->id,
                            'photo' => $photo->id,
                        ]),
                    ])->values(),
                ],
                'move_out_report' => $rental->moveOutReport ? [
                    'planned_move_out_at' => $rental->moveOutReport->planned_move_out_at?->toDateString(),
                    'message' => $rental->moveOutReport->message,
                    'status' => $rental->moveOutReport->status,
                    'reviewed_at' => $rental->moveOutReport->reviewed_at?->toIso8601String(),
                ] : null,
            ]),
        ]);
    }

    public function submitMoveOutReport(Request $request, int $rentalRequest): JsonResponse
    {
        $validated = $request->validate([
            'planned_move_out_at' => ['required', 'date', 'after_or_equal:today'],
            'message' => ['required', 'string', 'min:10', 'max:10000'],
        ]);

        $report = DB::transaction(function () use ($request, $rentalRequest, $validated) {
            $rental = $request->user()->rentalRequestsAsStudent()
                ->whereKey($rentalRequest)
                ->where('status', 'completed')
                ->where('secured_house', true)
                ->lockForUpdate()
                ->firstOrFail();

            abort_if($rental->moveOutReport()->exists(), 409, 'A move-out report has already been sent for this rental.');

            return $rental->moveOutReport()->create([
                'student_id' => $request->user()->id,
                'planned_move_out_at' => $validated['planned_move_out_at'],
                'message' => $validated['message'],
                'status' => 'submitted',
            ]);
        });

        return response()->json([
            'move_out_report' => [
                'planned_move_out_at' => $report->planned_move_out_at?->toDateString(),
                'message' => $report->message,
                'status' => $report->status,
                'reviewed_at' => null,
            ],
            'message' => 'Your move-out report has been sent to the company. This report does not end the rental automatically.',
        ], 201);
    }
}
