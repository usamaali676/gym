<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Complete / skip / undo-skip a plan day on a given date (defaults to this week's date). */
class DayActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['date' => ['nullable', 'date_format:Y-m-d']];
    }
}
