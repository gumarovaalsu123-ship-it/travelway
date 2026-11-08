import React, { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import TripLayout from '../../Components/TripLayout';
import { plural } from '../../utils/format';

const FILTERS = [
    { value: 'all', label: 'Все вещи' },
    { value: 'left', label: 'Осталось собрать' },
    { value: 'packed', label: 'Собрано' },
];

/**
 * Все запросы на этой странице меняют только список вещей — прокрутку страницы сохраняем.
 */
const keepScroll = { preserveScroll: true };

function CheckIcon() {
    return (
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">
            <polyline points="20 6 9 17 4 12" />
        </svg>
    );
}

/**
 * Поле для переименования: Enter — сохранить, Esc или клик мимо — отменить.
 */
function RenameInput({ form, field, onSave, onCancel, maxLength }) {
    return (
        <input
            className="form-input packing-inline-input"
            value={form.data[field]}
            maxLength={maxLength}
            autoFocus
            onChange={(e) => form.setData(field, e.target.value)}
            onBlur={onCancel}
            onKeyDown={(e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    onSave();
                }
                if (e.key === 'Escape') {
                    onCancel();
                }
            }}
        />
    );
}

function PackingItemRow({ tripId, item }) {
    const [isEditing, setEditing] = useState(false);
    const form = useForm({ name: item.name, note: item.note ?? '' });
    const url = `/my-trips/${tripId}/packing-items/${item.id}`;

    function toggle() {
        // Оптимистичное обновление: галочка меняется сразу, не дожидаясь ответа сервера.
        // Если сервер ответит ошибкой, Inertia сама вернёт прежнее состояние.
        router.optimistic((props) => ({
            lists: props.lists.map((list) => ({
                ...list,
                items: list.items.map((other) => (other.id === item.id ? { ...other, isCompleted: !other.isCompleted } : other)),
            })),
        })).patch(`${url}/toggle`, {}, keepScroll);
    }

    function save() {
        if (form.data.name.trim() === item.name && form.data.note.trim() === (item.note ?? '')) {
            setEditing(false);
            return;
        }

        form.patch(url, { ...keepScroll, onSuccess: () => setEditing(false) });
    }

    function cancel() {
        form.reset();
        form.clearErrors();
        setEditing(false);
    }

    return (
        <li className={`packing-item ${item.isCompleted ? 'packing-item--checked' : ''}`}>
            <label className="custom-checkbox custom-checkbox--packing">
                <input type="checkbox" className="custom-checkbox__input" checked={item.isCompleted} onChange={toggle} />
                <span className="custom-checkbox__box"><CheckIcon /></span>
            </label>

            <div className="packing-item__text">
                {isEditing ? (
                    <>
                        <RenameInput form={form} field="name" onSave={save} onCancel={cancel} maxLength={120} />
                        {form.errors.name && <span className="form-error">{form.errors.name}</span>}
                    </>
                ) : (
                    <>
                        <button type="button" className="packing-item__name packing-rename-btn" title="Нажмите, чтобы переименовать" onClick={() => setEditing(true)}>
                            {item.name}
                        </button>
                        {item.note && <span className="packing-item__note">{item.note}</span>}
                    </>
                )}
            </div>

            <button
                type="button"
                className="packing-delete-btn"
                aria-label={`Удалить «${item.name}»`}
                onClick={() => router.delete(url, keepScroll)}
            >
                ×
            </button>
        </li>
    );
}

/**
 * Быстрое добавление вещи в конец группы. После успешного добавления поле очищается и остаётся в фокусе.
 */
function AddItemForm({ tripId, listId }) {
    const form = useForm({ name: '', note: '' });

    function submit(e) {
        e.preventDefault();
        form.post(`/my-trips/${tripId}/packing-lists/${listId}/items`, { ...keepScroll, onSuccess: () => form.reset() });
    }

    return (
        <form className="packing-add-form" onSubmit={submit}>
            <div className="packing-add-form__row">
                <input
                    className="form-input"
                    placeholder="Добавить вещь…"
                    maxLength={120}
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                />
                <input
                    className="form-input packing-add-form__note"
                    placeholder="Заметка"
                    maxLength={120}
                    value={form.data.note}
                    onChange={(e) => form.setData('note', e.target.value)}
                />
                <button type="submit" className="btn btn--solid btn--sm" disabled={form.processing}>+</button>
            </div>
            {form.errors.name && <span className="form-error">{form.errors.name}</span>}
            {form.errors.note && <span className="form-error">{form.errors.note}</span>}
        </form>
    );
}

function PackingGroup({ tripId, list, visibleItems }) {
    const [isEditing, setEditing] = useState(false);
    const form = useForm({ title: list.title });
    const url = `/my-trips/${tripId}/packing-lists/${list.id}`;
    const packedCount = list.items.filter((item) => item.isCompleted).length;

    function save() {
        if (form.data.title.trim() === list.title) {
            setEditing(false);
            return;
        }

        form.patch(url, { ...keepScroll, onSuccess: () => setEditing(false) });
    }

    function cancel() {
        form.reset();
        form.clearErrors();
        setEditing(false);
    }

    function remove() {
        const question = list.items.length > 0
            ? `Удалить группу «${list.title}» вместе с ${list.items.length} ${plural(list.items.length, ['вещью', 'вещами', 'вещами'])}?`
            : `Удалить группу «${list.title}»?`;

        if (confirm(question)) {
            router.delete(url, keepScroll);
        }
    }

    return (
        <article className="packing-category-card">
            <header className="packing-category__header">
                {isEditing ? (
                    <RenameInput form={form} field="title" onSave={save} onCancel={cancel} maxLength={60} />
                ) : (
                    <button type="button" className="packing-category__title packing-rename-btn" title="Нажмите, чтобы переименовать" onClick={() => setEditing(true)}>
                        {list.title}
                    </button>
                )}
                <div className="packing-category__actions">
                    <span className="packing-category__count">{packedCount}/{list.items.length}</span>
                    <button type="button" className="packing-delete-btn" aria-label={`Удалить группу «${list.title}»`} onClick={remove}>×</button>
                </div>
            </header>
            {form.errors.title && <span className="form-error">{form.errors.title}</span>}

            {visibleItems.length > 0 && (
                <ul className="packing-list mt-24">
                    {visibleItems.map((item) => (
                        // key с названием: после переименования форма строки начнёт с новых значений.
                        <PackingItemRow key={`${item.id}-${item.name}-${item.note}`} tripId={tripId} item={item} />
                    ))}
                </ul>
            )}

            <AddItemForm tripId={tripId} listId={list.id} />
        </article>
    );
}

function AddGroupForm({ tripId, onDone }) {
    const form = useForm({ title: '' });

    function submit(e) {
        e.preventDefault();
        form.post(`/my-trips/${tripId}/packing-lists`, { ...keepScroll, onSuccess: () => onDone() });
    }

    return (
        <form className="packing-progress-card packing-group-form mt-24" onSubmit={submit}>
            <div className="packing-add-form__row">
                <input
                    className="form-input"
                    placeholder="Название группы, например «Пляж»"
                    maxLength={60}
                    autoFocus
                    value={form.data.title}
                    onChange={(e) => form.setData('title', e.target.value)}
                />
                <button type="submit" className="btn btn--solid btn--sm" disabled={form.processing}>Создать</button>
                <button type="button" className="btn btn--outline btn--sm" onClick={onDone}>Отмена</button>
            </div>
            {form.errors.title && <span className="form-error">{form.errors.title}</span>}
        </form>
    );
}

/**
 * Страница поездки, вкладка «Список вещей»: группы вещей с галочками «собрано».
 */
export default function TripPacking({ trip, lists }) {
    const [filter, setFilter] = useState('all');
    const [isAddingGroup, setAddingGroup] = useState(false);

    const allItems = lists.flatMap((list) => list.items);
    const packedCount = allItems.filter((item) => item.isCompleted).length;
    const totalCount = allItems.length;
    const percent = totalCount > 0 ? Math.round((packedCount / totalCount) * 100) : 0;

    const counts = { all: totalCount, left: totalCount - packedCount, packed: packedCount };
    const matchesFilter = (item) => filter === 'all' || item.isCompleted === (filter === 'packed');

    function addDefaults() {
        router.post(`/my-trips/${trip.id}/packing-lists/defaults`, {}, keepScroll);
    }

    return (
        <TripLayout trip={trip} activeTab="packing">
            <main className="container trip-packing-main mt-32 mb-40">
                <header className="section-header section-header--spaced">
                    <div className="section-header__text">
                        <h2 className="section-title">Список вещей</h2>
                        <p className="section-subtitle section-subtitle--light">
                            Отмечайте, что уже лежит в чемодане. Название вещи или группы можно изменить, нажав на него.
                        </p>
                    </div>
                    <div className="packing-header-actions">
                        {lists.length > 0 && (
                            <button type="button" className="btn btn--outline-dark" onClick={addDefaults}>Стандартный набор</button>
                        )}
                        <button type="button" className="btn btn--solid-dark" onClick={() => setAddingGroup(true)}>+ Новая группа</button>
                    </div>
                </header>

                {isAddingGroup && <AddGroupForm tripId={trip.id} onDone={() => setAddingGroup(false)} />}

                {lists.length === 0 ? (
                    <section className="trips-empty mt-24">
                        <h2 className="trips-empty__title">Список пока пуст</h2>
                        <p className="trips-empty__text">
                            Начните со стандартного набора — документы, одежда, техника, аптечка — и поправьте его под себя.
                            Или создайте свою группу вещей.
                        </p>
                        <button type="button" className="btn btn--solid" onClick={addDefaults}>Добавить стандартный набор</button>
                    </section>
                ) : (
                    <>
                        <div className="packing-progress-card mt-24">
                            <p className="packing-progress-text">
                                Собрано {packedCount} из {totalCount} {plural(totalCount, ['вещи', 'вещей', 'вещей'])} · {percent}%
                                {counts.left > 0 ? ` · Осталось ${counts.left}` : totalCount > 0 ? ' · Всё собрано!' : ''}
                            </p>
                            <div className="progress-track mt-8">
                                <div className="progress-fill" style={{ width: `${percent}%` }} />
                            </div>
                        </div>

                        <div className="packing-filters mt-24">
                            {FILTERS.map((item) => (
                                <button
                                    key={item.value}
                                    type="button"
                                    className={`packing-filter-btn ${filter === item.value ? 'active' : ''}`}
                                    onClick={() => setFilter(item.value)}
                                >
                                    {item.label} · {counts[item.value]}
                                </button>
                            ))}
                        </div>

                        <div className="packing-grid mt-24">
                            {lists.map((list) => (
                                <PackingGroup key={list.id} tripId={trip.id} list={list} visibleItems={list.items.filter(matchesFilter)} />
                            ))}
                        </div>
                    </>
                )}
            </main>
        </TripLayout>
    );
}
