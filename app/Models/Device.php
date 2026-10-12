<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One app install signed in to one account (mobile API). */
class Device extends Model
{
    protected $fillable = [
        'installation_id', 'platform', 'name', 'app_version', 'app_build', 'os_version', 'locale', 'timezone',
    ];

    protected $hidden = ['push_token', 'push_token_hash'];

    protected function casts(): array
    {
        return [
            'push_token' => 'encrypted',
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Forget the push token (sign-out, token revoked, or the OS invalidated it). */
    public function clearPushToken(): void
    {
        $this->forceFill(['push_provider' => null, 'push_token' => null, 'push_token_hash' => null])->save();
    }
}
