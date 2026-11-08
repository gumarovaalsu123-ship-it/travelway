<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PackingListRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => [
                'required', 'string', 'max:60',
                // Две группы с одинаковым названием в одной поездке только запутают.
                Rule::unique('packing_lists', 'title')
                    ->where('trip_id', $this->route('trip')->id)
                    ->ignore($this->route('packingList')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'название группы',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.unique' => 'Группа с таким названием уже есть.',
        ];
    }
}
