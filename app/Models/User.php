<?php

namespace App\Models;

use App\Enums\Role;
use App\Services\ProgressPhotoService;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    // Role, XP and streak columns are deliberately NOT mass-assignable.
    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    /** Mirror the column defaults so freshly created (not yet reloaded) models behave the same. */
    protected $attributes = [
        'is_guest' => false,
        'role' => 'user',
        'status' => 'active',
        'xp_total' => 0,
        'level' => 1,
        'workout_streak' => 0,
        'longest_workout_streak' => 0,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_guest' => 'boolean',
            'role' => Role::class,
            'last_workout_on' => 'date',
            'onboarded_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Database rows cascade, files do not: progress photos live on disk and must go with the account.
        static::deleting(function (User $user) {
            Storage::disk(ProgressPhotoService::DISK)->deleteDirectory("progress/{$user->id}");
            // Sanctum tokens are polymorphic (no foreign key), so they do not cascade.
            $user->tokens()->delete();
        });
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function notificationSetting(): HasOne
    {
        return $this->hasOne(NotificationSetting::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function workoutSessions(): HasMany
    {
        return $this->hasMany(WorkoutSession::class);
    }

    public function postureSessions(): HasMany
    {
        return $this->hasMany(PostureSession::class);
    }

    public function dietPlans(): HasMany
    {
        return $this->hasMany(DietPlan::class);
    }

    public function foodLogs(): HasMany
    {
        return $this->hasMany(FoodLog::class);
    }

    public function waterLogs(): HasMany
    {
        return $this->hasMany(WaterLog::class);
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(Measurement::class);
    }

    public function dailyLogs(): HasMany
    {
        return $this->hasMany(DailyLog::class);
    }

    public function xpEvents(): HasMany
    {
        return $this->hasMany(XpEvent::class);
    }

    public function userBadges(): HasMany
    {
        return $this->hasMany(UserBadge::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isOnboarded(): bool
    {
        return $this->onboarded_at !== null;
    }

    public function timezone(): string
    {
        return $this->profile?->timezone ?: config('app.timezone', 'UTC');
    }

    /** The user's current moment in their own timezone. */
    public function localNow(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    /** The user's current calendar date (Y-m-d) in their own timezone. */
    public function localToday(): CarbonImmutable
    {
        return $this->localNow()->startOfDay();
    }
}
