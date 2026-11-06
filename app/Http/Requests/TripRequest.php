<?php

namespace App\Http\Requests;

use App\Models\Trip;
use Carbon\Carbon;
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
            'end_date' => ['nullable', 'required_with:start_date', 'date', 'after_or_equal:start_date'],
            'days_count' => [Rule::excludeIf($hasDates), 'required', 'integer', 'min:1', 'max:'.Trip::MaxDays],
            'is_private' => ['boolean'],
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
     * Attributes for Trip::fill(): dates or a number of days, and public access.
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
            'is_public' => ! $this->boolean('is_private'),
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
            'end_date.after_or_equal' => 'Дата окончания не может быть раньше даты начала.',
            'days_count.required' => 'Укажите даты поездки или количество дней.',
        ];
    }
}
