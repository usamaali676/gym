<?php

namespace App\Http\Requests\Admin;

use App\Enums\DietPreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FoodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_verified' => $this->boolean('is_verified'),
            'servings' => collect($this->input('servings', []))->filter(fn ($s) => filled($s['label'] ?? null) || filled($s['grams'] ?? null))->values()->all(),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'brand' => ['nullable', 'string', 'max:80'],
            'category' => ['required', 'string', 'max:40'],
            'diet_type' => ['required', Rule::in(array_map(fn ($c) => $c->value, DietPreference::cases()))],
            'kcal_100g' => ['required', 'numeric', 'between:0,900'],
            'protein_100g' => ['required', 'numeric', 'between:0,100'],
            'carbs_100g' => ['required', 'numeric', 'between:0,100'],
            'fat_100g' => ['required', 'numeric', 'between:0,100'],
            'fibre_100g' => ['nullable', 'numeric', 'between:0,100'],
            'is_verified' => ['boolean'],
            'servings' => ['required', 'array', 'min:1', 'max:8'],
            'servings.*.label' => ['required', 'string', 'max:60'],
            'servings.*.grams' => ['required', 'numeric', 'between:1,2000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            // Macros per 100 g cannot add up to more than 100 g of food.
            $sum = (float) $this->input('protein_100g') + (float) $this->input('carbs_100g') + (float) $this->input('fat_100g') + (float) $this->input('fibre_100g');
            if ($sum > 100.5) {
                $v->errors()->add('protein_100g', 'Protein, carbs, fat and fibre per 100 g cannot add up to more than 100 g.');
            }
        });
    }
}
