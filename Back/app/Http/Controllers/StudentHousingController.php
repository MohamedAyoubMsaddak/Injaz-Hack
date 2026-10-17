<?php

namespace App\Http\Controllers;

use App\Models\House;
use App\Models\HousePhoto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
                ->with(['owner:id,name', 'photos', 'inspectionReports' => $this->approvedInspectionReports()])
                ->latest()
                ->limit(6)
                ->get()
                ->map(fn (House $house) => $this->houseData($house)),
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
            ->with(['owner:id,name', 'photos', 'inspectionReports' => $this->approvedInspectionReports()])
            ->when(filled($validated['governorate'] ?? null), fn (Builder $query) => $query->where('governorate', $validated['governorate']))
            ->when(filled($validated['city'] ?? null), fn (Builder $query) => $query->where('city', $validated['city']))
            ->when(isset($validated['max_rent']), fn (Builder $query) => $query->where('public_listing_price_tnd', '<=', $validated['max_rent']))
            ->when(isset($validated['min_bedrooms']), fn (Builder $query) => $query->where('bedrooms', '>=', $validated['min_bedrooms']))
            ->when(filled($validated['furnishing_status'] ?? null), fn (Builder $query) => $query->where('furnishing_status', $validated['furnishing_status']))
            ->where('available', $request->boolean('available', true))
            ->latest()
            ->paginate(12)
            ->withQueryString();

        $houses->through(fn (House $house) => $this->houseData($house));

        return response()->json(['houses' => $houses]);
    }

    public function show(int $house): JsonResponse
    {
        $property = $this->verifiedHouses()
            ->with(['owner:id,name', 'photos', 'inspectionReports' => $this->approvedInspectionReports()])
            ->whereKey($house)
            ->firstOrFail();

        return response()->json(['house' => $this->houseData($property)]);
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

    public function verifiedHouses(): Builder
    {
        return House::query()
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
            ->where('status', 'completed')
            ->where('recommendation', 'approve')
            ->whereNotNull('completed_at')
            ->whereNotNull('reviewed_by')
            ->whereNotNull('reviewed_at')
            ->whereHas('worker', fn ($workerQuery) => $workerQuery->where('role', 'worker'))
            ->whereHas('reviewer', fn ($reviewerQuery) => $reviewerQuery->where('role', 'admin'))
            ->orderByDesc('inspection_date');
    }

    private function houseData(House $house): array
    {
        $inspection = $house->inspectionReports->first();

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
            'created_at' => $house->created_at,
        ];
    }

    private function rentalRequestData($rentalRequest): array
    {
        return [
            'id' => $rentalRequest->id,
            'status' => $rentalRequest->status,
            'requested_at' => $rentalRequest->requested_at?->toIso8601String(),
            'secured_house' => $rentalRequest->secured_house,
            'student_confirmed_secured' => $rentalRequest->student_confirmed_secured_at !== null,
            'owner_confirmed_secured' => $rentalRequest->owner_confirmed_secured_at !== null,
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
    }
}
