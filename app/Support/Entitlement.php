<?php

namespace App\Support;

/**
 * Every capability a membership plan can grant. The list lives in code because enforcement lives in code
 * (the `entitled:<key>` middleware and per-resource checks); admins decide which plan grants which key.
 */
enum Entitlement: string
{
    case Workouts = 'workouts';
    case ProgramsPremium = 'programs.premium';
    case LibraryPremium = 'library.premium';
    case Nutrition = 'nutrition';
    case PostureCoach = 'posture_coach';
    case Analytics = 'analytics';
    case ProgressPhotos = 'progress_photos';
    case AiCoach = 'coach.ai';

    public function label(): string
    {
        return match ($this) {
            self::Workouts => 'Workouts and training plan',
            self::ProgramsPremium => 'Premium programs',
            self::LibraryPremium => 'Premium exercise library',
            self::Nutrition => 'Nutrition and food tracking',
            self::PostureCoach => 'Camera posture coach',
            self::Analytics => 'Progress analytics and reports',
            self::ProgressPhotos => 'Progress photos',
            self::AiCoach => 'AI coach',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Workouts => 'Plan pages, starting and logging workouts.',
            self::ProgramsPremium => 'Programs an admin marks as premium.',
            self::LibraryPremium => 'Exercises an admin marks as premium (instructions and demo media).',
            self::Nutrition => 'Diet targets, meal plans, food search and the food log.',
            self::PostureCoach => 'Live camera form feedback and rep counting.',
            self::Analytics => 'Strength curves, summaries and weekly reports.',
            self::ProgressPhotos => 'Private progress photo uploads.',
            self::AiCoach => 'The optional AI coach (also needs the server feature switch).',
        };
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_map(fn (self $e) => $e->value, self::cases());
    }

    /** Numeric limits a plan may set (null / missing = unlimited). */
    public const LIMITS = [
        'custom_foods' => 'Custom foods a member can create',
        'progress_photos' => 'Progress photos a member can keep',
    ];
}
