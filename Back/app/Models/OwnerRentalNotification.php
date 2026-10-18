<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OwnerRentalNotification extends Model
{
    public const EVENT_REQUEST_ACCEPTED = 'request_accepted';

    protected $fillable = [
        'owner_id',
        'rental_request_id',
        'event_type',
        'house_title',
        'student_name',
        'amount_tnd',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
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
