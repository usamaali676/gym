<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DietPreference;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FoodRequest;
use App\Models\Food;
use App\Support\AdminAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** The shared food database. Foods people add for themselves stay private to them and are not listed here. */
class FoodController extends Controller
{
    public const CATEGORIES = ['meat_fish', 'eggs', 'dairy', 'oils_fats', 'sweets_snacks', 'legumes', 'grains', 'nuts_seeds', 'vegetables', 'fruits', 'beverages', 'supplements'];

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $category = $request->query('category');

        $foods = Food::query()->whereNull('user_id')->with('servings')
            ->when($q !== '', fn ($query) => $query->where('name_search', 'like', '%'.str_replace(['%', '_'], ['', ''], Food::normalise($q)).'%'))
            ->when(in_array($category, self::CATEGORIES, true), fn ($query) => $query->where('category', $category))
            ->orderBy('category')->orderBy('name')
            ->paginate(40)->withQueryString();

        return view('admin.foods.index', ['foods' => $foods, 'q' => $q, 'category' => $category, 'categories' => self::CATEGORIES]);
    }

    public function create(): View
    {
        return view('admin.foods.form', $this->formData(new Food(['diet_type' => 'vegan', 'is_verified' => true, 'fibre_100g' => 0]), [['label' => '100 g', 'grams' => 100]]));
    }

    public function store(FoodRequest $request): RedirectResponse
    {
        $food = $this->save(new Food, $request);
        AdminAudit::record('food.create', ['food_id' => $food->id]);

        return redirect()->route('admin.foods.edit', $food)->with('status', 'Food added.');
    }

    public function edit(Food $food): View
    {
        abort_unless($food->user_id === null, 404);

        return view('admin.foods.form', $this->formData($food->load('servings'), $food->servings->map(fn ($s) => ['label' => $s->label, 'grams' => (float) $s->grams])->all()));
    }

    public function update(FoodRequest $request, Food $food): RedirectResponse
    {
        abort_unless($food->user_id === null, 404);
        $this->save($food, $request);
        AdminAudit::record('food.update', ['food_id' => $food->id]);

        return redirect()->route('admin.foods.edit', $food)->with('status', 'Food saved.');
    }

    public function destroy(Food $food): RedirectResponse
    {
        abort_unless($food->user_id === null, 404);
        AdminAudit::record('food.delete', ['food_id' => $food->id, 'name' => $food->name]);
        $food->delete();   // logged meals keep their own name and nutrition snapshot

        return redirect()->route('admin.foods.index')->with('status', 'Food deleted. Meals that used it keep their saved nutrition.');
    }

    private function save(Food $food, FoodRequest $request): Food
    {
        $data = $request->validated();

        return DB::transaction(function () use ($food, $data) {
            $food->fill([
                'user_id' => null,
                'name' => trim($data['name']),
                'name_search' => Food::normalise($data['name']),
                'brand' => $data['brand'] ?? null,
                'category' => $data['category'],
                'diet_type' => $data['diet_type'],
                'kcal_100g' => $data['kcal_100g'],
                'protein_100g' => $data['protein_100g'],
                'carbs_100g' => $data['carbs_100g'],
                'fat_100g' => $data['fat_100g'],
                'fibre_100g' => $data['fibre_100g'] ?? 0,
                'is_public' => true,
                'is_verified' => (bool) $data['is_verified'],
            ])->save();

            $food->servings()->delete();
            foreach ($data['servings'] as $i => $serving) {
                $food->servings()->create(['label' => $serving['label'], 'grams' => $serving['grams'], 'is_default' => $i === 0]);
            }

            return $food;
        });
    }

    /** @param list<array{label:string,grams:float|int}> $servings @return array<string, mixed> */
    private function formData(Food $food, array $servings): array
    {
        return [
            'food' => $food,
            'servings' => old('servings', $servings),
            'categories' => self::CATEGORIES,
            'diets' => DietPreference::cases(),
        ];
    }
}
