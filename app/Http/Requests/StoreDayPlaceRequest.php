<?php

namespace App\Http\Requests;

use App\Models\TripDay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A place found by an Overpass hint ("Пора пообедать?", "Что по пути?") that is added to the day plan.
 * The data comes from the browser (OpenStreetMap), so the server checks it like any other form.
 */
class StoreDayPlaceRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var TripDay $day */
        $day = $this->route('day');

        return [
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'integer', 'exists:place_categories,id'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            // После какого места дня вставить новое. Пусто — в конец дня.
            // Только место этого же дня, а не любое из базы.
            'after_trip_place_id' => ['nullable', 'integer', Rule::exists('trip_places', 'id')->where('trip_day_id', $day->id)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'название',
            'category_id' => 'категория',
            'address' => 'адрес',
            'latitude' => 'широта',
            'longitude' => 'долгота',
            'after_trip_place_id' => 'место в плане',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'after_trip_place_id.exists' => 'Это место уже не стоит в плане дня. Обновите страницу.',
        ];
    }
}
