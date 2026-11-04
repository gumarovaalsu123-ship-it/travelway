<?php

namespace App\Http\Requests;

class StoreMapPlaceRequest extends StoreTripPlaceRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'integer', 'exists:place_categories,id'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'destination' => ['required', 'in:trip,favorite'],
            'trip_day_id' => ['exclude_unless:destination,trip', 'required', 'integer', $this->ownTripDayRule()],
            'start_time' => ['exclude_unless:destination,trip', 'nullable', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'name' => 'название',
            'category_id' => 'категория',
            'address' => 'адрес',
        ];
    }
}
