<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HousePhoto extends Model
{
    protected $fillable = [
        'house_id',
        'disk',
        'path',
        'caption',
        'sort_order',
    ];

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }
}
