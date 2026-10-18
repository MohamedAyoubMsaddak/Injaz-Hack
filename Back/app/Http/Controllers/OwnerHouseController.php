<?php

namespace App\Http\Controllers;

use App\Models\House;
use App\Models\HousePhoto;
use App\Services\PaymentObligationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OwnerHouseController extends Controller
{
    private const MAX_PHOTOS = 8;

    public function index(Request $request): JsonResponse
    {
        $houses = $request->user()->houses()
            ->whereNull('listing_archived_at')
            ->with('photos:id,house_id,disk,path,caption,sort_order')
            ->latest()
            ->get();

        return response()->json([
            'houses' => $houses->map(fn (House $house) => $this->houseData($house)),
        ]);
    }

    public function store(Request $request, PaymentObligationService $obligations): JsonResponse
    {
        $validated = $request->validate($this->rules($request));
        $photoPaths = [];

        try {
            $house = DB::transaction(function () use ($request, $validated, &$photoPaths, $obligations): House {
                $house = $request->user()->houses()->create([
                    ...$this->houseAttributes($validated),
                    'verification_status' => 'pending_negotiation',
                ]);
                $house->forceFill(['negotiation_status' => 'pending'])->save();
                $startsOn = CarbonImmutable::today();
                $subscription = $request->user()->ownerSubscriptions()->create([
                    'house_id' => $house->id,
                    'monthly_price_tnd' => config('student_housing.owner_subscription_monthly_tnd', '20.000'),
                    'status' => 'pending',
                    'starts_on' => $startsOn->toDateString(),
                    'next_renewal_on' => $startsOn->addMonthNoOverflow()->toDateString(),
                    'payment_status' => 'unpaid',
                ]);
                $obligations->createOwnerSubscriptionPayment($subscription);

                foreach ($request->file('photos', []) as $photo) {
                    $path = $photo->store('houses/'.$house->id, config('filesystems.default'));
                    if ($path === false) {
                        throw new \RuntimeException('A house photo could not be stored.');
                    }

                    $photoPaths[] = $path;
                    $house->photos()->create([
                        'disk' => config('filesystems.default'),
                        'path' => $path,
                    ]);
                }

                return $house->load('photos');
            });
        } catch (\Throwable $exception) {
            foreach ($photoPaths as $path) {
                Storage::disk(config('filesystems.default'))->delete($path);
            }

            throw $exception;
        }

        return response()->json([
            'house' => $this->houseData($house),
            'message' => 'House created and submitted for administrator review.',
        ], 201);
    }

    public function show(Request $request, int $house): JsonResponse
    {
        return response()->json([
            'house' => $this->houseData($this->ownedHouse($request, $house)->load('photos')),
        ]);
    }

    public function update(Request $request, int $house): JsonResponse
    {
        $ownedHouse = $this->ownedHouse($request, $house);
        abort_if($ownedHouse->owner_deletion_requested_at !== null, 409, 'This house is awaiting administrator approval for removal.');
        $validated = $request->validate($this->rules($request, $ownedHouse));
        if (
            $ownedHouse->public_listing_price_tnd !== null
            && (string) $validated['monthly_rent_tnd'] !== (string) $ownedHouse->monthly_rent_tnd
        ) {
            abort(409, 'The owner rent cannot be changed after price agreement. Contact Sakani staff to review it.');
        }
        $photoPaths = [];

        try {
            $ownedHouse = DB::transaction(function () use ($request, $validated, $ownedHouse, &$photoPaths): House {
                $ownedHouse->fill($this->houseAttributes($validated))->save();

                foreach ($request->file('photos', []) as $photo) {
                    $path = $photo->store('houses/'.$ownedHouse->id, config('filesystems.default'));
                    if ($path === false) {
                        throw new \RuntimeException('A house photo could not be stored.');
                    }

                    $photoPaths[] = $path;
                    $ownedHouse->photos()->create([
                        'disk' => config('filesystems.default'),
                        'path' => $path,
                    ]);
                }

                return $ownedHouse->load('photos');
            });
        } catch (\Throwable $exception) {
            foreach ($photoPaths as $path) {
                Storage::disk(config('filesystems.default'))->delete($path);
            }

            throw $exception;
        }

        return response()->json([
            'house' => $this->houseData($ownedHouse),
            'message' => 'House details updated.',
        ]);
    }

    public function requestDeletion(Request $request, int $house): JsonResponse
    {
        $property = DB::transaction(function () use ($request, $house): House {
            $property = $request->user()->houses()->whereKey($house)->lockForUpdate()->firstOrFail();
            abort_if($property->listing_archived_at !== null, 409, 'This listing has already been removed.');
            abort_if($property->owner_deletion_requested_at !== null, 409, 'A removal request for this house is already awaiting administrator approval.');

            $property->forceFill(['owner_deletion_requested_at' => now()])->save();

            return $property;
        });

        return response()->json([
            'house_id' => $property->id,
            'owner_deletion_requested_at' => $property->owner_deletion_requested_at?->toIso8601String(),
            'message' => 'Your removal request was sent to the administrator. The house will remain listed until approved.',
        ], 202);
    }

    public function photo(Request $request, int $house, int $photo): StreamedResponse
    {
        $ownedHouse = $this->ownedHouse($request, $house);
        $housePhoto = $ownedHouse->photos()->whereKey($photo)->firstOrFail();

        return Storage::disk($housePhoto->disk)->response(
            $housePhoto->path,
            null,
            ['X-Content-Type-Options' => 'nosniff']
        );
    }

    private function ownedHouse(Request $request, int $house): House
    {
        return $request->user()->houses()->whereKey($house)->firstOrFail();
    }

    private function rules(Request $request, ?House $house = null): array
    {
        $photoCount = $house?->photos()->count() ?? 0;
        $maximumAdditionalPhotos = self::MAX_PHOTOS - $photoCount;

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'governorate' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'monthly_rent_tnd' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'bedrooms' => ['required', 'integer', 'min:1', 'max:255'],
            'furnishing_status' => ['required', Rule::in(['furnished', 'unfurnished', 'partially_furnished', 'unknown'])],
            'available' => ['required', 'boolean'],
            'photos' => $house === null
                ? ['required', 'array', 'min:4', 'max:'.self::MAX_PHOTOS]
                : ['sometimes', 'array', 'max:'.max(0, $maximumAdditionalPhotos)],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    private function houseAttributes(array $validated): array
    {
        return [
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'governorate' => $validated['governorate'],
            'city' => $validated['city'],
            'address' => $validated['address'] ?? null,
            'monthly_rent_tnd' => $validated['monthly_rent_tnd'],
            'bedrooms' => $validated['bedrooms'],
            'furnishing_status' => $validated['furnishing_status'],
            'available' => $validated['available'],
        ];
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
            'agreed_owner_rent_tnd' => $house->agreed_owner_rent_tnd,
            'public_listing_price_tnd' => $house->public_listing_price_tnd,
            'negotiation_status' => $house->negotiation_status,
            'submission_reviewed_at' => $house->submission_reviewed_at?->toIso8601String(),
            'negotiation_last_contact_at' => $house->negotiation_last_contact_at?->toIso8601String(),
            'bedrooms' => $house->bedrooms,
            'furnishing_status' => $house->furnishing_status,
            'available' => $house->available,
            'verification_status' => $house->verification_status,
            'listing_archived_at' => $house->listing_archived_at?->toIso8601String(),
            'owner_deletion_requested_at' => $house->owner_deletion_requested_at?->toIso8601String(),
            'photos' => $house->photos->map(fn (HousePhoto $photo) => [
                'id' => $photo->id,
                'url' => route('owner.houses.photos.show', [
                    'house' => $house->id,
                    'photo' => $photo->id,
                ]),
                'caption' => $photo->caption,
            ]),
            'created_at' => $house->created_at,
            'updated_at' => $house->updated_at,
        ];
    }
}
