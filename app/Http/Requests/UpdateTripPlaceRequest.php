<?php

namespace App\Http\Requests;

use App\Models\TripPlace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTripPlaceRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     * Visits that go past midnight (23:00–01:00) are not supported: the end must be later than the start.
     * Time can be locked only when it is set: the calculation keeps a locked start time as it is.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'start_time' => ['nullable', 'date_format:H:i', 'required_with:end_time', 'required_if_accepted:time_locked'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'note' => ['nullable', 'string', 'max:500'],
            // Пусто — время на месте по категории. Больше 12 часов в одном месте за день не бывает.
            'stay_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
            // Пусто — способ выбирает расчёт.
            'travel_mode' => ['nullable', Rule::in(TripPlace::TravelModes)],
            'time_locked' => ['boolean'],
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
            'stay_minutes' => 'время на месте',
            'travel_mode' => 'способ',
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
            'start_time.required_if_accepted' => 'Чтобы закрепить время, укажите начало.',
        ];
    }
}
