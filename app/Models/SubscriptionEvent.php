<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only log of everything that changed a membership (admin actions, store notifications). */
class SubscriptionEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['subscription_id', 'user_id', 'type', 'provider', 'external_event_id', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
