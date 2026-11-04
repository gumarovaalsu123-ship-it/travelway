<?php

namespace App\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class StoreTripPlaceRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'trip_day_id' => ['required', 'integer', $this->ownTripDayRule()],
            'start_time' => ['nullable', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'trip_day_id' => 'день поездки',
            'start_time' => 'время',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'trip_day_id.required' => 'Выберите путешествие и день.',
        ];
    }

    /**
     * Only days of the current user's trips may be chosen.
     */
    protected function ownTripDayRule(): Exists
    {
        return Rule::exists('trip_days', 'id')->where(
            fn (Builder $query) => $query->whereIn('trip_id', $this->user()->trips()->select('id'))
        );
    }
}
