import React from 'react';
import { router, usePage } from '@inertiajs/react';
import AdminLayout from '../../Components/AdminLayout';
import AdminPagination from '../../Components/AdminPagination';
import AdminSearch from '../../Components/AdminSearch';
import { plural } from '../../utils/format';
import { confirmDialog } from '../../utils/confirm';

const ROLE_USER = 1;
const ROLE_ADMIN = 2;

// Все действия — запросы на сервер; preserveScroll — чтобы таблица не прыгала наверх после ответа.
const requestOptions = { preserveScroll: true };

function formatDate(isoDate) {
    return isoDate ? new Date(isoDate).toLocaleDateString('ru-RU') : '—';
}

function UserRow({ user }) {
    const changeRole = (event) => {
        router.patch(`/admin/users/${user.id}/role`, { role_id: Number(event.target.value) }, requestOptions);
    };

    const toggleBlock = () => {
        const action = user.isBlocked ? 'unblock' : 'block';
        router.patch(`/admin/users/${user.id}/${action}`, {}, requestOptions);
    };

    const remove = async () => {
        if (await confirmDialog({ title: `Удалить пользователя ${user.name}?`, message: 'Его поездки удалятся вместе с ним. Отменить это нельзя.', confirmLabel: 'Удалить', danger: true })) {
            router.delete(`/admin/users/${user.id}`, requestOptions);
        }
    };

    return (
        <tr className={user.isBlocked ? 'admin-table__row--blocked' : ''}>
            <td>
                <div className="admin-table__name">
                    {user.name}
                    {user.isCurrent && <span className="badge--light">это вы</span>}
                </div>
                <div className="admin-table__email">{user.email}</div>
            </td>
            <td data-label="Роль">
                {/* Свою роль не меняем: сервер всё равно не даст, поэтому и выбор отключён. */}
                <select
                    className="admin-select"
                    value={user.isAdmin ? ROLE_ADMIN : ROLE_USER}
                    onChange={changeRole}
                    disabled={user.isCurrent}
                    aria-label={`Роль ${user.name}`}
                >
                    <option value={ROLE_USER}>Пользователь</option>
                    <option value={ROLE_ADMIN}>Администратор</option>
                </select>
            </td>
            <td data-label="Регистрация">{formatDate(user.registeredAt)}</td>
            <td data-label="Поездок">{user.tripsCount}</td>
            <td data-label="Статус">
                <span className={`admin-status ${user.isBlocked ? 'admin-status--blocked' : ''}`}>
                    {user.isBlocked ? 'Заблокирован' : 'Активен'}
                </span>
            </td>
            <td>
                {!user.isCurrent && (
                    <div className="admin-table__actions">
                        <button type="button" className="admin-action" onClick={toggleBlock}>
                            {user.isBlocked ? 'Разблокировать' : 'Заблокировать'}
                        </button>
                        <button type="button" className="admin-action admin-action--danger" onClick={remove}>
                            Удалить
                        </button>
                    </div>
                )}
            </td>
        </tr>
    );
}

export default function AdminUsers({ users, search }) {
    const { errors } = usePage().props;

    const searchForm = <AdminSearch url="/admin/users" search={search} placeholder="Имя или почта, Enter — найти" />;

    return (
        <AdminLayout
            title="Пользователи"
            subtitle={`${users.total} ${plural(users.total, ['пользователь', 'пользователя', 'пользователей'])}${search ? ` по запросу «${search}»` : ''}`}
            actions={searchForm}
        >
            {/* Ошибки защиты (нельзя трогать себя и последнего администратора) и неверная роль. */}
            {(errors.user || errors.role_id) && (
                <div className="admin-alert" role="alert">{errors.user ?? errors.role_id}</div>
            )}

            <section className="admin-table-card">
                {users.data.length === 0 ? (
                    <p className="admin-empty">Никого не нашли.</p>
                ) : (
                    <table className="admin-table">
                        <thead>
                            <tr>
                                <th>Пользователь</th>
                                <th>Роль</th>
                                <th>Регистрация</th>
                                <th>Поездок</th>
                                <th>Статус</th>
                                <th aria-label="Действия" />
                            </tr>
                        </thead>
                        <tbody>
                            {users.data.map((user) => <UserRow key={user.id} user={user} />)}
                        </tbody>
                    </table>
                )}
            </section>

            <AdminPagination paginator={users} />
        </AdminLayout>
    );
}
