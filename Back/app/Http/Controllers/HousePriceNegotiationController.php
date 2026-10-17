<?php

namespace App\Http\Controllers;

use App\Models\House;
use App\Models\HousePhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HousePriceNegotiationController extends Controller
{
    public function index(): JsonResponse
    {
        $houses = House::query()
            ->with(['owner:id,name,email,phone', 'photos:id,house_id,disk,path,caption,sort_order'])
            ->withCount('photos')
            ->whereNotIn('verification_status', ['verified', 'rejected'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (House $house) => $this->houseData($house));

        return response()->json([
            'listing_margin_tnd' => config('student_housing.listing_margin_tnd', '20.000'),
            'houses' => $houses,
        ]);
    }

    public function photo(int $house, int $photo): StreamedResponse
    {
        $property = House::query()->whereKey($house)->firstOrFail();
        $housePhoto = $property->photos()->whereKey($photo)->firstOrFail();

        return Storage::disk($housePhoto->disk)->response(
            $housePhoto->path,
            null,
            ['X-Content-Type-Options' => 'nosniff']
        );
    }

    public function reviewSubmission(Request $request, int $house): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['owner_accepted', 'owner_refused', 'reject'])],
            'public_listing_price_tnd' => ['prohibited'],
        ]);

        $property = DB::transaction(function () use ($request, $house, $validated): House {
            $property = House::query()->whereKey($house)->lockForUpdate()->firstOrFail();
            abort_unless(
                $property->verification_status === 'pending_negotiation'
                    && $property->negotiation_status === 'pending',
                409,
                'This house submission has already been reviewed or is no longer awaiting a decision.'
            );

            $decision = $validated['decision'];
            $published = $decision !== 'reject';
            if ($published && $property->photos()->count() < 4) {
                abort(422, 'At least four owner-uploaded photos are required before this house can be accepted and published.');
            }

            $originalRent = $this->toMilliunits($property->monthly_rent_tnd);
            $suggestedOwnerRent = $this->fromMilliunits(intdiv($originalRent * 95 + 50, 100));
            $publicPrice = $decision === 'owner_refused'
                ? $this->fromMilliunits($this->increaseFivePercentAndRoundToTen($originalRent))
                : ($decision === 'owner_accepted' ? $property->monthly_rent_tnd : null);

            if ($publicPrice !== null && $this->toMilliunits($publicPrice) > 9_999_999_999) {
                throw ValidationException::withMessages([
                    'decision' => 'The calculated public price exceeds the supported amount.',
                ]);
            }

            $property->forceFill([
                'verification_status' => $published ? 'verified' : 'rejected',
                'negotiation_status' => $decision === 'reject' ? 'rejected' : $decision,
                'proposed_owner_rent_tnd' => $suggestedOwnerRent,
                'agreed_owner_rent_tnd' => $decision === 'owner_accepted'
                    ? $suggestedOwnerRent
                    : ($decision === 'owner_refused' ? $property->monthly_rent_tnd : null),
                'public_listing_price_tnd' => $publicPrice,
                'available' => $published,
                'submission_reviewed_at' => now(),
                'submission_reviewed_by' => $request->user()->id,
                'verification_decided_at' => $published ? now() : null,
                'verification_decided_by' => $published ? $request->user()->id : null,
            ])->save();

            return $property->load(['owner:id,name,email,phone', 'photos:id,house_id,disk,path,caption,sort_order']);
        });

        return response()->json([
            'house' => $this->houseData($property),
            'message' => match ($validated['decision']) {
                'owner_accepted' => 'The owner accepted the 5% rent reduction. The house is published at its original public rent.',
                'owner_refused' => 'The owner refused the rent reduction. The house is published at the original rent increased by 5% and rounded to the nearest ten TND.',
                default => 'House submission rejected. The owner can see the decision in My Houses.',
            },
        ]);
    }

    public function update(Request $request, int $house): JsonResponse
    {
        $validated = $request->validate([
            'negotiation_status' => ['required', Rule::in(['negotiation_in_progress', 'no_agreement', 'price_agreed'])],
            'proposed_owner_rent_tnd' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'decimal:0,3', 'max:9999999.999'],
            'agreed_owner_rent_tnd' => [
                Rule::requiredIf($request->input('negotiation_status') === 'price_agreed'),
                'nullable',
                'numeric',
                'gt:0',
                'decimal:0,3',
                'max:9999999.999',
            ],
            'negotiation_notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ]);

        $updatedHouse = DB::transaction(function () use ($request, $house, $validated): House {
            $property = House::query()->whereKey($house)->lockForUpdate()->firstOrFail();
            abort_unless(
                in_array($property->verification_status, ['pending_negotiation', 'negotiation_in_progress'], true)
                    && $property->negotiation_status !== 'pending',
                409,
                'New submissions must use the owner response actions and automatic pricing.'
            );

            if (
                isset($validated['proposed_owner_rent_tnd'])
                && $this->toMilliunits($validated['proposed_owner_rent_tnd']) > $this->toMilliunits($property->monthly_rent_tnd)
            ) {
                throw ValidationException::withMessages([
                    'proposed_owner_rent_tnd' => 'A proposed owner rent cannot exceed the original requested rent.',
                ]);
            }

            $status = $validated['negotiation_status'];
            $property->negotiation_status = $status === 'negotiation_in_progress'
                ? 'negotiation_in_progress'
                : ($status === 'no_agreement' ? 'no_agreement' : 'price_agreed');
            if (array_key_exists('proposed_owner_rent_tnd', $validated)) {
                $property->proposed_owner_rent_tnd = $validated['proposed_owner_rent_tnd'];
            }
            $property->negotiation_notes = array_key_exists('negotiation_notes', $validated)
                ? $validated['negotiation_notes']
                : $property->negotiation_notes;
            $property->negotiation_last_contact_at = now();

            if ($status === 'price_agreed') {
                $agreedRent = $validated['agreed_owner_rent_tnd'];
                if ($this->toMilliunits($agreedRent) > $this->toMilliunits($property->monthly_rent_tnd)) {
                    throw ValidationException::withMessages([
                        'agreed_owner_rent_tnd' => 'The final owner rent cannot exceed the original requested rent.',
                    ]);
                }

                $publicPrice = $this->toMilliunits($agreedRent)
                    + $this->toMilliunits(config('student_housing.listing_margin_tnd', '20.000'));
                if ($publicPrice > 9_999_999_999) {
                    throw ValidationException::withMessages([
                        'agreed_owner_rent_tnd' => 'The calculated public listing price exceeds the supported amount.',
                    ]);
                }

                $property->agreed_owner_rent_tnd = $agreedRent;
                $property->public_listing_price_tnd = $this->fromMilliunits($publicPrice);
                $property->price_agreed_at = now();
                $property->price_agreed_by = $request->user()->id;
                $property->verification_status = 'pending_verification';
            } else {
                $property->agreed_owner_rent_tnd = null;
                $property->public_listing_price_tnd = null;
                $property->price_agreed_at = null;
                $property->price_agreed_by = null;
                $property->verification_status = $status === 'negotiation_in_progress'
                    ? 'negotiation_in_progress'
                    : 'pending_negotiation';
            }

            $property->save();

            return $property->load('owner:id,name,email,phone');
        });

        return response()->json([
            'house' => $this->houseData($updatedHouse),
            'message' => $updatedHouse->negotiation_status === 'price_agreed'
                ? 'The agreed owner rent and public listing price were recorded. The house is now pending inspection.'
                : 'The phone negotiation update was recorded.',
        ]);
    }

    private function houseData(House $house): array
    {
        return [
            'id' => $house->id,
            'title' => $house->title,
            'city' => $house->city,
            'governorate' => $house->governorate,
            'original_owner_rent_tnd' => $house->monthly_rent_tnd,
            'proposed_owner_rent_tnd' => $house->proposed_owner_rent_tnd,
            'suggested_owner_rent_tnd' => $this->fromMilliunits(
                intdiv($this->toMilliunits($house->monthly_rent_tnd) * 95 + 50, 100)
            ),
            'refusal_public_price_tnd' => $this->fromMilliunits(
                $this->increaseFivePercentAndRoundToTen($this->toMilliunits($house->monthly_rent_tnd))
            ),
            'agreed_owner_rent_tnd' => $house->agreed_owner_rent_tnd,
            'public_listing_price_tnd' => $house->public_listing_price_tnd,
            'negotiation_status' => $house->negotiation_status,
            'verification_status' => $house->verification_status,
            'photo_count' => $house->photos_count ?? $house->photos->count(),
            'photos' => $house->photos->map(fn (HousePhoto $photo) => [
                'id' => $photo->id,
                'url' => route('admin.houses.photos.show', [
                    'house' => $house->id,
                    'photo' => $photo->id,
                ]),
                'caption' => $photo->caption,
            ])->values(),
            'submission_reviewed_at' => $house->submission_reviewed_at?->toIso8601String(),
            'negotiation_notes' => $house->negotiation_notes,
            'negotiation_last_contact_at' => $house->negotiation_last_contact_at?->toIso8601String(),
            'owner' => $house->owner?->only(['id', 'name', 'email', 'phone']),
        ];
    }

    private function toMilliunits(string|int $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '');

        return ((int) $whole * 1000) + (int) str_pad(substr($fraction, 0, 3), 3, '0');
    }

    private function fromMilliunits(int $amount): string
    {
        return intdiv($amount, 1000).'.'.str_pad((string) ($amount % 1000), 3, '0', STR_PAD_LEFT);
    }

    private function increaseFivePercentAndRoundToTen(int $originalMilliunits): int
    {
        $increasedWholeTnd = intdiv($originalMilliunits * 105, 100_000);
        $roundedTnd = intdiv($increasedWholeTnd, 10) * 10
            + ($increasedWholeTnd % 10 >= 5 ? 10 : 0);

        return $roundedTnd * 1000;
    }
}
