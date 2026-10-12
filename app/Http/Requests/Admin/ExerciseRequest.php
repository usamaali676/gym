<?php

namespace App\Http\Requests\Admin;

use App\Enums\Difficulty;
use App\Enums\ExerciseKind;
use App\Enums\Mechanic;
use App\Enums\MuscleGroup;
use App\Support\Entitlement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ExerciseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => Str::slug((string) ($this->input('slug') ?: $this->input('name'))),
            'is_timed' => $this->boolean('is_timed'),
            'per_side' => $this->boolean('per_side'),
            'is_active' => $this->boolean('is_active'),
            // Older callers send only the "visible" checkbox; map it onto the workflow status.
            'status' => $this->input('status') ?: ($this->boolean('is_active') ? 'published' : ($this->route('exercise') ? 'archived' : 'draft')),
            'required_entitlement' => $this->input('required_entitlement') ?: null,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $current = $this->route('exercise');

        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('exercises', 'slug')->ignore($current?->id)],
            'kind' => ['required', Rule::in(ExerciseKind::values())],
            'muscle_group' => ['required', Rule::in(MuscleGroup::values())],
            'secondary_groups' => ['nullable', 'array'],
            'secondary_groups.*' => [Rule::in(MuscleGroup::values())],
            'mechanic' => ['required', Rule::in(Mechanic::values())],
            'equipment' => ['nullable', 'array'],
            'equipment.*' => ['string', 'max:40'],
            'difficulty' => ['required', Rule::in(Difficulty::values())],
            'is_timed' => ['boolean'],
            'per_side' => ['boolean'],
            'mobility_type' => ['nullable', Rule::in(['dynamic', 'static'])],
            'default_hold_seconds' => ['nullable', 'integer', 'between:5,300'],
            'rest_min_seconds' => ['required', 'integer', 'between:0,600'],
            'rest_max_seconds' => ['required', 'integer', 'between:0,600', 'gte:rest_min_seconds'],
            'default_rest_seconds' => ['required', 'integer', 'between:0,600'],
            'demo_key' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/'],
            'description' => ['nullable', 'string', 'max:2000'],
            'form_cues' => ['nullable', 'string', 'max:2000'],
            'common_mistakes' => ['nullable', 'string', 'max:2000'],
            'breathing' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
            'required_entitlement' => ['nullable', Rule::in([Entitlement::LibraryPremium->value])],
        ];
    }

    /** One cue per line in the form, a list in the database. @return array<string, mixed> */
    public function exerciseData(): array
    {
        $data = $this->validated();
        $lines = fn (?string $text) => collect(preg_split('/\R/', (string) $text))->map(fn ($l) => trim($l))->filter()->values()->all();

        $data['form_cues'] = $lines($data['form_cues'] ?? null) ?: null;
        $data['common_mistakes'] = $lines($data['common_mistakes'] ?? null) ?: null;
        $data['secondary_groups'] = array_values(array_diff($data['secondary_groups'] ?? [], [$data['muscle_group']])) ?: null;
        $data['equipment'] = array_values($data['equipment'] ?? []) ?: null;
        $data['is_active'] = $data['status'] === 'published';
        if ($data['kind'] !== 'mobility') {
            $data['mobility_type'] = null;
        }

        return $data;
    }
}
