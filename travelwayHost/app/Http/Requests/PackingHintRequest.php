<?php

namespace App\Http\Requests;

use App\Models\PackingList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PackingHintRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // Добавить можно только вещи из выбранной подсказки, а не произвольный текст.
        $hintItems = PackingList::Hints[$this->input('rule')]['items'] ?? [];

        return [
            'rule' => ['required', 'string', Rule::in(array_keys(PackingList::Hints))],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['string', 'distinct', Rule::in($hintItems)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'rule' => 'подсказка',
            'items' => 'вещи',
            'items.*' => 'вещь',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Отметьте хотя бы одну вещь.',
        ];
    }
}
