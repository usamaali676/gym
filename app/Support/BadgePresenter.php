<?php

namespace App\Support;

use App\Models\Badge;

final class BadgePresenter
{
    /** @param  iterable<Badge>  $badges @return list<array{slug:string,name:string,icon:string,tier:string,xp_reward:int}> */
    public static function many(iterable $badges): array
    {
        $out = [];
        foreach ($badges as $b) {
            $out[] = ['slug' => $b->slug, 'name' => $b->name, 'icon' => $b->icon, 'tier' => $b->tier, 'xp_reward' => $b->xp_reward];
        }

        return $out;
    }
}
