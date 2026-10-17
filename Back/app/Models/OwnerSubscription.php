<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OwnerSubscription extends Model
{
    protected $fillable = [
        'owner_id',
        'house_id',
        'monthly_price_tnd',
        'status',
        'starts_on',
        'next_renewal_on',
        'payment_status',
    ];

    protected function casts(): array
    {
        return [
            'monthly_price_tnd' => 'decimal:3',
            'starts_on' => 'date',
            'next_renewal_on' => 'date',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
