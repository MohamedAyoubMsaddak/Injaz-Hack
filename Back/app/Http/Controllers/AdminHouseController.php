<?php

namespace App\Http\Controllers;

use App\Models\House;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdminHouseController extends Controller
{
    public function index(): JsonResponse
    {
        $houses = House::query()
            ->with(['owner:id,name,email,phone', 'photos:id,house_id,disk,path,caption,sort_order'])
            ->withCount('photos')
            ->whereNull('listing_archived_at')
            ->latest()
            ->get()
            ->map(fn (House $house) => $this->houseData($house));

        return response()->json(['houses' => $houses]);
    }

    public function update(Request $request, int $house): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:160'],
            'description' => ['nullable', 'string', 'max:10000'],
            'governorate' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
            'bedrooms' => ['required', 'integer', 'min:1', 'max:30'],
            'furnishing_status' => ['required', Rule::in(['furnished', 'unfurnished', 'partially_furnished', 'unknown'])],
        ]);

        $property = DB::transaction(function () use ($house, $validated): House {
            $property = House::query()->whereKey($house)->lockForUpdate()->firstOrFail();
            abort_if($property->listing_archived_at !== null, 409, 'This listing has already been removed.');
            $property->fill($validated)->save();

            return $property->load([
                'owner:id,name,email,phone',
                'photos:id,house_id,disk,path,caption,sort_order',
            ])->loadCount('photos');
        });

        return response()->json([
            'house' => $this->houseData($property),
            'message' => 'House details updated.',
        ]);
    }

    public function destroy(int $house): JsonResponse
    {
        $deleted = DB::transaction(function () use ($house): array {
            $property = House::query()->whereKey($house)->lockForUpdate()->firstOrFail();
            abort_if($property->listing_archived_at !== null, 409, 'This listing has already been removed.');

            $files = $property->photos()->get(['disk', 'path'])->toArray();
            $inspections = $property->inspectionReports()->with('photos')->get();
            $maintenanceRequests = $property->maintenanceRequests()->with('photos')->get();
            foreach ($inspections as $inspection) {
                $files = [...$files, ...$inspection->photos->map(fn ($photo) => [
                    'disk' => $photo->disk,
                    'path' => $photo->path,
                ])->all()];
            }
            foreach ($maintenanceRequests as $maintenanceRequest) {
                $files = [...$files, ...$maintenanceRequest->photos->map(fn ($photo) => [
                    'disk' => $photo->disk,
                    'path' => $photo->path,
                ])->all()];
            }

            $inspectionIds = $inspections->modelKeys();
            $maintenanceRequestIds = $maintenanceRequests->modelKeys();
            $rentalRequestIds = $property->rentalRequests()->pluck('id');
            $subscription = $property->ownerSubscription()->first();

            DB::table('payments')->whereIn('rental_request_id', $rentalRequestIds)->delete();
            if ($subscription !== null) {
                DB::table('payments')->where('owner_subscription_id', $subscription->id)->delete();
            }

            DB::table('maintenance_notifications')->whereIn('maintenance_request_id', $maintenanceRequestIds)->delete();
            DB::table('maintenance_visits')->whereIn('maintenance_request_id', $maintenanceRequestIds)->delete();
            DB::table('maintenance_request_updates')->whereIn('maintenance_request_id', $maintenanceRequestIds)->delete();
            DB::table('maintenance_request_photos')->whereIn('maintenance_request_id', $maintenanceRequestIds)->delete();
            DB::table('maintenance_requests')->whereIn('id', $maintenanceRequestIds)->delete();

            DB::table('inspection_report_photos')->whereIn('inspection_report_id', $inspectionIds)->delete();
            DB::table('inspection_reports')->whereIn('id', $inspectionIds)->delete();
            DB::table('rental_requests')->whereIn('id', $rentalRequestIds)->delete();
            if ($subscription !== null) {
                DB::table('owner_subscriptions')->where('id', $subscription->id)->delete();
            }
            DB::table('house_photos')->where('house_id', $property->id)->delete();
            $property->delete();

            return ['house_id' => $property->id, 'files' => $files];
        });

        $mediaCleanupFailed = false;
        foreach ($deleted['files'] as $file) {
            if (! Storage::disk($file['disk'])->delete($file['path'])) {
                $mediaCleanupFailed = true;
                Log::error('Failed to delete media after permanently deleting a house post.', [
                    'house_id' => $deleted['house_id'],
                    'disk' => $file['disk'],
                    'path' => $file['path'],
                ]);
            }
        }

        return response()->json([
            'house_id' => $deleted['house_id'],
            'message' => $mediaCleanupFailed
                ? 'The house and all related records were permanently deleted, but some uploaded files could not be removed.'
                : 'The house and all related rental, payment, inspection, and maintenance records were permanently deleted.',
        ]);
    }

    private function houseData(House $house): array
    {
        return [
            'id' => $house->id,
            'title' => $house->title,
            'description' => $house->description,
            'governorate' => $house->governorate,
            'city' => $house->city,
            'address' => $house->address,
            'monthly_rent_tnd' => $house->monthly_rent_tnd,
            'public_listing_price_tnd' => $house->public_listing_price_tnd,
            'bedrooms' => $house->bedrooms,
            'furnishing_status' => $house->furnishing_status,
            'available' => $house->available,
            'verification_status' => $house->verification_status,
            'negotiation_status' => $house->negotiation_status,
            'listing_archived_at' => $house->listing_archived_at?->toIso8601String(),
            'owner_deletion_requested_at' => $house->owner_deletion_requested_at?->toIso8601String(),
            'photo_count' => $house->photos_count,
            'owner' => $house->owner?->only(['name', 'email', 'phone']),
            'photos' => $house->photos->map(fn ($photo) => [
                'id' => $photo->id,
                'caption' => $photo->caption,
                'url' => route('admin.houses.photos.show', [
                    'house' => $house->id,
                    'photo' => $photo->id,
                ]),
            ])->values(),
        ];
    }
}
