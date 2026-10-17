<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RentalRequest extends Model
{
    protected $fillable = [
        'student_id',
        'house_id',
        'requested_at',
        'status',
        'secured_house',
        'secured_at',
        'student_confirmed_secured_at',
        'owner_confirmed_secured_at',
        'service_fee_tnd',
        'service_fee_payment_status',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'secured_house' => 'boolean',
            'secured_at' => 'datetime',
            'student_confirmed_secured_at' => 'datetime',
            'owner_confirmed_secured_at' => 'datetime',
            'service_fee_tnd' => 'decimal:3',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
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
