<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class House extends Model
{
    protected $fillable = [
        'title',
        'description',
        'governorate',
        'city',
        'address',
        'monthly_rent_tnd',
        'bedrooms',
        'furnishing_status',
        'available',
        'verification_status',
    ];

    protected function casts(): array
    {
        return [
            'monthly_rent_tnd' => 'decimal:3',
            'public_listing_price_tnd' => 'decimal:3',
            'proposed_owner_rent_tnd' => 'decimal:3',
            'agreed_owner_rent_tnd' => 'decimal:3',
            'negotiation_last_contact_at' => 'datetime',
            'submission_reviewed_at' => 'datetime',
            'price_agreed_at' => 'datetime',
            'available' => 'boolean',
            'verification_decided_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(HousePhoto::class);
    }

    public function inspectionReports(): HasMany
    {
        return $this->hasMany(InspectionReport::class);
    }

    public function verificationDecisionMaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verification_decided_by');
    }

    public function rentalRequests(): HasMany
    {
        return $this->hasMany(RentalRequest::class);
    }

    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class);
    }

    public function ownerSubscription(): HasOne
    {
        return $this->hasOne(OwnerSubscription::class);
    }

    public function priceAgreementMaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'price_agreed_by');
    }

    public function submissionDecisionMaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submission_reviewed_by');
    }
}
