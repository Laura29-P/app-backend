<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class AuthToken extends Model
{
    protected $fillable = ['user_id', 'token_hash'];

    protected $hidden = ['token_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}