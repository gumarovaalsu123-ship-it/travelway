<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CalculateDayScheduleRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     * The start of the day is saved together with the calculation; empty means the default 10:00.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'start_time' => ['nullable', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'start_time' => 'начало дня',
        ];
    }
}
