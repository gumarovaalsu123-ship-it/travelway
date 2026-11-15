<?php

namespace App\Http\Requests\Admin;

use App\Models\Role;
use Illuminate\Validation\Rule;

/**
 * Change of a user's role. Те же защиты, что у блокировки и удаления (см. ManageUserRequest),
 * плюс сама роль должна быть одной из двух: пользователь или администратор.
 */
class UpdateUserRoleRequest extends ManageUserRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'role_id' => ['required', 'integer', Rule::in([Role::User, Role::Admin])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'role_id' => 'роль',
        ];
    }
}
