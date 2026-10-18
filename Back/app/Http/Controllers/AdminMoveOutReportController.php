<?php

namespace App\Http\Controllers;

use App\Models\StudentMoveOutReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMoveOutReportController extends Controller
{
    public function index(): JsonResponse
    {
        $reports = StudentMoveOutReport::query()
            ->with(['rentalRequest.house:id,title,city,governorate,address', 'student:id,name,email,phone'])
            ->latest()
            ->get()
            ->map(fn (StudentMoveOutReport $report) => [
                'id' => $report->id,
                'planned_move_out_at' => $report->planned_move_out_at?->toDateString(),
                'message' => $report->message,
                'status' => $report->status,
                'reviewed_at' => $report->reviewed_at?->toIso8601String(),
                'created_at' => $report->created_at?->toIso8601String(),
                'student' => $report->student->only(['name', 'email', 'phone']),
                'house' => $report->rentalRequest->house->only(['id', 'title', 'city', 'governorate', 'address']),
            ]);

        return response()->json(['reports' => $reports]);
    }

    public function review(Request $request, int $report): JsonResponse
    {
        $moveOutReport = StudentMoveOutReport::query()->whereKey($report)->firstOrFail();

        if ($moveOutReport->status === 'submitted') {
            $moveOutReport->update([
                'status' => 'reviewed',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Move-out report marked as reviewed.',
            'reviewed_at' => $moveOutReport->fresh()->reviewed_at?->toIso8601String(),
        ]);
    }
}
