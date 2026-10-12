<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreMeasurementRequest extends FormRequest
{
    public const SITES = ['neck', 'shoulders', 'chest', 'waist', 'hips', 'bicep_left', 'bicep_right', 'thigh', 'calf'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $today = CarbonImmutable::now($this->user()?->timezone() ?? 'UTC')->startOfDay();
        $rules = [
            'measured_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString(), 'after_or_equal:'.$today->subYears(3)->toDateString()],
            'weight_kg' => ['nullable', 'numeric', 'between:30,300'],
            'body_fat_pct' => ['nullable', 'numeric', 'between:2,60'],
            'notes' => ['nullable', 'string', 'max:200'],
        ];
        foreach (self::SITES as $site) {
            $rules["{$site}_cm"] = ['nullable', 'numeric', 'between:5,250'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $fields = array_merge(['weight_kg', 'body_fat_pct'], array_map(fn ($s) => "{$s}_cm", self::SITES));
            if (collect($fields)->every(fn ($f) => $this->input($f) === null || $this->input($f) === '')) {
                $v->errors()->add('weight_kg', 'Enter at least one measurement.');
            }
        });
    }

    /** @return array<string, mixed> */
    public function measurementData(): array
    {
        $keys = array_merge(['weight_kg', 'body_fat_pct', 'notes'], array_map(fn ($s) => "{$s}_cm", self::SITES));

        return collect($keys)->mapWithKeys(fn ($k) => [$k => ($this->input($k) === '' ? null : $this->input($k))])->all();
    }
}
