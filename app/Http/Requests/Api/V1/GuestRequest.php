<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesDevice;
use Illuminate\Foundation\Http\FormRequest;

class GuestRequest extends FormRequest
{
    use ValidatesDevice;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->deviceRules();
    }
}
