<?php

namespace App\Http\Requests;

use App\Enums\DietPreference;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A food the user adds to their own list (nutrition per 100 g, optional serving sizes). */
class StoreFoodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'brand' => ['nullable', 'string', 'max:80'],
            'diet_type' => ['required', Rule::in(DietPreference::values())],
            'kcal_100g' => ['required', 'numeric', 'min:0', 'max:900'],
            'protein_100g' => ['required', 'numeric', 'min:0', 'max:100'],
            'carbs_100g' => ['required', 'numeric', 'min:0', 'max:100'],
            'fat_100g' => ['required', 'numeric', 'min:0', 'max:100'],
            'fibre_100g' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'servings' => ['nullable', 'array', 'max:5'],
            'servings.*.label' => ['required', 'string', 'max:60'],
            'servings.*.grams' => ['required', 'numeric', 'min:1', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $d = $this->all();
            $p = (float) ($d['protein_100g'] ?? 0);
            $c = (float) ($d['carbs_100g'] ?? 0);
            $f = (float) ($d['fat_100g'] ?? 0);

            if ($p + $c + $f > 100.5) {
                $v->errors()->add('protein_100g', 'Protein, carbs and fat together cannot be more than 100 g in 100 g of food.');
            }
            if ((float) ($d['fibre_100g'] ?? 0) > $c + 0.5) {
                $v->errors()->add('fibre_100g', 'Fibre is part of carbohydrate, so it cannot be more than the carbs.');
            }
            // Catch a swapped column or a missing digit: energy should be close to 4 / 4 / 9 kcal per gram.
            $estimate = 4 * $p + 4 * $c + 9 * $f;
            $kcal = (float) ($d['kcal_100g'] ?? 0);
            if ($estimate > 20 && ($kcal < $estimate * 0.7 - 10 || $kcal > $estimate * 1.3 + 10)) {
                $v->errors()->add('kcal_100g', 'Calories do not match the macros (about '.round($estimate).' kcal expected from protein, carbs and fat). Check the numbers on the label.');
            }
        });
    }
}
