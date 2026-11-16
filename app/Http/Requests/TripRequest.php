<?php

namespace App\Http\Requests;

use App\Models\Trip;
use Carbon\Carbon;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TripRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $hasDates = $this->filled('start_date') || $this->filled('end_date');

        return [
            'title' => ['required', 'string', 'min:3', 'max:120'],
            'destination' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'start_date' => ['nullable', 'required_with:end_date', 'date'],
            // Новую поездку, которая уже закончилась (последний день — вчера или раньше), создать нельзя.
            // При редактировании правило не действует: в завершённой поездке можно исправить ошибочные даты.
            'end_date' => [
                'bail',
                'nullable',
                'required_with:start_date',
                'date',
                'after_or_equal:start_date',
                Rule::when($this->isMethod('post'), [
                    function (string $attribute, mixed $value, Closure $fail): void {
                        if (Carbon::parse($value)->lt(today())) {
                            $fail('Эта поездка уже закончилась — нельзя создать путешествие в прошлом.');
                        }
                    },
                ]),
            ],
            'days_count' => [Rule::excludeIf($hasDates), 'required', 'integer', 'min:1', 'max:'.Trip::MaxDays],
            // Предыдущий город цепочки (только при создании, форма редактирования его не шлёт):
            // своя поездка, у которой ещё нет следующей.
            'previous_trip_id' => [
                'nullable',
                'integer',
                Rule::exists('trips', 'id')->where('user_id', $this->user()->id),
                Rule::unique('trips', 'previous_trip_id'),
            ],
        ];
    }

    /**
     * A trip with dates cannot be longer than Trip::MaxDays either.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['start_date', 'end_date']) || ! $this->filled('start_date')) {
                    return;
                }

                $days = Carbon::parse($this->input('start_date'))->diffInDays(Carbon::parse($this->input('end_date'))) + 1;

                if ($days > Trip::MaxDays) {
                    $validator->errors()->add('end_date', 'Поездка не может быть длиннее '.Trip::MaxDays.' дней.');
                }
            },
        ];
    }

    /**
     * Attributes for Trip::fill(): dates or a number of days.
     * Доступ по ссылке форма не меняет — для него свои кнопки (TripShareController).
     *
     * @return array<string, mixed>
     */
    public function tripAttributes(): array
    {
        $hasDates = $this->filled('start_date');

        return [
            ...$this->safe()->only(['title', 'destination', 'description']),
            'start_date' => $hasDates ? $this->validated('start_date') : null,
            'end_date' => $hasDates ? $this->validated('end_date') : null,
            'days_count' => $hasDates ? 1 : (int) $this->validated('days_count'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'название путешествия',
            'destination' => 'город или направление',
            'description' => 'описание',
            'start_date' => 'дата начала',
            'end_date' => 'дата окончания',
            'days_count' => 'количество дней',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.min' => 'Название должно быть не менее 3 символов.',
            'previous_trip_id.exists' => 'Предыдущая поездка не найдена.',
            'previous_trip_id.unique' => 'У предыдущей поездки уже есть следующий город.',
            'end_date.after_or_equal' => 'Дата окончания не может быть раньше даты начала.',
            'days_count.required' => 'Укажите даты поездки или количество дней.',
        ];
    }
}
