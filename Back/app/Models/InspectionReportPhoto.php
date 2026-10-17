<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionReportPhoto extends Model
{
    protected $fillable = [
        'inspection_report_id',
        'disk',
        'path',
        'caption',
    ];

    public function inspectionReport(): BelongsTo
    {
        return $this->belongsTo(InspectionReport::class);
    }
}
