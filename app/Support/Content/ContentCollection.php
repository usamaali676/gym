<?php

namespace App\Support\Content;

/** The collections the mobile apps cache and sync incrementally. */
enum ContentCollection: string
{
    case Programs = 'programs';
    case Exercises = 'exercises';
    case Foods = 'foods';
    case Badges = 'badges';
    case MembershipPlans = 'membership_plans';
    case Announcements = 'announcements';
    case Banners = 'banners';
    case Help = 'help';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
