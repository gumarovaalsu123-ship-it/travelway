<?php

namespace App\Http\Requests;

class StoreMapPlaceRequest extends StoreTripPlaceRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Фото, описание, часы, сайт и телефон подставляет браузер из OpenStreetMap и Википедии,
     * поэтому сервер им не доверяет: ссылки допускаются только на ожидаемые домены и схемы.
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
            'image' => ['nullable', 'string', 'max:2000', 'url:https', 'starts_with:https://upload.wikimedia.org/'],
            'description' => ['nullable', 'string', 'max:2000'],
            'opening_hours' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'phone' => ['nullable', 'string', 'max:50'],
            'wiki_url' => ['nullable', 'string', 'max:500', 'url:https', 'regex:#^https://[a-z\-]+\.wikipedia\.org/#i'],
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
            'image' => 'фото',
            'description' => 'описание',
            'opening_hours' => 'часы работы',
            'website' => 'сайт',
            'phone' => 'телефон',
            'wiki_url' => 'ссылка на Википедию',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'image.url' => 'Фото должно быть ссылкой на upload.wikimedia.org.',
            'image.starts_with' => 'Фото должно быть ссылкой на upload.wikimedia.org.',
            'website.url' => 'Сайт должен быть ссылкой, начинающейся с http:// или https://.',
            'wiki_url.url' => 'Ссылка должна вести на статью Википедии.',
            'wiki_url.regex' => 'Ссылка должна вести на статью Википедии.',
        ];
    }
}
