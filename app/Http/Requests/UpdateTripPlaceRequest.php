<?php

namespace App\Http\Requests;

use App\Models\Trip;
use App\Models\TripPlace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTripPlaceRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     * The end of the visit is not asked: it is always start + time on the place (see TripPlanController::update).
     * Time can be locked only when it is set: the calculation keeps a locked start time as it is.
     * trip_day_id moves the place to another day of the same trip in the same "Сохранить".
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Trip $trip */
        $trip = $this->route('trip');

        return [
            'start_time' => ['nullable', 'date_format:H:i', 'required_if_accepted:time_locked'],
            'note' => ['nullable', 'string', 'max:500'],
            // Пусто — время на месте по категории. Больше 12 часов в одном месте за день не бывает.
            'stay_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
            // Пусто — способ выбирает расчёт.
            'travel_mode' => ['nullable', Rule::in(TripPlace::TravelModes)],
            'time_locked' => ['boolean'],
            'needs_booking' => ['sometimes', 'boolean'],
            // Только день этой же поездки, а не любой день из базы. Тот же день — просто без переноса.
            'trip_day_id' => ['sometimes', 'nullable', 'integer', Rule::exists('trip_days', 'id')->where('trip_id', $trip->id)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'start_time' => 'начало',
            'trip_day_id' => 'день поездки',
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
            'start_time.required_if_accepted' => 'Чтобы закрепить время, укажите начало.',
        ];
    }
}
