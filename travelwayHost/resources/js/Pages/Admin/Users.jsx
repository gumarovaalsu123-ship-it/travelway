import React, { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import AdminLayout from '../../Components/AdminLayout';
import { plural } from '../../utils/format';

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

    const remove = () => {
        if (window.confirm(`Удалить пользователя ${user.name}? Его поездки удалятся вместе с ним. Отменить это нельзя.`)) {
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
            <td>
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
            <td>{formatDate(user.registeredAt)}</td>
            <td>{user.tripsCount}</td>
            <td>
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

/**
 * Переключатель страниц. users — то, что отдаёт Laravel-пагинатор: current_page, last_page,
 * prev_page_url, next_page_url. Адреса уже содержат ?search=..., потому что на сервере withQueryString().
 */
function Pagination({ users }) {
    if (users.last_page <= 1) {
        return null;
    }

    return (
        <nav className="admin-pagination">
            {users.prev_page_url
                ? <Link href={users.prev_page_url} className="admin-action" preserveScroll>← Назад</Link>
                : <span className="admin-action admin-action--disabled">← Назад</span>}
            <span className="admin-pagination__info">Страница {users.current_page} из {users.last_page}</span>
            {users.next_page_url
                ? <Link href={users.next_page_url} className="admin-action" preserveScroll>Вперёд →</Link>
                : <span className="admin-action admin-action--disabled">Вперёд →</span>}
        </nav>
    );
}

export default function AdminUsers({ users, search }) {
    const { errors } = usePage().props;
    const [query, setQuery] = useState(search);

    const submitSearch = (event) => {
        event.preventDefault();
        // Новый поиск — снова с первой страницы. replace: не засоряем историю браузера каждым поиском.
        router.get('/admin/users', query.trim() ? { search: query.trim() } : {}, { preserveState: true, replace: true });
    };

    const searchForm = (
        <form className="admin-search-wrapper" onSubmit={submitSearch} role="search">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <circle cx="11" cy="11" r="8" />
                <line x1="21" y1="21" x2="16.65" y2="16.65" />
            </svg>
            <input
                type="search"
                className="admin-search-input"
                placeholder="Имя или почта, Enter — найти"
                value={query}
                onChange={(event) => setQuery(event.target.value)}
            />
        </form>
    );

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

            <Pagination users={users} />
        </AdminLayout>
    );
}
