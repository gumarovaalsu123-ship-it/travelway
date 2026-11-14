<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTripDayRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     * An empty title arrives as null (Laravel turns empty strings into null) — then the day is just "День N".
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'название дня',
        ];
    }
}
