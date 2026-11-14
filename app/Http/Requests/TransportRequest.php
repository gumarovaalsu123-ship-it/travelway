<?php

namespace App\Http\Requests;

use App\Models\Transport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransportRequest extends FormRequest
{
    /**
     * Для своего авто билет не покупают, поэтому отметку «Билет куплен» не храним.
     */
    protected function prepareForValidation(): void
    {
        if (in_array($this->input('type'), Transport::TypesWithoutTicket, true)) {
            $this->merge(['is_booked' => false]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(Transport::Types)],
            'direction' => ['required', Rule::in(Transport::Directions)],
            'departure_place' => ['required', 'string', 'max:150'],
            'arrival_place' => ['required', 'string', 'max:150'],
            'departure_at' => ['nullable', 'date'],
            // Сравниваем с отправлением, только если оно указано: оба времени необязательны.
            'arrival_at' => ['nullable', 'date', Rule::when($this->filled('departure_at'), ['after_or_equal:departure_at'])],
            'booking_info' => ['nullable', 'string', 'max:1000'],
            'is_booked' => ['boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => 'вид транспорта',
            'direction' => 'направление',
            'departure_place' => 'место отправления',
            'arrival_place' => 'место прибытия',
            'departure_at' => 'время отправления',
            'arrival_at' => 'время прибытия',
            'booking_info' => 'номер брони или билета',
            'is_booked' => 'билет куплен',
            'note' => 'заметка',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.in' => 'Выберите вид транспорта из списка.',
            'direction.required' => 'Укажите направление: туда, обратно или дальше.',
            'direction.in' => 'Направление может быть только «Туда», «Обратно» или «Дальше».',
            'arrival_at.after_or_equal' => 'Прибытие не может быть раньше отправления.',
        ];
    }
}
