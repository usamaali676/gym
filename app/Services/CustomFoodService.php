<?php

namespace App\Services;

use App\Models\Food;
use App\Models\User;
use App\Services\Membership\EntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A food a member adds for themselves (private to them). Shared by the website and the mobile API. */
class CustomFoodService
{
    /** Keeps the shared list and the database tidy, whatever the plan says. */
    public const MAX_PER_USER = 300;

    public function __construct(private readonly EntitlementService $entitlements) {}

    /** @param  array<string, mixed>  $data  validated StoreFoodRequest data */
    public function create(User $user, array $data): Food
    {
        $count = Food::where('user_id', $user->id)->count();
        if ($count >= self::MAX_PER_USER) {
            throw ValidationException::withMessages(['name' => 'You have reached the limit of '.self::MAX_PER_USER.' custom foods.']);
        }
        $this->entitlements->assertWithinLimit($user, 'custom_foods', $count, 'custom foods');

        return DB::transaction(function () use ($user, $data) {
            $food = Food::create([
                'user_id' => $user->id,
                'name' => trim($data['name']),
                'name_search' => Food::normalise($data['name']),
                'brand' => $data['brand'] ?? null,
                'category' => 'custom',
                'diet_type' => $data['diet_type'],
                'kcal_100g' => $data['kcal_100g'],
                'protein_100g' => $data['protein_100g'],
                'carbs_100g' => $data['carbs_100g'],
                'fat_100g' => $data['fat_100g'],
                'fibre_100g' => $data['fibre_100g'] ?? 0,
                'is_public' => false,
                'is_verified' => false,
            ]);
            foreach ($data['servings'] ?? [] as $i => $serving) {
                $food->servings()->create(['label' => $serving['label'], 'grams' => $serving['grams'], 'is_default' => $i === 0]);
            }

            return $food->load('servings');
        });
    }
}
