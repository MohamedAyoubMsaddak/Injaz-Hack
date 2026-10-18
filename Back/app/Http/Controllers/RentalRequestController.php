<?php

namespace App\Http\Controllers;

use App\Models\House;
use App\Models\InspectionReport;
use App\Models\Payment;
use App\Models\OwnerRentalNotification;
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
                ->with(['house', 'payments', 'conditionReport.photos', 'conditionReport.reviewer:id,role'])
                ->latest('requested_at')
                ->get()
                ->map(fn (RentalRequest $rentalRequest) => $this->requestData($rentalRequest, false)),
        ]);
    }

    public function store(
        Request $request,
        StudentHousingController $housing,
        PaymentObligationService $obligations
    ): JsonResponse
    {
        $validated = $request->validate([
            'house_id' => ['required', 'integer', 'exists:houses,id'],
        ]);

        $rentalRequest = DB::transaction(function () use ($request, $housing, $validated, $obligations): RentalRequest {
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

            $rentalRequest = $request->user()->rentalRequestsAsStudent()->create([
                'house_id' => $house->id,
                'requested_at' => now(),
                'status' => 'pending',
                'service_fee_tnd' => config('student_housing.student_service_fee_tnd'),
                'service_fee_payment_status' => 'not_due',
            ]);

            $obligations->createInitialStudentRentalPayment($rentalRequest, $house);
            $rentalRequest->conditionReport()->create([
                'house_id' => $house->id,
                'requested_by_student_id' => $request->user()->id,
                'inspection_date' => now(),
                'status' => 'requested',
            ]);

            return $rentalRequest->load(['house', 'payments', 'conditionReport.photos', 'conditionReport.reviewer:id,role']);
        });

        return response()->json([
            'rental_request' => $this->requestData($rentalRequest, false),
            'payment_obligation' => $this->paymentObligationData($rentalRequest->payments->first(), $rentalRequest),
            'checkout_available' => false,
            'message' => 'Your rental request was sent to the property owner. The first-month rent and one-time service fee are recorded as a pending payment obligation; online payment is not configured and no money has been collected.',
        ], 201);
    }

    public function ownerIndex(Request $request): JsonResponse
    {
        $rentalRequests = RentalRequest::query()
            ->whereHas('house', fn ($query) => $query->where('owner_id', $request->user()->id))
            ->with(['house', 'student:id,name,email,phone', 'conditionReport.photos', 'conditionReport.reviewer:id,role'])
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
            $conditionReport = $lockedRequest->conditionReport()
                ->where('house_id', $lockedRequest->house_id)
                ->where('requested_by_student_id', $lockedRequest->student_id)
                ->where('status', 'completed')
                ->whereNotNull('reviewed_at')
                ->whereHas('reviewer', fn ($query) => $query->where('role', 'admin'))
                ->lockForUpdate()
                ->first();
            abort_unless(
                $conditionReport !== null,
                409,
                'The administrator must share the house condition report with the student before you can decide on this rental request.'
            );

            $house = null;
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
            if ($validated['decision'] === 'accepted' && $house !== null) {
                OwnerRentalNotification::query()->firstOrCreate(
                    [
                        'rental_request_id' => $lockedRequest->id,
                        'event_type' => OwnerRentalNotification::EVENT_REQUEST_ACCEPTED,
                    ],
                    [
                        'owner_id' => $house->owner_id,
                        'house_title' => $house->title,
                        'student_name' => $lockedRequest->student()->value('name'),
                        'amount_tnd' => $house->agreed_owner_rent_tnd ?? $house->monthly_rent_tnd,
                    ]
                );
            }
            if ($validated['decision'] === 'rejected') {
                $lockedRequest->payments()
                    ->where('payment_type', Payment::TYPE_STUDENT_RENT_AND_SERVICE_FEE)
                    ->where('status', Payment::STATUS_PENDING)
                    ->update(['status' => Payment::STATUS_CANCELLED]);
            }

            return $lockedRequest->load(['house', 'student:id,name,email,phone', 'conditionReport.photos', 'conditionReport.reviewer:id,role']);
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
            $lockedRequest->conditionReport()
                ->whereIn('status', ['requested', 'assigned', 'completed'])
                ->whereNull('reviewed_at')
                ->update(['status' => 'cancelled']);
            $lockedRequest->payments()
                ->where('payment_type', Payment::TYPE_STUDENT_RENT_AND_SERVICE_FEE)
                ->where('status', Payment::STATUS_PENDING)
                ->update(['status' => Payment::STATUS_CANCELLED]);

            return $lockedRequest->load(['house', 'payments', 'conditionReport.photos', 'conditionReport.reviewer:id,role']);
        });

        return response()->json([
            'rental_request' => $this->requestData($rentalRequest, false),
            'message' => 'Your rental request was cancelled.',
            'payment_obligation' => $this->paymentObligationData(
                $rentalRequest->payments->firstWhere('payment_type', Payment::TYPE_STUDENT_RENT_AND_SERVICE_FEE),
                $rentalRequest
            ),
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

            return $lockedRequest->load(['house', 'student:id,name,email,phone', 'conditionReport.photos', 'conditionReport.reviewer:id,role']);
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
            'payment_obligation' => ! $includeStudent
                ? $this->paymentObligationData($rentalRequest->payments->first(), $rentalRequest)
                : null,
            'condition_report' => $this->conditionReportData($rentalRequest),
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

    private function paymentObligationData(?Payment $payment, ?RentalRequest $rentalRequest = null): ?array
    {
        if (! $payment) {
            return null;
        }

        return [
            'id' => $payment->id,
            'amount' => $payment->amount_tnd,
            'rent_amount' => $payment->payment_type === Payment::TYPE_STUDENT_RENT_AND_SERVICE_FEE
                ? $rentalRequest?->house?->public_listing_price_tnd
                : null,
            'service_fee_amount' => $payment->payment_type === Payment::TYPE_STUDENT_RENT_AND_SERVICE_FEE
                ? $rentalRequest?->service_fee_tnd
                : null,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'type' => $payment->payment_type,
            'checkout_available' => false,
            'message' => 'Online payment is not configured; no money has been collected.',
        ];
    }

    private function conditionReportData(RentalRequest $rentalRequest): ?array
    {
        $report = $rentalRequest->conditionReport;
        if ($report === null) {
            return null;
        }

        $sharedWithStudent = $report->rental_request_id === $rentalRequest->id
            && $report->house_id === $rentalRequest->house_id
            && $report->requested_by_student_id === $rentalRequest->student_id
            && $report->status === 'completed'
            && $report->reviewed_at !== null
            && $report->reviewer?->role === 'admin';

        $data = [
            'id' => $report->id,
            'status' => $report->status,
            'reviewed_by_admin' => $sharedWithStudent,
            'scheduled_at' => $report->status === 'assigned' ? $report->inspection_date?->toIso8601String() : null,
            'shared_at' => $sharedWithStudent ? $report->reviewed_at?->toIso8601String() : null,
        ];

        if ($sharedWithStudent) {
            $data['report'] = [
                'completed_at' => $report->completed_at?->toIso8601String(),
                'checklist' => $report->condition_checklist,
                'notes' => $report->notes,
                'defects_found' => $report->defects_found,
                'recommendation' => $report->recommendation,
                'review_notes' => $report->review_notes,
                'photos' => $report->photos->map(fn ($photo) => [
                    'id' => $photo->id,
                    'url' => route('student.house-check-requests.photos.show', [
                        'inspection' => $report->id,
                        'photo' => $photo->id,
                    ]),
                    'caption' => $photo->caption,
                ])->values(),
            ];
        }

        return $data;
    }
}
