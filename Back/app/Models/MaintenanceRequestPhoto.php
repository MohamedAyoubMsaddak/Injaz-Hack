<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceRequestPhoto extends Model
{
    protected $fillable = [
        'maintenance_request_id',
        'disk',
        'path',
        'caption',
        'photo_type',
    ];

    public function maintenanceRequest(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class);
    }
}
