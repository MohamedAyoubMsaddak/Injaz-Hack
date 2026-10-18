<?php

namespace App\Http\Controllers;

use App\Models\House;
use App\Models\HousePhoto;
use App\Models\InspectionReport;
use App\Models\InspectionReportPhoto;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentHousingController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $student = $request->user();
        $rentalRequests = $student->rentalRequestsAsStudent()
            ->with('house')
            ->latest('requested_at')
            ->get();
        $securedHouseIds = $student->rentalRequestsAsStudent()
            ->where('secured_house', true)
            ->select('house_id');

        return response()->json([
            'dashboard' => 'student',
            'account' => $student->only(['id', 'name', 'email', 'phone']),
            'student_service_fee_tnd' => config('student_housing.student_service_fee_tnd'),
            'recent_houses' => $this->verifiedHouses()
                ->where('available', true)
                ->with([
                    'owner:id,name',
                    'photos',
                    'inspectionReports' => $this->approvedInspectionReports(),
                    'studentCheckRequests' => fn ($query) => $query
                        ->where('requested_by_student_id', $student->id)
                        ->whereNull('rental_request_id')
                        ->latest(),
                ])
                ->latest()
                ->limit(6)
                ->get()
                ->map(fn (House $house) => $this->houseData($house, $student->id)),
            'active_requests' => $rentalRequests
                ->whereIn('status', ['pending', 'accepted'])
                ->values()
                ->map(fn ($rentalRequest) => $this->rentalRequestData($rentalRequest)),
            'request_history' => $rentalRequests
                ->whereNotIn('status', ['pending', 'accepted'])
                ->values()
                ->map(fn ($rentalRequest) => $this->rentalRequestData($rentalRequest)),
            'maintenance_requests' => $student->maintenanceRequestsReported()
                ->whereIn('house_id', $securedHouseIds)
                ->latest('reported_at')
                ->get(['id', 'house_id', 'description', 'priority', 'status', 'reported_at']),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'governorate' => ['sometimes', 'nullable', 'string', 'max:100'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'max_rent' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:9999999.999'],
            'min_bedrooms' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:255'],
            'furnishing_status' => ['sometimes', 'nullable', Rule::in(['furnished', 'unfurnished', 'partially_furnished', 'unknown'])],
            'available' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $houses = $this->verifiedHouses()
            ->with([
                'owner:id,name',
                'photos',
                'inspectionReports' => $this->approvedInspectionReports(),
                'studentCheckRequests' => fn ($query) => $query
                    ->where('requested_by_student_id', $request->user()->id)
                    ->whereNull('rental_request_id')
                    ->latest(),
                'rentalRequests' => fn ($query) => $query
                    ->where('student_id', $request->user()->id)
                    ->where(fn (Builder $active) => $active
                        ->whereIn('status', ['pending', 'accepted'])
                        ->orWhere('secured_house', true))
                    ->with('payments')
                    ->latest('requested_at'),
            ])
            ->when(filled($validated['governorate'] ?? null), fn (Builder $query) => $query->where('governorate', $validated['governorate']))
            ->when(filled($validated['city'] ?? null), fn (Builder $query) => $query->where('city', $validated['city']))
            ->when(isset($validated['max_rent']), fn (Builder $query) => $query->where('public_listing_price_tnd', '<=', $validated['max_rent']))
            ->when(isset($validated['min_bedrooms']), fn (Builder $query) => $query->where('bedrooms', '>=', $validated['min_bedrooms']))
            ->when(filled($validated['furnishing_status'] ?? null), fn (Builder $query) => $query->where('furnishing_status', $validated['furnishing_status']))
            ->where('available', $request->boolean('available', true))
            ->latest()
            ->paginate(12)
            ->withQueryString();

        $houses->through(fn (House $house) => $this->houseData($house, $request->user()->id));

        return response()->json([
            'houses' => $houses,
            'student_service_fee_tnd' => config('student_housing.student_service_fee_tnd'),
        ]);
    }

    public function show(Request $request, int $house): JsonResponse
    {
        $property = $this->verifiedHouses()
            ->with([
                'owner:id,name',
                'photos',
                'inspectionReports' => $this->approvedInspectionReports(),
                'studentCheckRequests' => fn ($query) => $query
                    ->where('requested_by_student_id', $request->user()->id)
                    ->whereNull('rental_request_id')
                    ->latest(),
                'rentalRequests' => fn ($query) => $query
                    ->where('student_id', $request->user()->id)
                    ->where(fn (Builder $active) => $active
                        ->whereIn('status', ['pending', 'accepted'])
                        ->orWhere('secured_house', true))
                    ->with('payments')
                    ->latest('requested_at'),
            ])
            ->whereKey($house)
            ->firstOrFail();

        return response()->json(['house' => $this->houseData($property, $request->user()->id)]);
    }

    public function requestCheck(Request $request, int $house): JsonResponse
    {
        $student = $request->user();
        $inspection = DB::transaction(function () use ($student, $house): InspectionReport {
            $property = $this->verifiedHouses()->whereKey($house)->lockForUpdate()->firstOrFail();
            $activeRequest = $property->inspectionReports()
                ->where('requested_by_student_id', $student->id)
                ->where(function (Builder $query) {
                    $query->whereIn('status', ['requested', 'assigned'])
                        ->orWhere(fn (Builder $completed) => $completed
                            ->where('status', 'completed')
                            ->whereNull('reviewed_at'));
                })
                ->exists();

            abort_if($activeRequest, 409, 'You already have an inspection request in progress for this house.');

            return $property->inspectionReports()->create([
                'requested_by_student_id' => $student->id,
                'inspection_date' => now(),
                'status' => 'requested',
            ]);
        });

        return response()->json([
            'check_request' => $this->checkRequestData($inspection),
            'message' => 'Your inspection request was sent to the company.',
        ], 201);
    }

    public function checkRequests(Request $request): JsonResponse
    {
        $requests = $request->user()->inspectionReportsAsStudent()
            ->whereNull('rental_request_id')
            ->with(['house', 'photos'])
            ->latest()
            ->get()
            ->map(fn (InspectionReport $report) => [
                ...$this->checkRequestData($report),
                'house' => [
                    'id' => $report->house->id,
                    'title' => $report->house->title,
                    'city' => $report->house->city,
                    'governorate' => $report->house->governorate,
                    'address' => $report->house->address,
                    'monthly_rent_tnd' => $report->house->public_listing_price_tnd,
                ],
                'requested_at' => $report->created_at?->toIso8601String(),
            ]);

        return response()->json(['check_requests' => $requests]);
    }

    public function photo(int $house, int $photo): StreamedResponse
    {
        $property = $this->verifiedHouses()->whereKey($house)->firstOrFail();
        $housePhoto = $property->photos()->whereKey($photo)->firstOrFail();

        return Storage::disk($housePhoto->disk)->response(
            $housePhoto->path,
            null,
            ['X-Content-Type-Options' => 'nosniff']
        );
    }

    public function checkPhoto(Request $request, int $inspection, int $photo): StreamedResponse
    {
        $report = $request->user()->inspectionReportsAsStudent()
            ->whereKey($inspection)
            ->whereNotNull('reviewed_at')
            ->firstOrFail();
        $inspectionPhoto = $report->photos()->whereKey($photo)->firstOrFail();

        return Storage::disk($inspectionPhoto->disk)->response(
            $inspectionPhoto->path,
            null,
            ['X-Content-Type-Options' => 'nosniff']
        );
    }

    public function verifiedHouses(): Builder
    {
        return House::query()
            ->whereNull('listing_archived_at')
            ->where('available', true)
            ->where('verification_status', 'verified')
            ->whereNotNull('public_listing_price_tnd')
            ->whereNotNull('verification_decided_by')
            ->whereNotNull('verification_decided_at')
            ->whereHas('verificationDecisionMaker', fn (Builder $query) => $query->where('role', 'admin'))
            ->where(function (Builder $query) {
                $query->where(function (Builder $query) {
                    $query->where(function (Builder $query) {
                        $query->whereNull('negotiation_status')
                            ->orWhere('negotiation_status', 'price_agreed');
                    })->whereHas('inspectionReports', $this->approvedInspectionReports());
                })->orWhere(function (Builder $query) {
                    $query->whereIn('negotiation_status', ['admin_priced', 'owner_accepted', 'owner_refused'])
                        ->whereNotNull('submission_reviewed_at')
                        ->whereNotNull('submission_reviewed_by')
                        ->whereHas('submissionDecisionMaker', fn (Builder $reviewer) => $reviewer->where('role', 'admin'))
                        ->has('photos', '>=', 4);
                });
            });
    }

    private function approvedInspectionReports(): \Closure
    {
        return fn ($query) => $query
            ->whereNull('requested_by_student_id')
            ->where('status', 'completed')
            ->where('recommendation', 'approve')
            ->whereNotNull('completed_at')
            ->whereNotNull('reviewed_by')
            ->whereNotNull('reviewed_at')
            ->whereHas('worker', fn ($workerQuery) => $workerQuery->where('role', 'worker'))
            ->whereHas('reviewer', fn ($reviewerQuery) => $reviewerQuery->where('role', 'admin'))
            ->orderByDesc('inspection_date');
    }

    private function houseData(House $house, ?int $studentId = null): array
    {
        $inspection = $house->inspectionReports->first();
        $checkRequest = $studentId && $house->relationLoaded('studentCheckRequests')
            ? $house->studentCheckRequests->first()
            : null;
        $rentalRequest = $studentId && $house->relationLoaded('rentalRequests')
            ? $house->rentalRequests->first()
            : null;

        return [
            'id' => $house->id,
            'title' => $house->title,
            'description' => $house->description,
            'governorate' => $house->governorate,
            'city' => $house->city,
            'address' => $house->address,
            'monthly_rent_tnd' => $house->public_listing_price_tnd,
            'public_listing_price_tnd' => $house->public_listing_price_tnd,
            'bedrooms' => $house->bedrooms,
            'furnishing_status' => $house->furnishing_status,
            'available' => $house->available,
            'verification_status' => $house->verification_status,
            'verified_at' => $house->verification_decided_at?->toIso8601String(),
            'owner' => ['name' => $house->owner?->name],
            'photos' => $house->photos->map(fn (HousePhoto $photo) => [
                'id' => $photo->id,
                'url' => '/api/student/houses/'.$house->id.'/photos/'.$photo->id,
                'caption' => $photo->caption,
            ])->values(),
            'inspection' => $inspection ? [
                'date' => $inspection->inspection_date?->toIso8601String(),
                'checklist' => $inspection->condition_checklist,
                'defects_found' => $inspection->defects_found,
            ] : null,
            'check_request' => $checkRequest ? $this->checkRequestData($checkRequest) : null,
            'rental_request' => $rentalRequest ? $this->rentalRequestData($rentalRequest) : null,
            'created_at' => $house->created_at,
        ];
    }

    private function checkRequestData(InspectionReport $report): array
    {
        $data = [
            'id' => $report->id,
            'status' => $report->status,
            'scheduled_at' => $report->status === 'assigned' ? $report->inspection_date?->toIso8601String() : null,
            'shared_at' => $report->reviewed_at?->toIso8601String(),
        ];

        if ($report->reviewed_at) {
            $data['report'] = [
                'completed_at' => $report->completed_at?->toIso8601String(),
                'checklist' => $report->condition_checklist,
                'notes' => $report->notes,
                'defects_found' => $report->defects_found,
                'recommendation' => $report->recommendation,
                'review_notes' => $report->review_notes,
                'photos' => $report->photos->map(fn (InspectionReportPhoto $photo) => [
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

    private function rentalRequestData($rentalRequest): array
    {
        $payment = $rentalRequest->payments->first();

        return [
            'id' => $rentalRequest->id,
            'status' => $rentalRequest->status,
            'requested_at' => $rentalRequest->requested_at?->toIso8601String(),
            'secured_house' => $rentalRequest->secured_house,
            'student_confirmed_secured' => $rentalRequest->student_confirmed_secured_at !== null,
            'owner_confirmed_secured' => $rentalRequest->owner_confirmed_secured_at !== null,
            'service_fee_tnd' => $rentalRequest->service_fee_tnd,
            'service_fee_payment_status' => $rentalRequest->service_fee_payment_status,
            'payment_obligation' => $payment ? [
                'id' => $payment->id,
                'amount' => $payment->amount_tnd,
                'rent_amount' => $payment->payment_type === Payment::TYPE_STUDENT_RENT_AND_SERVICE_FEE
                    ? $rentalRequest->house?->public_listing_price_tnd
                    : null,
                'service_fee_amount' => $payment->payment_type === Payment::TYPE_STUDENT_RENT_AND_SERVICE_FEE
                    ? $rentalRequest->service_fee_tnd
                    : null,
                'currency' => $payment->currency,
                'status' => $payment->status,
                'type' => $payment->payment_type,
                'checkout_available' => false,
            ] : null,
            'house' => $rentalRequest->house ? [
                'id' => $rentalRequest->house->id,
                'title' => $rentalRequest->house->title,
                'city' => $rentalRequest->house->city,
                'governorate' => $rentalRequest->house->governorate,
                'monthly_rent_tnd' => $rentalRequest->house->public_listing_price_tnd,
            ] : null,
        ];
    }
}
