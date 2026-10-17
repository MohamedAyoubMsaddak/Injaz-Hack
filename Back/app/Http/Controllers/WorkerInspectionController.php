<?php

namespace App\Http\Controllers;

use App\Models\House;
use App\Models\InspectionReport;
use App\Models\InspectionReportPhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkerInspectionController extends Controller
{
    private const CHECKLIST_ITEMS = [
        'description_match',
        'electricity',
        'water',
        'doors_windows',
        'bathroom_kitchen',
        'furniture',
        'leaks_damage',
        'rooms_facilities',
    ];

    public function dashboard(Request $request): JsonResponse
    {
        $worker = $request->user();
        $reports = $worker->inspectionReportsAsWorker();

        return response()->json([
            'dashboard' => 'worker',
            'counts' => [
                'assigned_inspections' => (clone $reports)->count(),
                'pending_inspections' => (clone $reports)->where('status', 'assigned')->count(),
                'completed_inspections' => (clone $reports)->where('status', 'completed')->count(),
                'houses_awaiting_verification' => House::query()
                    ->where('governorate', $worker->governorate)
                    ->whereIn('verification_status', [
                        'pending',
                        'pending_verification',
                        'inspection_assigned',
                        'inspection_completed',
                        'corrections_required',
                    ])->count(),
                'assigned_maintenance_requests' => $worker->maintenanceRequestsAssigned()
                    ->whereHas('house', fn ($query) => $query->where('governorate', $worker->governorate))
                    ->count(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $reports = $request->user()->inspectionReportsAsWorker()
            ->with(['house.owner', 'photos'])
            ->orderBy('inspection_date')
            ->get();

        return response()->json([
            'inspections' => $reports->map(fn (InspectionReport $report) => $this->reportData($report)),
        ]);
    }

    public function show(Request $request, int $inspection): JsonResponse
    {
        $report = $this->assignedReport($request, $inspection)->load(['house.owner', 'photos']);

        return response()->json([
            'inspection' => $this->reportData($report),
        ]);
    }

    public function complete(Request $request, int $inspection): JsonResponse
    {
        $report = $this->assignedReport($request, $inspection);
        abort_unless($report->status === 'assigned', 409, 'This inspection is no longer awaiting a report.');

        $validated = $request->validate([
            'condition_checklist' => ['required', 'array:'.implode(',', self::CHECKLIST_ITEMS)],
            ...collect(self::CHECKLIST_ITEMS)->mapWithKeys(fn (string $item) => [
                'condition_checklist.'.$item => ['required', Rule::in(['good', 'problem_found', 'not_applicable'])],
            ])->all(),
            'notes' => ['required', 'string', 'max:10000'],
            'defects_found' => ['nullable', 'string', 'max:10000'],
            'recommendation' => ['required', Rule::in(['approve', 'reject', 'require_corrections'])],
            'photos' => ['sometimes', 'array', 'max:8'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $validator = ValidatorFacade::make($validated, []);
        $validator->after(function (Validator $validator) use ($validated): void {
            $hasProblems = collect($validated['condition_checklist'])
                ->contains(fn (string $status) => $status === 'problem_found');
            if ($hasProblems && blank($validated['defects_found'] ?? null)) {
                $validator->errors()->add('defects_found', 'Describe the defects found when any checklist item has a problem.');
            }
        });
        $validator->validate();

        $photoPaths = [];

        try {
            $report = DB::transaction(function () use ($request, $report, $validated, &$photoPaths): InspectionReport {
                $lockedReport = InspectionReport::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();
                abort_unless($lockedReport->worker_id === $request->user()->id, 404);
                abort_unless($lockedReport->status === 'assigned', 409, 'This inspection is no longer awaiting a report.');

                $lockedReport->fill([
                    'condition_checklist' => $validated['condition_checklist'],
                    'notes' => $validated['notes'],
                    'defects_found' => $validated['defects_found'] ?? null,
                    'recommendation' => $validated['recommendation'],
                    'status' => 'completed',
                    'completed_at' => now(),
                ])->save();

                foreach ($request->file('photos', []) as $photo) {
                    $path = $photo->store('inspections/'.$lockedReport->id, config('filesystems.default'));
                    if ($path === false) {
                        throw new \RuntimeException('An inspection photo could not be stored.');
                    }

                    $photoPaths[] = $path;
                    $lockedReport->photos()->create([
                        'disk' => config('filesystems.default'),
                        'path' => $path,
                    ]);
                }

                $lockedReport->house()->update(['verification_status' => 'inspection_completed']);

                return $lockedReport->load(['house.owner', 'photos']);
            });
        } catch (\Throwable $exception) {
            foreach ($photoPaths as $path) {
                Storage::disk(config('filesystems.default'))->delete($path);
            }

            throw $exception;
        }

        return response()->json([
            'inspection' => $this->reportData($report),
            'message' => 'Inspection report submitted for administrative review.',
        ]);
    }

    public function photo(Request $request, int $inspection, int $photo): StreamedResponse
    {
        $report = $this->assignedReport($request, $inspection);
        $inspectionPhoto = $report->photos()->whereKey($photo)->firstOrFail();

        return Storage::disk($inspectionPhoto->disk)->response(
            $inspectionPhoto->path,
            null,
            ['X-Content-Type-Options' => 'nosniff']
        );
    }

    private function assignedReport(Request $request, int $inspection): InspectionReport
    {
        return $request->user()->inspectionReportsAsWorker()->whereKey($inspection)->firstOrFail();
    }

    private function reportData(InspectionReport $report): array
    {
        return [
            'id' => $report->id,
            'status' => $report->status,
            'scheduled_at' => $report->inspection_date,
            'completed_at' => $report->completed_at,
            'condition_checklist' => $report->condition_checklist,
            'notes' => $report->notes,
            'defects_found' => $report->defects_found,
            'recommendation' => $report->recommendation,
            'reviewed_at' => $report->reviewed_at,
            'review_notes' => $report->review_notes,
            'house' => [
                'id' => $report->house->id,
                'title' => $report->house->title,
                'governorate' => $report->house->governorate,
                'city' => $report->house->city,
                'address' => $report->house->address,
                'verification_status' => $report->house->verification_status,
            ],
            'owner' => [
                'name' => $report->house->owner->name,
                'email' => $report->house->owner->email,
                'phone' => $report->house->owner->phone,
            ],
            'photos' => $report->photos->map(fn (InspectionReportPhoto $photo) => [
                'id' => $photo->id,
                'url' => route('worker.inspections.photos.show', [
                    'inspection' => $report->id,
                    'photo' => $photo->id,
                ]),
                'caption' => $photo->caption,
            ]),
        ];
    }
}
