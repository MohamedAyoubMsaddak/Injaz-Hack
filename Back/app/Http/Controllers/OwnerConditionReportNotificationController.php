<?php

namespace App\Http\Controllers;

use App\Models\InspectionReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OwnerConditionReportNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $reports = $this->ownerDamageReports($request);

        return response()->json([
            'unread_count' => (clone $reports)->whereNull('owner_read_at')->count(),
            'notifications' => $reports->latest('reviewed_at')
                ->limit(30)
                ->get()
                ->map(fn (InspectionReport $report) => $this->notificationData($report))
                ->values(),
        ]);
    }

    public function read(Request $request, int $inspection): JsonResponse
    {
        $report = $this->ownerDamageReports($request)
            ->whereKey($inspection)
            ->firstOrFail();

        if ($report->owner_read_at === null) {
            $report->update(['owner_read_at' => now()]);
        }

        return response()->json(['message' => 'Condition report notification marked as read.']);
    }

    private function ownerDamageReports(Request $request): Builder
    {
        return InspectionReport::query()
            ->with('house:id,owner_id,title')
            ->whereNotNull('requested_by_student_id')
            ->whereNotNull('reviewed_at')
            ->whereNotNull('defects_found')
            ->where('defects_found', '<>', '')
            ->whereHas('house', fn ($query) => $query->where('owner_id', $request->user()->id));
    }

    private function notificationData(InspectionReport $report): array
    {
        return [
            'id' => $report->id,
            'house_title' => $report->house->title,
            'defects_found' => $report->defects_found,
            'notes' => $report->notes,
            'condition_checklist' => $report->condition_checklist,
            'recommendation' => $report->recommendation,
            'review_notes' => $report->review_notes,
            'reviewed_at' => $report->reviewed_at?->toIso8601String(),
            'read_at' => $report->owner_read_at?->toIso8601String(),
            'created_at' => $report->created_at?->toIso8601String(),
        ];
    }
}
