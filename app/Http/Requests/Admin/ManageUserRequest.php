<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Admin action on a user: block or delete. Who can open the admin panel is checked by the 'admin' middleware,
 * here — защита от опасных действий: нельзя трогать самого себя и последнего администратора.
 */
class ManageUserRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Extra checks after the rules. Ошибка попадает в errors.user и показывается над таблицей.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $target = $this->targetUser();

                // Сначала «последний админ»: если админ один и пытается понизить себя, причина именно в этом.
                if ($target->isLastAdmin()) {
                    $validator->errors()->add('user', 'Это единственный администратор — без него в админ-панель некому будет войти.');
                } elseif ($target->is($this->user())) {
                    $validator->errors()->add('user', 'Нельзя заблокировать, понизить или удалить самого себя.');
                }
            },
        ];
    }

    /**
     * The user from the address /admin/users/{user}.
     */
    public function targetUser(): User
    {
        return $this->route('user');
    }
}
