<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemoryGameResult extends Model
{
    protected $fillable = [
        'client_id',
        'score',
        'moves',
        'duration_seconds',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'moves' => 'integer',
            'duration_seconds' => 'integer',
        ];
    }
}
