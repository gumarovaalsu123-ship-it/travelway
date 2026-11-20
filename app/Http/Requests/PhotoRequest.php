<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A photo the user uploads: the trip cover (TripCoverController) or a place photo (TripPlanController).
 * Who may change what is checked in the controller (Gate), here only the file itself.
 */
class PhotoRequest extends FormRequest
{
    /**
     * Наибольший размер фото в килобайтах (5 МБ): фото с телефона обычно 2–4 МБ.
     */
    public const MaxKilobytes = 5120;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MaxKilobytes],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'photo' => 'фото',
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
            'photo.image' => 'Нужно выбрать изображение.',
            'photo.mimes' => 'Фото должно быть в формате JPG, PNG или WEBP.',
            'photo.max' => 'Фото должно быть не больше 5 МБ.',
            'photo.uploaded' => 'Не удалось загрузить файл. Возможно, он слишком большой.',
        ];
    }
}
