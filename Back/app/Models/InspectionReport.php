<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InspectionReport extends Model
{
    protected $fillable = [
        'house_id',
        'worker_id',
        'inspection_date',
        'condition_checklist',
        'defects_found',
        'notes',
        'status',
        'recommendation',
        'completed_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'inspection_date' => 'datetime',
            'condition_checklist' => 'array',
            'completed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(InspectionReportPhoto::class);
    }
}
