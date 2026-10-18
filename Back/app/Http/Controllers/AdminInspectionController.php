<?php

namespace App\Http\Controllers;

use App\Models\House;
use App\Models\InspectionReport;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminInspectionController extends Controller
{
    public function pendingReviews(): JsonResponse
    {
        $reports = InspectionReport::query()
            ->with(['house.owner', 'worker', 'photos', 'requestedByStudent'])
            ->where('status', 'completed')
            ->whereNull('reviewed_at')
            ->where(fn ($query) => $query
                ->whereNotNull('requested_by_student_id')
                ->orWhereHas('house', fn ($house) => $house
                    ->where('verification_status', 'inspection_completed')
                    ->where(fn ($negotiation) => $negotiation
                        ->whereNull('negotiation_status')
                        ->orWhere('negotiation_status', 'price_agreed'))))
            ->orderBy('completed_at')
            ->get();

        return response()->json([
            'inspections' => $reports->map(fn (InspectionReport $report) => $this->reviewData($report)),
        ]);
    }

    public function studentRequests(): JsonResponse
    {
        $reports = InspectionReport::query()
            ->with(['house.owner', 'requestedByStudent'])
            ->whereNotNull('requested_by_student_id')
            ->where('status', 'requested')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'requests' => $reports->map(fn (InspectionReport $report) => [
                'id' => $report->id,
                'rental_request_id' => $report->rental_request_id,
                'requested_at' => $report->created_at?->toIso8601String(),
                'student' => $report->requestedByStudent?->only(['name', 'email', 'phone']),
                'house' => [
                    'id' => $report->house->id,
                    'title' => $report->house->title,
                    'city' => $report->house->city,
                    'governorate' => $report->house->governorate,
                    'address' => $report->house->address,
                ],
            ]),
        ]);
    }

    public function reviewPhoto(int $inspection, int $photo): StreamedResponse
    {
        $report = InspectionReport::query()
            ->whereKey($inspection)
            ->where('status', 'completed')
            ->where(fn ($query) => $query
                ->whereNotNull('requested_by_student_id')
                ->orWhereHas('house', fn ($house) => $house
                    ->where('verification_status', 'inspection_completed')))
            ->firstOrFail();
        $inspectionPhoto = $report->photos()->whereKey($photo)->firstOrFail();

        return Storage::disk($inspectionPhoto->disk)->response(
            $inspectionPhoto->path,
            null,
            ['X-Content-Type-Options' => 'nosniff']
        );
    }

    public function workers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'governorate' => ['required', 'string', 'max:100'],
        ]);

        return response()->json([
            'workers' => User::query()
                ->where('role', 'worker')
                ->where('governorate', $validated['governorate'])
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'governorate']),
        ]);
    }

    public function assign(Request $request, int $house): JsonResponse
    {
        $validated = $request->validate([
            'worker_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'worker')],
            'scheduled_at' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $inspection = DB::transaction(function () use ($house, $validated): InspectionReport {
            $property = House::query()->whereKey($house)->lockForUpdate()->firstOrFail();
            abort_unless(
                $property->negotiation_status === null || $property->negotiation_status === 'price_agreed',
                409,
                'A phone price agreement must be recorded before inspection assignment.'
            );
            $worker = User::query()->whereKey($validated['worker_id'])
                ->where('role', 'worker')
                ->firstOrFail();

            abort_unless($worker->governorate && $worker->governorate === $property->governorate, 422, 'The worker is not assigned to this house governorate.');
            abort_unless(in_array($property->verification_status, ['pending', 'pending_verification', 'corrections_required'], true), 409, 'This house is not awaiting an inspection.');
            abort_if($property->inspectionReports()->where('status', 'assigned')->exists(), 409, 'This house already has an assigned inspection.');

            $inspection = $property->inspectionReports()->create([
                'worker_id' => $worker->id,
                'inspection_date' => $validated['scheduled_at'],
                'status' => 'assigned',
            ]);
            $property->update(['verification_status' => 'inspection_assigned']);

            return $inspection;
        });

        return response()->json([
            'inspection_id' => $inspection->id,
            'status' => $inspection->status,
            'message' => 'Inspection assigned to the governorate worker.',
        ], 201);
    }

    public function assignStudentRequest(Request $request, int $inspection): JsonResponse
    {
        $validated = $request->validate([
            'worker_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'worker')],
            'scheduled_at' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $report = DB::transaction(function () use ($inspection, $validated): InspectionReport {
            $report = InspectionReport::query()->whereKey($inspection)->lockForUpdate()->firstOrFail();
            abort_unless($report->requested_by_student_id !== null, 404);
            abort_unless($report->status === 'requested', 409, 'This student inspection request is no longer awaiting assignment.');
            $house = House::query()->whereKey($report->house_id)->firstOrFail();
            $worker = User::query()->whereKey($validated['worker_id'])
                ->where('role', 'worker')
                ->firstOrFail();

            abort_unless($worker->governorate && $worker->governorate === $house->governorate, 422, 'The worker is not assigned to this house governorate.');

            $report->update([
                'worker_id' => $worker->id,
                'inspection_date' => $validated['scheduled_at'],
                'status' => 'assigned',
            ]);

            return $report;
        });

        return response()->json([
            'inspection_id' => $report->id,
            'status' => $report->status,
            'message' => 'Inspection assigned to the governorate worker.',
        ]);
    }

    public function decide(Request $request, int $inspection): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['verified', 'rejected', 'corrections_required', 'report_shared'])],
            'review_notes' => ['nullable', 'string', 'max:10000'],
        ]);

        $report = DB::transaction(function () use ($request, $inspection, $validated): InspectionReport {
            $report = InspectionReport::query()->whereKey($inspection)->lockForUpdate()->firstOrFail();
            abort_unless($report->status === 'completed', 409, 'Only completed inspections can be reviewed.');
            abort_if($report->reviewed_at !== null, 409, 'This inspection already has a final decision.');
            $studentRequested = $report->requested_by_student_id !== null;
            abort_unless(
                $studentRequested === ($validated['decision'] === 'report_shared'),
                422,
                'The selected decision does not match this inspection type.'
            );

            if ($studentRequested) {
                $report->update([
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => now(),
                    'review_notes' => $validated['review_notes'] ?? null,
                ]);

                return $report->load(['house.owner', 'worker', 'photos', 'requestedByStudent']);
            }

            abort_unless(
                $validated['decision'] !== 'verified' || $report->recommendation === 'approve',
                422,
                'The worker must recommend approval before the house can be verified.'
            );

            $house = House::query()->whereKey($report->house_id)->lockForUpdate()->firstOrFail();
            abort_unless(
                $house->negotiation_status === null || $house->negotiation_status === 'price_agreed',
                409,
                'A phone price agreement must be recorded before verification.'
            );
            abort_unless($house->verification_status === 'inspection_completed', 409, 'The house is not awaiting an inspection decision.');

            $report->update([
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_notes' => $validated['review_notes'] ?? null,
            ]);
            $house->forceFill([
                'verification_status' => $validated['decision'],
                'verification_decided_by' => $request->user()->id,
                'verification_decided_at' => now(),
            ])->save();

            return $report->load(['house.owner', 'worker', 'photos']);
        });

        return response()->json([
            'house_id' => $report->house_id,
            'verification_status' => $report->house->verification_status,
            'message' => $report->requested_by_student_id
                ? 'Inspection report shared with the requesting student.'
                : 'Final verification decision recorded.',
        ]);
    }

    private function reviewData(InspectionReport $report): array
    {
        return [
            'id' => $report->id,
            'rental_request_id' => $report->rental_request_id,
            'completed_at' => $report->completed_at?->toIso8601String(),
            'condition_checklist' => $report->condition_checklist,
            'notes' => $report->notes,
            'defects_found' => $report->defects_found,
            'recommendation' => $report->recommendation,
            'house' => [
                'id' => $report->house->id,
                'title' => $report->house->title,
                'city' => $report->house->city,
                'governorate' => $report->house->governorate,
                'address' => $report->house->address,
                'public_listing_price_tnd' => $report->house->public_listing_price_tnd,
            ],
            'owner' => $report->house->owner?->only(['name', 'email', 'phone']),
            'worker' => $report->worker?->only(['name', 'email']),
            'student' => $report->requestedByStudent?->only(['name', 'email', 'phone']),
            'photos' => $report->photos->map(fn ($photo) => [
                'id' => $photo->id,
                'url' => route('admin.inspections.photos.show', [
                    'inspection' => $report->id,
                    'photo' => $photo->id,
                ]),
                'caption' => $photo->caption,
            ])->values(),
        ];
    }
}
