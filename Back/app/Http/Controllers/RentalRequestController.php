<?php

namespace App\Http\Controllers;

use App\Models\House;
use App\Models\RentalRequest;
use App\Services\PaymentObligationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RentalRequestController extends Controller
{
    public function studentIndex(Request $request): JsonResponse
    {
        return response()->json([
            'student_service_fee_tnd' => config('student_housing.student_service_fee_tnd'),
            'rental_requests' => $request->user()->rentalRequestsAsStudent()
                ->with('house')
                ->latest('requested_at')
                ->get()
                ->map(fn (RentalRequest $rentalRequest) => $this->requestData($rentalRequest, false)),
        ]);
    }

    public function store(Request $request, StudentHousingController $housing): JsonResponse
    {
        $validated = $request->validate([
            'house_id' => ['required', 'integer', 'exists:houses,id'],
        ]);

        $rentalRequest = DB::transaction(function () use ($request, $housing, $validated): RentalRequest {
            $house = House::query()->whereKey($validated['house_id'])->lockForUpdate()->firstOrFail();
            abort_unless($house->available && $housing->verifiedHouses()->whereKey($house->id)->exists(), 409, 'This house is not available for rental requests.');

            $alreadyActive = $request->user()->rentalRequestsAsStudent()
                ->where('house_id', $house->id)
                ->where(function ($query) {
                    $query->whereIn('status', ['pending', 'accepted'])
                        ->orWhere('secured_house', true);
                })
                ->exists();
            abort_if($alreadyActive, 409, 'You already have an active request for this house.');

            return $request->user()->rentalRequestsAsStudent()->create([
                'house_id' => $house->id,
                'requested_at' => now(),
                'status' => 'pending',
                'service_fee_tnd' => config('student_housing.student_service_fee_tnd'),
                'service_fee_payment_status' => 'not_due',
            ]);
        });

        return response()->json([
            'rental_request' => $this->requestData($rentalRequest->load('house'), false),
            'message' => 'Your rental request was sent to the property owner.',
        ], 201);
    }

    public function ownerIndex(Request $request): JsonResponse
    {
        $rentalRequests = RentalRequest::query()
            ->whereHas('house', fn ($query) => $query->where('owner_id', $request->user()->id))
            ->with(['house', 'student:id,name,email,phone'])
            ->latest('requested_at')
            ->get();

        return response()->json([
            'rental_requests' => $rentalRequests->map(fn (RentalRequest $rentalRequest) => $this->requestData($rentalRequest, true)),
        ]);
    }

    public function respond(Request $request, int $rentalRequest, StudentHousingController $housing): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:accepted,rejected'],
        ]);

        $rentalRequest = DB::transaction(function () use ($request, $rentalRequest, $validated, $housing): RentalRequest {
            $lockedRequest = $this->ownedRequest($request, $rentalRequest, true);
            abort_unless($lockedRequest->status === 'pending', 409, 'Only pending rental requests can be accepted or rejected.');

            if ($validated['decision'] === 'accepted') {
                $house = House::query()->whereKey($lockedRequest->house_id)->lockForUpdate()->firstOrFail();
                abort_unless(
                    $house->available && $housing->verifiedHouses()->whereKey($house->id)->exists(),
                    409,
                    'This house is no longer available for rental.'
                );
                $anotherSecuredRequest = RentalRequest::query()
                    ->where('house_id', $house->id)
                    ->where('id', '<>', $lockedRequest->id)
                    ->where(function ($query) {
                        $query->where('status', 'accepted')->orWhere('secured_house', true);
                    })
                    ->exists();
                abort_if($anotherSecuredRequest, 409, 'Another student already has an accepted request for this house.');
            }

            $lockedRequest->update(['status' => $validated['decision']]);

            return $lockedRequest->load(['house', 'student:id,name,email,phone']);
        });

        return response()->json([
            'rental_request' => $this->requestData($rentalRequest, true),
            'message' => 'The rental request has been '.$validated['decision'].'.',
        ]);
    }

    public function cancel(Request $request, int $rentalRequest): JsonResponse
    {
        $rentalRequest = DB::transaction(function () use ($request, $rentalRequest): RentalRequest {
            $lockedRequest = $request->user()->rentalRequestsAsStudent()
                ->whereKey($rentalRequest)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($lockedRequest->status === 'pending', 409, 'Only pending requests can be cancelled.');
            $lockedRequest->update(['status' => 'cancelled']);

            return $lockedRequest->load('house');
        });

        return response()->json([
            'rental_request' => $this->requestData($rentalRequest, false),
            'message' => 'Your rental request was cancelled.',
        ]);
    }

    public function confirmStudent(
        Request $request,
        int $rentalRequest,
        PaymentObligationService $obligations
    ): JsonResponse {
        return $this->confirmSecured($request, $rentalRequest, true, $obligations);
    }

    public function confirmOwner(
        Request $request,
        int $rentalRequest,
        PaymentObligationService $obligations
    ): JsonResponse {
        return $this->confirmSecured($request, $rentalRequest, false, $obligations);
    }

    private function confirmSecured(
        Request $request,
        int $rentalRequest,
        bool $student,
        PaymentObligationService $obligations
    ): JsonResponse {
        $updatedRequest = DB::transaction(function () use ($request, $rentalRequest, $student, $obligations): RentalRequest {
            $lockedRequest = $student
                ? $request->user()->rentalRequestsAsStudent()->whereKey($rentalRequest)->lockForUpdate()->firstOrFail()
                : $this->ownedRequest($request, $rentalRequest, true);

            abort_unless($lockedRequest->status === 'accepted', 409, 'Both parties can confirm a rental only after the owner accepts the request.');

            $confirmationColumn = $student ? 'student_confirmed_secured_at' : 'owner_confirmed_secured_at';
            if ($lockedRequest->{$confirmationColumn} === null) {
                $lockedRequest->{$confirmationColumn} = now();
            }

            if ($lockedRequest->student_confirmed_secured_at && $lockedRequest->owner_confirmed_secured_at) {
                $lockedRequest->status = 'completed';
                $lockedRequest->secured_house = true;
                $lockedRequest->secured_at ??= now();
                $lockedRequest->service_fee_tnd = config('student_housing.student_service_fee_tnd');
                $lockedRequest->service_fee_payment_status = 'due';
                House::query()->whereKey($lockedRequest->house_id)->update(['available' => false]);
            }

            $lockedRequest->save();

            if ($lockedRequest->status === 'completed') {
                $obligations->createStudentServiceFee($lockedRequest);
            }

            return $lockedRequest->load(['house', 'student:id,name,email,phone']);
        });

        return response()->json([
            'rental_request' => $this->requestData($updatedRequest, ! $student),
            'message' => $updatedRequest->status === 'completed'
                ? 'Both parties confirmed. The rental is secured; the service fee is due but has not been paid.'
                : 'Your confirmation was recorded. The rental is complete only after both parties confirm.',
        ]);
    }

    private function ownedRequest(Request $request, int $rentalRequest, bool $lock = false): RentalRequest
    {
        $query = RentalRequest::query()
            ->whereKey($rentalRequest)
            ->whereHas('house', fn ($houseQuery) => $houseQuery->where('owner_id', $request->user()->id));

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function requestData(RentalRequest $rentalRequest, bool $includeStudent): array
    {
        $data = [
            'id' => $rentalRequest->id,
            'status' => $rentalRequest->status,
            'requested_at' => $rentalRequest->requested_at?->toIso8601String(),
            'secured_house' => $rentalRequest->secured_house,
            'student_confirmed_secured' => $rentalRequest->student_confirmed_secured_at !== null,
            'owner_confirmed_secured' => $rentalRequest->owner_confirmed_secured_at !== null,
            'secured_at' => $rentalRequest->secured_at?->toIso8601String(),
            'service_fee_tnd' => $rentalRequest->service_fee_tnd,
            'service_fee_payment_status' => $rentalRequest->service_fee_payment_status,
            'house' => $rentalRequest->house ? [
                'id' => $rentalRequest->house->id,
                'title' => $rentalRequest->house->title,
                'city' => $rentalRequest->house->city,
                'governorate' => $rentalRequest->house->governorate,
                'monthly_rent_tnd' => $rentalRequest->house->public_listing_price_tnd,
            ] : null,
        ];

        if ($includeStudent && $rentalRequest->relationLoaded('student') && $rentalRequest->student) {
            $data['student'] = $rentalRequest->student->only(['id', 'name', 'email', 'phone']);
        }

        return $data;
    }
}
