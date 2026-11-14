<?php

namespace App\Http\Requests;

use App\Models\Transport;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Запись о транспорте. Кроме обычных проверок, даты дороги сверяются с датами поездки (см. withinTripDates).
 * Номер брони/билета (booking_info) форма больше не присылает: поля нет в правилах, поэтому оно и не сохраняется.
 */
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
     * Менять записи может только хозяин поездки. Проверяем здесь, до валидации:
     * иначе чужой человек увидел бы в тексте ошибки даты чужой поездки.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('trip'));
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
            // bail: если дата не распознана, дальше её с поездкой не сравниваем.
            'departure_at' => ['bail', 'nullable', 'date', $this->withinTripDates()],
            // Сравниваем с отправлением, только если оно указано: оба времени необязательны.
            'arrival_at' => ['bail', 'nullable', 'date', Rule::when($this->filled('departure_at'), ['after_or_equal:departure_at']), $this->withinTripDates()],
            'is_booked' => ['boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Дорога должна быть рядом с поездкой: «Туда» — около начала, «Обратно» и «Дальше» — около конца,
     * допуск ±1 день (Transport::allowedDates). У черновика без дат проверять не с чем.
     */
    protected function withinTripDates(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            /** @var Trip $trip */
            $trip = $this->route('trip');
            $direction = $this->input('direction');
            $allowed = Transport::allowedDates($trip, $direction);

            if ($allowed === null) {
                return;
            }

            [$first, $last] = $allowed;
            // Даты «2026-06-15» можно сравнивать как строки; время внутри дня не важно.
            $date = CarbonImmutable::parse($value)->toDateString();

            if ($date >= $first->toDateString() && $date <= $last->toDateString()) {
                return;
            }

            $road = ['to' => 'Дорога туда должна', 'back' => 'Дорога обратно должна', 'onward' => 'Переезд дальше должен'][$direction];
            $tripDate = $direction === 'to'
                ? 'поездка начинается '.$this->dayMonth($trip->start_date)
                : 'поездка заканчивается '.$this->dayMonth($trip->end_date);

            $fail("{$road} быть с {$this->dayMonth($first)} по {$this->dayMonth($last)}: {$tripDate}, допускается день до или после.");
        };
    }

    /**
     * «15 июня»
     */
    protected function dayMonth(CarbonInterface $date): string
    {
        return $date->locale('ru')->isoFormat('D MMMM');
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
