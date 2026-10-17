<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const TYPE_OWNER_SUBSCRIPTION = 'owner_subscription';

    public const TYPE_STUDENT_SERVICE_FEE = 'student_service_fee';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'payment_reference',
        'payer_id',
        'payment_type',
        'owner_subscription_id',
        'rental_request_id',
        'billing_period',
        'amount_tnd',
        'currency',
        'status',
        'provider',
        'provider_transaction_reference',
        'provider_event_reference',
        'failure_reason',
        'paid_at',
        'failed_at',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_tnd' => 'decimal:3',
            'billing_period' => 'date',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_id');
    }

    public function ownerSubscription(): BelongsTo
    {
        return $this->belongsTo(OwnerSubscription::class);
    }

    public function rentalRequest(): BelongsTo
    {
        return $this->belongsTo(RentalRequest::class);
    }
}
