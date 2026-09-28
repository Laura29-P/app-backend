<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearningProgress extends Model
{
    protected $fillable = [
        'client_id',
        'age_group',
        'stars',
        'completed_exercises',
        'current_subject',
        'current_index_in_subject',
        'owned_characters',
        'selected_character',
    ];

    protected function casts(): array
    {
        return [
            'stars' => 'integer',
            'completed_exercises' => 'array',
            'current_index_in_subject' => 'integer',
            'owned_characters' => 'array',
        ];
    }
}