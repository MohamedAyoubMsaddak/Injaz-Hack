<?php

namespace App\Http\Controllers;

use App\Models\House;
use App\Models\MaintenanceNotification;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceRequestPhoto;
use App\Models\MaintenanceRequestUpdate;
use App\Models\MaintenanceVisit;
use App\Models\RentalRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaintenanceRequestController extends Controller
{
    private const CATEGORIES = [
        'plumbing',
        'electricity',
        'furniture',
        'doors_windows',
        'appliances',
        'structural_damage',
        'other',
    ];

    public function studentIndex(Request $request): JsonResponse
    {
        $requests = MaintenanceRequest::query()
            ->where('reporter_id', $request->user()->id)
            ->with(['house:id,title,city,governorate,owner_id', 'assignedWorker:id,name', 'photos', 'updates.actor:id,name', 'visits.worker:id,name'])
            ->latest('reported_at')
            ->paginate(15);

        $requests->through(fn (MaintenanceRequest $item) => $this->requestData($item, 'student'));

        $eligibleHouses = House::query()
            ->whereHas('rentalRequests', fn ($query) => $query
                ->where('student_id', $request->user()->id)
                ->where('status', 'completed')
                ->where('secured_house', true)
                ->whereNotNull('student_confirmed_secured_at')
                ->whereNotNull('owner_confirmed_secured_at'))
            ->distinct()
            ->get(['id', 'title', 'city', 'governorate']);

        return response()->json([
            'eligible_houses' => $eligibleHouses,
            'maintenance_requests' => $requests,
        ]);
    }

    public function studentStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'house_id' => ['required', 'integer', 'exists:houses,id'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'description' => ['required', 'string', 'min:10', 'max:10000'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'reported_at' => ['sometimes', 'date', 'before_or_equal:now'],
            'photos' => ['sometimes', 'array', 'max:8'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('student_housing.maintenance_repair_policy.max_photo_kb', 5120)],
        ]);

        $paths = [];
        try {
            $maintenanceRequest = DB::transaction(function () use ($request, $validated, &$paths): MaintenanceRequest {
                $house = House::query()->whereKey($validated['house_id'])->lockForUpdate()->firstOrFail();
                $isCurrentTenant = RentalRequest::query()
                    ->where('student_id', $request->user()->id)
                    ->where('house_id', $house->id)
                    ->where('status', 'completed')
                    ->where('secured_house', true)
                    ->whereNotNull('student_confirmed_secured_at')
                    ->whereNotNull('owner_confirmed_secured_at')
                    ->exists();
                abort_unless($isCurrentTenant, 403, 'Only a confirmed tenant can report a problem for this house.');

                $maintenanceRequest = $house->maintenanceRequests()->create([
                    'reporter_id' => $request->user()->id,
                    'category' => $validated['category'],
                    'description' => $validated['description'],
                    'priority' => $validated['priority'],
                    'status' => 'awaiting_owner_response',
                    'reported_at' => $validated['reported_at'] ?? now(),
                    'covered_by_service' => config('student_housing.maintenance_repair_policy.default_covered'),
                ]);

                foreach ($request->file('photos', []) as $photo) {
                    $path = $photo->store('maintenance/'.$maintenanceRequest->id, config('filesystems.default'));
                    if ($path === false) {
                        throw new \RuntimeException('A maintenance photo could not be stored.');
                    }
                    $paths[] = $path;
                    $maintenanceRequest->photos()->create([
                        'disk' => config('filesystems.default'),
                        'path' => $path,
                        'photo_type' => 'reported',
                    ]);
                }

                $this->recordUpdate($maintenanceRequest, $request->user(), 'reported', 'Student reported a maintenance issue.');
                $this->notify($house->owner_id, $maintenanceRequest, 'request_submitted', 'A tenant submitted a maintenance request for '.$house->title.'.');

                return $maintenanceRequest->load(['house', 'assignedWorker', 'photos', 'updates.actor', 'visits.worker']);
            });
        } catch (\Throwable $exception) {
            foreach ($paths as $path) {
                Storage::disk(config('filesystems.default'))->delete($path);
            }

            throw $exception;
        }

        return response()->json([
            'maintenance_request' => $this->requestData($maintenanceRequest, 'student'),
            'message' => 'Your maintenance request was saved and sent to the property owner.',
        ], 201);
    }

    public function ownerIndex(Request $request): JsonResponse
    {
        $requests = MaintenanceRequest::query()
            ->whereHas('house', fn ($query) => $query->where('owner_id', $request->user()->id))
            ->with(['house:id,title,city,governorate,owner_id', 'reporter:id,name,email,phone', 'assignedWorker:id,name,governorate', 'photos', 'updates.actor:id,name', 'visits.worker:id,name'])
            ->latest('reported_at')
            ->paginate(15);

        $requests->through(fn (MaintenanceRequest $item) => $this->requestData($item, 'owner'));

        return response()->json(['maintenance_requests' => $requests]);
    }

    public function ownerRepairReports(Request $request): JsonResponse
    {
        $reports = MaintenanceRequest::query()
            ->where('status', 'completed')
            ->whereHas('house', fn ($query) => $query->where('owner_id', $request->user()->id))
            ->with([
                'house:id,title,city,governorate,owner_id',
                'reporter:id,name,email,phone',
                'assignedWorker:id,name,governorate',
                'photos',
                'updates.actor:id,name',
                'visits.worker:id,name',
            ])
            ->latest('resolved_at')
            ->paginate(15);

        $reports->through(fn (MaintenanceRequest $item) => $this->requestData($item, 'owner'));

        return response()->json(['repair_reports' => $reports]);
    }

    public function ownerAcknowledge(Request $request, int $maintenanceRequest): JsonResponse
    {
        $item = DB::transaction(function () use ($request, $maintenanceRequest): MaintenanceRequest {
            $item = $this->ownedRequest($request, $maintenanceRequest, true);
            abort_unless($item->status === 'awaiting_owner_response', 409, 'This request is not awaiting owner acknowledgement.');
            $item->update([
                'status' => 'reported',
                'owner_received_at' => now(),
            ]);
            $this->recordUpdate($item, $request->user(), 'owner_received', 'The owner confirmed receipt of this request.');
            $this->notify($item->reporter_id, $item, 'owner_received', 'The owner has received your maintenance request.');

            return $this->loadRequest($item);
        });

        return $this->response($item, 'owner', 'You confirmed receipt of the maintenance request.');
    }

    public function ownerNote(Request $request, int $maintenanceRequest): JsonResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'min:2', 'max:5000']]);
        $item = $this->ownedRequest($request, $maintenanceRequest);
        $this->recordUpdate($item, $request->user(), 'owner_note', $validated['note']);
        $this->notify($item->reporter_id, $item, 'status_changed', 'The owner added a note to your maintenance request.');
        $this->notify($item->assigned_worker_id, $item, 'status_changed', 'The owner added a note to an assigned maintenance request.');

        return $this->response($this->loadRequest($item), 'owner', 'Your note was saved.');
    }

    public function ownerApproveEstimate(Request $request, int $maintenanceRequest): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string', 'max:5000'],
            'covered_by_service' => ['required', 'boolean'],
            'cost_responsibility' => ['required', Rule::in(['owner', 'student', 'platform', 'shared', 'undetermined'])],
        ]);

        $item = DB::transaction(function () use ($request, $maintenanceRequest, $validated): MaintenanceRequest {
            $item = $this->ownedRequest($request, $maintenanceRequest, true);
            abort_unless(
                $item->status === 'awaiting_owner_response' && $item->estimated_repair_cost_tnd !== null,
                409,
                'There is no repair estimate awaiting your approval.'
            );
            $item->covered_by_service = $validated['covered_by_service'];
            $item->cost_responsibility = $validated['cost_responsibility'];

            if ($validated['decision'] === 'approve') {
                $item->owner_approved_at = now();
                $item->status = 'assigned';
                $event = 'estimate_approved';
                $message = 'The owner approved the repair estimate.';
            } else {
                $item->owner_approved_at = null;
                $item->status = 'assigned';
                $event = 'estimate_rejected';
                $message = 'The owner did not approve the repair estimate. Contact the owner before proceeding.';
            }

            $item->save();
            if (filled($validated['note'] ?? null)) {
                $this->recordUpdate($item, $request->user(), 'owner_note', $validated['note']);
            }
            $this->recordUpdate($item, $request->user(), $event, $message);
            $this->notify($item->assigned_worker_id, $item, 'status_changed', $message);
            $this->notify($item->reporter_id, $item, 'status_changed', $message);

            return $this->loadRequest($item);
        });

        return $this->response($item, 'owner', 'Your estimate decision was saved.');
    }

    public function ownerConfirmCompletion(Request $request, int $maintenanceRequest): JsonResponse
    {
        $item = DB::transaction(function () use ($request, $maintenanceRequest): MaintenanceRequest {
            $item = $this->ownedRequest($request, $maintenanceRequest, true);
            abort_unless($item->status === 'awaiting_confirmation', 409, 'This repair is not awaiting your confirmation.');
            $item->update([
                'status' => 'completed',
                'owner_confirmed_completed_at' => now(),
                'resolved_at' => now(),
            ]);
            $this->recordUpdate($item, $request->user(), 'repair_completed', 'The owner confirmed the repair was completed.');
            $this->notify($item->reporter_id, $item, 'repair_completed', 'The owner confirmed completion of the maintenance request.');
            $this->notify($item->assigned_worker_id, $item, 'repair_completed', 'The owner confirmed completion of your assigned repair.');

            return $this->loadRequest($item);
        });

        return $this->response($item, 'owner', 'Repair completion confirmed.');
    }

    public function ownerDisputeCompletion(Request $request, int $maintenanceRequest): JsonResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'min:5', 'max:5000']]);
        $item = DB::transaction(function () use ($request, $maintenanceRequest, $validated): MaintenanceRequest {
            $item = $this->ownedRequest($request, $maintenanceRequest, true);
            abort_unless($item->status === 'awaiting_confirmation', 409, 'This repair is not awaiting your confirmation.');
            $item->update([
                'status' => 'in_progress',
                'owner_disputed_at' => now(),
                'resolved_at' => null,
            ]);
            $this->recordUpdate($item, $request->user(), 'completion_disputed', $validated['note']);
            $this->notify($item->assigned_worker_id, $item, 'status_changed', 'The owner disputed the repair completion and added a note.');
            $this->notify($item->reporter_id, $item, 'status_changed', 'The owner requested further work on the maintenance issue.');

            return $this->loadRequest($item);
        });

        return $this->response($item, 'owner', 'The repair was returned to in-progress status.');
    }

    public function adminAssign(Request $request, int $maintenanceRequest): JsonResponse
    {
        $validated = $request->validate([
            'worker_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'worker')],
            'scheduled_at' => ['nullable', 'date', 'after_or_equal:now'],
        ]);

        $item = DB::transaction(function () use ($request, $maintenanceRequest, $validated): MaintenanceRequest {
            $item = MaintenanceRequest::query()->whereKey($maintenanceRequest)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($item->status, ['reported', 'assigned'], true), 409, 'Only acknowledged requests can be assigned.');
            $house = House::query()->whereKey($item->house_id)->firstOrFail();
            $worker = User::query()->whereKey($validated['worker_id'])->where('role', 'worker')->firstOrFail();
            abort_unless($worker->governorate && $worker->governorate === $house->governorate, 422, 'The worker is not authorized for this house governorate.');

            $item->update([
                'assigned_worker_id' => $worker->id,
                'status' => 'assigned',
            ]);
            if (filled($validated['scheduled_at'] ?? null)) {
                MaintenanceVisit::query()->create([
                    'maintenance_request_id' => $item->id,
                    'worker_id' => $worker->id,
                    'scheduled_at' => $validated['scheduled_at'],
                ]);
            }
            $this->recordUpdate($item, $request->user(), 'worker_assigned', 'A worker was assigned to this maintenance request.');
            $this->notify($worker->id, $item, 'worker_assigned', 'A maintenance request was assigned to you in '.$house->governorate.'.');
            $this->notify($item->reporter_id, $item, 'status_changed', 'A worker was assigned to your maintenance request.');
            $this->notify($house->owner_id, $item, 'status_changed', 'A worker was assigned to your property maintenance request.');

            return $this->loadRequest($item);
        });

        return $this->response($item, 'admin', 'The worker was assigned to the request.');
    }

    public function workerIndex(Request $request): JsonResponse
    {
        $requests = $request->user()->maintenanceRequestsAssigned()
            ->whereHas('house', fn ($query) => $query->where('governorate', $request->user()->governorate))
            ->with(['house:id,title,city,governorate,owner_id', 'reporter:id,name', 'photos', 'updates.actor:id,name', 'visits.worker:id,name'])
            ->latest('reported_at')
            ->paginate(15);

        $requests->through(fn (MaintenanceRequest $item) => $this->requestData($item, 'worker'));

        return response()->json(['maintenance_requests' => $requests]);
    }

    public function workerUpdate(Request $request, int $maintenanceRequest): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['schedule_visit', 'record_visit', 'start_work', 'submit_estimate', 'submit_for_confirmation', 'reject'])],
            'scheduled_at' => ['required_if:action,schedule_visit', 'nullable', 'date', 'after_or_equal:now'],
            'notes' => ['required_if:action,record_visit,start_work,submit_estimate,submit_for_confirmation,reject', 'string', 'min:3', 'max:10000'],
            'estimated_repair_cost_tnd' => ['required_if:action,submit_estimate', 'nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'actual_repair_cost_tnd' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'covered_by_service' => ['sometimes', 'boolean'],
            'cost_responsibility' => ['nullable', Rule::in(['owner', 'student', 'platform', 'shared', 'undetermined'])],
            'repair_outcome' => ['required_if:action,submit_for_confirmation', 'nullable', 'string', 'min:3', 'max:5000'],
            'photos' => ['sometimes', 'array', 'max:8'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('student_housing.maintenance_repair_policy.max_photo_kb', 5120)],
        ]);

        $paths = [];
        try {
            $item = DB::transaction(function () use ($request, $maintenanceRequest, $validated, &$paths): MaintenanceRequest {
                $item = $this->assignedRequest($request, $maintenanceRequest, true);
                $action = $validated['action'];

                if ($action === 'schedule_visit') {
                    abort_unless(in_array($item->status, ['assigned', 'visit_scheduled'], true), 409, 'A visit can only be scheduled for an assigned request.');
                    $item->status = 'visit_scheduled';
                    $item->visits()->create([
                        'worker_id' => $request->user()->id,
                        'scheduled_at' => $validated['scheduled_at'],
                        'notes' => $validated['notes'] ?? null,
                    ]);
                } elseif ($action === 'record_visit') {
                    abort_unless(in_array($item->status, ['assigned', 'visit_scheduled'], true), 409, 'A visit can only be recorded for an assigned request.');
                    $item->visits()->create([
                        'worker_id' => $request->user()->id,
                        'visited_at' => now(),
                        'notes' => $validated['notes'],
                    ]);
                } elseif ($action === 'start_work') {
                    abort_unless(in_array($item->status, ['assigned', 'visit_scheduled'], true), 409, 'Work can only start on an assigned or scheduled request.');
                    abort_unless(
                        ! config('student_housing.maintenance_repair_policy.owner_approval_required', true)
                        || ($item->estimated_repair_cost_tnd !== null && $item->owner_approved_at !== null),
                        409,
                        'The owner must approve the repair estimate before work starts.'
                    );
                    abort_unless(
                        $item->visits()->whereNotNull('visited_at')->exists(),
                        409,
                        'Record a repair visit before starting work.'
                    );
                    $item->status = 'in_progress';
                } elseif ($action === 'submit_estimate') {
                    abort_unless(in_array($item->status, ['assigned', 'visit_scheduled', 'in_progress'], true), 409, 'An estimate cannot be submitted in the current status.');
                    $item->estimated_repair_cost_tnd = $validated['estimated_repair_cost_tnd'];
                    if (array_key_exists('covered_by_service', $validated)) {
                        $item->covered_by_service = $validated['covered_by_service'];
                    }
                    if (array_key_exists('cost_responsibility', $validated)) {
                        $item->cost_responsibility = $validated['cost_responsibility'];
                    }
                    $item->owner_approved_at = null;
                    $item->status = config('student_housing.maintenance_repair_policy.owner_approval_required', true)
                        ? 'awaiting_owner_response'
                        : 'in_progress';
                } elseif ($action === 'submit_for_confirmation') {
                    abort_unless($item->status === 'in_progress', 409, 'Only an in-progress repair can be submitted for confirmation.');
                    abort_unless(
                        $item->visits()->whereNotNull('visited_at')->exists(),
                        409,
                        'Record a repair visit before submitting the work for confirmation.'
                    );
                    abort_unless(
                        ! config('student_housing.maintenance_repair_policy.owner_approval_required', true)
                        || $item->estimated_repair_cost_tnd === null
                        || $item->owner_approved_at !== null,
                        409,
                        'The owner must approve the estimate before work can be submitted for confirmation.'
                    );
                    $item->repair_cost_tnd = $validated['actual_repair_cost_tnd'] ?? $item->repair_cost_tnd;
                    $item->repair_outcome = $validated['repair_outcome'] ?? $item->repair_outcome;
                    $item->resolved_at = now();
                    $item->status = 'awaiting_confirmation';
                } else {
                    abort_unless(in_array($item->status, ['assigned', 'visit_scheduled', 'in_progress'], true), 409, 'This request cannot be rejected in its current status.');
                    $item->status = 'rejected_with_explanation';
                    $item->rejection_reason = $validated['notes'];
                }

                $item->save();

                foreach ($request->file('photos', []) as $photo) {
                    $path = $photo->store('maintenance/'.$item->id, config('filesystems.default'));
                    if ($path === false) {
                        throw new \RuntimeException('A maintenance evidence photo could not be stored.');
                    }
                    $paths[] = $path;
                    $item->photos()->create([
                        'disk' => config('filesystems.default'),
                        'path' => $path,
                        'photo_type' => 'evidence',
                    ]);
                }

                $event = match ($action) {
                    'schedule_visit' => 'visit_scheduled',
                    'record_visit' => 'visit_recorded',
                    'start_work' => 'work_started',
                    'submit_estimate' => 'estimate_submitted',
                    'submit_for_confirmation' => 'awaiting_confirmation',
                    'reject' => 'request_rejected',
                };
                $this->recordUpdate($item, $request->user(), $event, $validated['notes'] ?? null);
                $this->notifyHouseParties($item, 'status_changed', 'The maintenance request status is now '.$item->status.'.');

                return $this->loadRequest($item);
            });
        } catch (\Throwable $exception) {
            foreach ($paths as $path) {
                Storage::disk(config('filesystems.default'))->delete($path);
            }

            throw $exception;
        }

        return $this->response($item, 'worker', 'The maintenance update was saved.');
    }

    public function photo(Request $request, int $maintenanceRequest, int $photo): StreamedResponse
    {
        $item = $this->visibleRequest($request, $maintenanceRequest);
        $image = $item->photos()->whereKey($photo)->firstOrFail();

        return Storage::disk($image->disk)->response(
            $image->path,
            null,
            ['X-Content-Type-Options' => 'nosniff']
        );
    }

    public function notifications(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => $request->user()->maintenanceNotifications()->whereNull('read_at')->count(),
            'notifications' => $request->user()->maintenanceNotifications()
                ->latest()
                ->limit(30)
                ->get(['id', 'maintenance_request_id', 'event_type', 'message', 'read_at', 'created_at']),
        ]);
    }

    public function readNotification(Request $request, int $notification): JsonResponse
    {
        $item = $request->user()->maintenanceNotifications()->whereKey($notification)->firstOrFail();
        $item->update(['read_at' => now()]);

        return response()->json(['message' => 'Notification marked as read.']);
    }

    private function visibleRequest(Request $request, int $id): MaintenanceRequest
    {
        $user = $request->user();
        $query = MaintenanceRequest::query()->whereKey($id);

        if ($user->role === 'student') {
            $query->where('reporter_id', $user->id);
        } elseif ($user->role === 'owner') {
            $query->whereHas('house', fn ($houseQuery) => $houseQuery->where('owner_id', $user->id));
        } elseif ($user->role === 'worker') {
            $query->where('assigned_worker_id', $user->id)
                ->whereHas('house', fn ($houseQuery) => $houseQuery->where('governorate', $user->governorate));
        } elseif ($user->role !== 'admin') {
            abort(403);
        }

        return $query->firstOrFail();
    }

    private function ownedRequest(Request $request, int $id, bool $lock = false): MaintenanceRequest
    {
        $query = MaintenanceRequest::query()
            ->whereKey($id)
            ->whereHas('house', fn ($houseQuery) => $houseQuery->where('owner_id', $request->user()->id));
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function assignedRequest(Request $request, int $id, bool $lock = false): MaintenanceRequest
    {
        $query = $request->user()->maintenanceRequestsAssigned()
            ->whereKey($id)
            ->whereHas('house', fn ($houseQuery) => $houseQuery->where('governorate', $request->user()->governorate));
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function loadRequest(MaintenanceRequest $item): MaintenanceRequest
    {
        return $item->fresh()->load([
            'house:id,title,city,governorate,owner_id',
            'reporter:id,name,email,phone',
            'assignedWorker:id,name,governorate',
            'photos',
            'updates.actor:id,name',
            'visits.worker:id,name',
        ]);
    }

    private function response(MaintenanceRequest $item, string $audience, string $message): JsonResponse
    {
        return response()->json([
            'maintenance_request' => $this->requestData($item, $audience),
            'message' => $message,
        ]);
    }

    private function requestData(MaintenanceRequest $item, string $audience): array
    {
        $data = [
            'id' => $item->id,
            'house' => $item->house ? [
                'id' => $item->house->id,
                'title' => $item->house->title,
                'city' => $item->house->city,
                'governorate' => $item->house->governorate,
            ] : null,
            'category' => $item->category,
            'description' => $item->description,
            'priority' => $item->priority,
            'status' => $item->status,
            'reported_at' => $item->reported_at?->toIso8601String(),
            'estimated_repair_cost_tnd' => $item->estimated_repair_cost_tnd,
            'repair_cost_tnd' => $item->repair_cost_tnd,
            'covered_by_service' => $item->covered_by_service,
            'cost_responsibility' => $item->cost_responsibility,
            'owner_approval_required' => config('student_housing.maintenance_repair_policy.owner_approval_required', true),
            'owner_received_at' => $item->owner_received_at?->toIso8601String(),
            'owner_approved_at' => $item->owner_approved_at?->toIso8601String(),
            'resolved_at' => $item->resolved_at?->toIso8601String(),
            'repair_outcome' => $item->repair_outcome,
            'rejection_reason' => $item->rejection_reason,
            'photos' => $item->photos->map(fn (MaintenanceRequestPhoto $photo) => [
                'id' => $photo->id,
                'type' => $photo->photo_type,
                'url' => '/api/'.$audience.'/maintenance-requests/'.$item->id.'/photos/'.$photo->id,
                'caption' => $photo->caption,
            ])->values(),
            'updates' => $item->updates->map(fn (MaintenanceRequestUpdate $update) => [
                'event_type' => $update->event_type,
                'body' => $update->body,
                'actor' => $update->actor?->name,
                'created_at' => $update->created_at?->toIso8601String(),
            ])->values(),
            'visits' => $item->visits->map(fn (MaintenanceVisit $visit) => [
                'worker' => $visit->worker?->name,
                'scheduled_at' => $visit->scheduled_at?->toIso8601String(),
                'visited_at' => $visit->visited_at?->toIso8601String(),
                'notes' => $visit->notes,
            ])->values(),
        ];

        if (in_array($audience, ['owner', 'worker'], true)) {
            $data['student'] = $item->reporter?->only(['id', 'name', 'email', 'phone']);
        }
        if (in_array($audience, ['owner', 'student', 'admin'], true)) {
            $data['assigned_worker'] = $item->assignedWorker?->only(['id', 'name']);
        }

        return $data;
    }

    private function recordUpdate(MaintenanceRequest $item, ?User $actor, string $event, ?string $body, array $metadata = []): void
    {
        $item->updates()->create([
            'actor_id' => $actor?->id,
            'event_type' => $event,
            'body' => $body,
            'metadata' => $metadata ?: null,
        ]);
    }

    private function notify(?int $recipient, MaintenanceRequest $item, string $event, string $message): void
    {
        if ($recipient === null) {
            return;
        }

        MaintenanceNotification::query()->create([
            'recipient_id' => $recipient,
            'maintenance_request_id' => $item->id,
            'event_type' => $event,
            'message' => $message,
        ]);
    }

    private function notifyHouseParties(MaintenanceRequest $item, string $event, string $message): void
    {
        $this->notify($item->reporter_id, $item, $event, $message);
        $this->notify($item->house()->value('owner_id'), $item, $event, $message);
    }
}
