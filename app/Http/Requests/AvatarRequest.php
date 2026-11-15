<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AvatarRequest extends FormRequest
{
    /**
     * Наибольший размер аватара в килобайтах (2 МБ).
     */
    public const MaxKilobytes = 2048;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MaxKilobytes],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'avatar' => 'аватар',
        ];
    }

    /**
     * Сообщений для этих правил нет в lang/ru/validation.php, поэтому они здесь.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avatar.image' => 'Аватар должен быть изображением.',
            'avatar.mimes' => 'Аватар должен быть в формате JPG, PNG или WEBP.',
            'avatar.max' => 'Аватар должен быть не больше 2 МБ.',
            'avatar.uploaded' => 'Не удалось загрузить файл. Возможно, он слишком большой.',
        ];
    }
}
