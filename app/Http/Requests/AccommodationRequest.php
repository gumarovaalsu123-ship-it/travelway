<?php

namespace App\Http\Requests;

use App\Models\Accommodation;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccommodationRequest extends FormRequest
{
    /**
     * У знакомых бронировать нечего, поэтому отметку «Забронировано» не храним.
     */
    protected function prepareForValidation(): void
    {
        if (in_array($this->input('type'), Accommodation::TypesWithoutBooking, true)) {
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
            'type' => ['required', Rule::in(Accommodation::Types)],
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            // bail: если дата не распознана, дальше её с поездкой не сравниваем.
            'check_in' => ['bail', 'nullable', 'date', $this->withinTripDates('checkIn')],
            // Сравниваем с заездом, только если он указан: иначе сравнивать не с чем.
            'check_out' => ['bail', 'nullable', 'date', ...($this->filled('check_in') ? ['after:check_in'] : []), $this->withinTripDates('checkOut')],
            'booking_info' => ['nullable', 'string', 'max:2000'],
            'is_booked' => ['boolean'],
            // Координаты приходят из поиска адреса и имеют смысл только парой.
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Жильё должно быть в пределах поездки с допуском в один день (Accommodation::allowedDates):
     * заезд — не раньше чем за день до начала и не позже последнего дня,
     * выезд — не раньше первого дня и не позже чем через день после конца.
     *
     * @param  'checkIn'|'checkOut'  $field
     */
    protected function withinTripDates(string $field): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($field): void {
            /** @var Trip $trip */
            $trip = $this->route('trip');
            $allowed = Accommodation::allowedDates($trip);

            if ($allowed === null) {
                return;
            }

            [$first, $last] = $allowed[$field];
            // Даты «2026-06-15» можно сравнивать как строки; часы не важны.
            $date = CarbonImmutable::parse($value)->toDateString();

            if ($date >= $first->toDateString() && $date <= $last->toDateString()) {
                return;
            }

            $label = $field === 'checkIn' ? 'Заезд' : 'Выезд';
            $tripDates = "поездка с {$this->dayMonth($trip->start_date)} по {$this->dayMonth($trip->end_date)}";

            $fail("{$label} должен быть с {$this->dayMonth($first)} по {$this->dayMonth($last)}: {$tripDates}.");
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
            'type' => 'тип жилья',
            'name' => 'название',
            'address' => 'адрес',
            'check_in' => 'заезд',
            'check_out' => 'выезд',
            'booking_info' => 'данные брони',
            'is_booked' => 'забронировано',
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
            'type.required' => 'Выберите тип жилья.',
            'type.in' => 'Выберите тип жилья из списка.',
            'check_out.after' => 'Выезд должен быть позже заезда.',
        ];
    }
}
