<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $today = CarbonImmutable::now($this->user()?->timezone() ?? 'UTC')->startOfDay();

        return [
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240', 'dimensions:min_width=200,min_height=200,max_width=12000,max_height=12000'],
            'pose' => ['required', Rule::in(['front', 'side', 'back'])],
            'taken_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString(), 'after_or_equal:'.$today->subYears(5)->toDateString()],
            'notes' => ['nullable', 'string', 'max:200'],
        ];
    }
}
