<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaintenanceRequest extends Model
{
    protected $fillable = [
        'house_id',
        'reporter_id',
        'assigned_worker_id',
        'description',
        'category',
        'priority',
        'status',
        'estimated_repair_cost_tnd',
        'repair_cost_tnd',
        'cost_responsibility',
        'covered_by_service',
        'reported_at',
        'resolved_at',
        'owner_received_at',
        'owner_approved_at',
        'owner_confirmed_completed_at',
        'owner_disputed_at',
        'repair_outcome',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'repair_cost_tnd' => 'decimal:3',
            'estimated_repair_cost_tnd' => 'decimal:3',
            'covered_by_service' => 'boolean',
            'reported_at' => 'datetime',
            'resolved_at' => 'datetime',
            'owner_received_at' => 'datetime',
            'owner_approved_at' => 'datetime',
            'owner_confirmed_completed_at' => 'datetime',
            'owner_disputed_at' => 'datetime',
        ];
    }

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function assignedWorker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_worker_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(MaintenanceRequestPhoto::class);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(MaintenanceRequestUpdate::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(MaintenanceVisit::class);
    }
}
