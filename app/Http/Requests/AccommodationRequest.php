<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AccommodationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'check_in' => ['nullable', 'date'],
            // Сравниваем с заездом, только если он указан: иначе сравнивать не с чем.
            'check_out' => ['nullable', 'date', ...($this->filled('check_in') ? ['after_or_equal:check_in'] : [])],
            'booking_info' => ['nullable', 'string', 'max:2000'],
            // Координаты приходят из поиска адреса и имеют смысл только парой.
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'название',
            'address' => 'адрес',
            'check_in' => 'заезд',
            'check_out' => 'выезд',
            'booking_info' => 'данные брони',
            'latitude' => 'широта',
            'longitude' => 'долгота',
            'note' => 'заметка',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'check_out.after_or_equal' => 'Выезд не может быть раньше заезда.',
        ];
    }
}
