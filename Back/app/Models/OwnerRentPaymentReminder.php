<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OwnerRentPaymentReminder extends Model
{
    protected $fillable = [
        'rental_request_id',
        'owner_id',
        'due_date',
        'amount_tnd',
        'message',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'amount_tnd' => 'decimal:3',
            'read_at' => 'datetime',
        ];
    }

    public function rentalRequest(): BelongsTo
    {
        return $this->belongsTo(RentalRequest::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
