<?php

namespace App\Http\Requests;

use App\Models\Trip;
use App\Models\TripPlace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeTripPlaceDayRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Trip $trip */
        $trip = $this->route('trip');
        /** @var TripPlace $tripPlace */
        $tripPlace = $this->route('tripPlace');

        return [
            'trip_day_id' => [
                'required',
                'integer',
                // Только день этой же поездки, а не любой день из базы.
                Rule::exists('trip_days', 'id')->where('trip_id', $trip->id),
                // Перенос в тот же день не имеет смысла.
                Rule::notIn([$tripPlace->trip_day_id]),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'trip_day_id' => 'день поездки',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'trip_day_id.required' => 'Выберите день, в который перенести место.',
            'trip_day_id.not_in' => 'Место уже стоит в этом дне.',
        ];
    }
}
