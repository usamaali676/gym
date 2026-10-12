<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    public const STATUSES = ['trialing', 'active', 'canceled', 'expired', 'revoked'];

    protected $fillable = [
        'user_id', 'membership_plan_id', 'status', 'source', 'external_id', 'started_at', 'trial_ends_at',
        'current_period_ends_at', 'ends_at', 'canceled_at', 'auto_renew', 'granted_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'ends_at' => 'datetime',
            'canceled_at' => 'datetime',
            'expiry_reminded_at' => 'datetime',
            'auto_renew' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /** Subscriptions that currently confer access (decided by timestamps, not by a status a job must keep fresh). */
    public function scopeEffective(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['expired', 'revoked'])
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function isEffective(): bool
    {
        return ! in_array($this->status, ['expired', 'revoked'], true)
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    /** The state to show: a lapsed row reads as expired even if no job has updated it yet. */
    public function displayStatus(): string
    {
        if (in_array($this->status, ['expired', 'revoked'], true)) {
            return $this->status;
        }

        return $this->isEffective() ? $this->status : 'expired';
    }
}
