<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTripPlaceRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     * Visits that go past midnight (23:00–01:00) are not supported: the end must be later than the start.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'start_time' => ['nullable', 'date_format:H:i', 'required_with:end_time'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'start_time' => 'начало',
            'end_time' => 'окончание',
            'note' => 'заметка',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'start_time.required_with' => 'Укажите начало, если указано окончание.',
            'end_time.after' => 'Окончание должно быть позже начала.',
        ];
    }
}
