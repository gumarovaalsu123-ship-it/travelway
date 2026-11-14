import React, { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import TripLayout, { TRIP_TABS } from '../../Components/TripLayout';
import { plural } from '../../utils/format';

/**
 * Все запросы на этой вкладке меняют только списки дел — прокрутку страницы сохраняем.
 */
const keepScroll = { preserveScroll: true };

const dayMonthFormatter = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long' });

function CheckIcon() {
    return (
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">
            <polyline points="20 6 9 17 4 12" />
        </svg>
    );
}

/**
 * Адрес вкладки поездки по её ключу (ключи — из TRIP_TABS), как на «Подготовке».
 */
function tabUrl(tripId, tabKey) {
    const tab = TRIP_TABS.find((item) => item.key === tabKey);

    return tab ? `/my-trips/${tripId}${tab.path}` : null;
}

/**
 * Куда отправить отметку «сделано». У автоматических дел отметка ставится у самой записи
 * (билет куплен / жильё забронировано / у места «куплено»), у своих дел — в trip_tasks.
 */
function toggleDone(tripId, task) {
    const base = `/my-trips/${tripId}`;
    const requests = {
        transport: () => router.patch(`${base}/transport/${task.id}/booked`, {}, keepScroll),
        accommodation: () => router.patch(`${base}/accommodation/${task.id}/booked`, {}, keepScroll),
        place: () => router.patch(`${base}/trip-places/${task.id}/booking`, { is_booked: !task.isDone }, keepScroll),
        task: () => router.patch(`${base}/tasks/${task.id}/toggle`, {}, keepScroll),
    };

    requests[task.kind]();
}

/**
 * Срок дела: «осталось 5 дней», «сегодня», «просрочено». daysLeft считает сервер.
 */
function DueLabel({ task }) {
    if (!task.dueDate) {
        return null;
    }

    const date = dayMonthFormatter.format(new Date(`${task.dueDate}T00:00:00`));
    const isOverdue = !task.isDone && task.daysLeft < 0;
    let left = '';

    if (!task.isDone) {
        if (task.daysLeft < 0) {
            left = ' · просрочено';
        } else if (task.daysLeft === 0) {
            left = ' · сегодня';
        } else {
            left = ` · осталось ${task.daysLeft} ${plural(task.daysLeft, ['день', 'дня', 'дней'])}`;
        }
    }

    return <span className={`advance-task__due ${isOverdue ? 'advance-task__due--overdue' : ''}`}>до {date}{left}</span>;
}

/**
 * Автоматическое дело: галочка, название, срок и ссылка во вкладку, где лежит сама запись.
 */
function AutoTaskRow({ tripId, task }) {
    const url = tabUrl(tripId, task.tab);
    const isOverdue = !task.isDone && task.daysLeft !== null && task.daysLeft < 0;

    return (
        <li className={`packing-item advance-task ${task.isDone ? 'packing-item--checked' : ''} ${isOverdue ? 'advance-task--overdue' : ''}`}>
            <label className="custom-checkbox custom-checkbox--packing">
                <input type="checkbox" className="custom-checkbox__input" checked={task.isDone} onChange={() => toggleDone(tripId, task)} />
                <span className="custom-checkbox__box"><CheckIcon /></span>
            </label>
            <div className="packing-item__text">
                <span className="packing-item__name">{task.title}</span>
                <DueLabel task={task} />
            </div>
            {url && <Link href={url} className="prep-hint__link">Открыть →</Link>}
        </li>
    );
}

/**
 * Своё дело: галочка, название и срок; по клику на название — редактирование, × — удалить.
 */
function OwnTaskRow({ tripId, task }) {
    const [isEditing, setEditing] = useState(false);
    const form = useForm({ title: task.title, due_date: task.dueDate ?? '' });
    const url = `/my-trips/${tripId}/tasks/${task.id}`;
    const isOverdue = !task.isDone && task.daysLeft !== null && task.daysLeft < 0;

    function save(e) {
        e.preventDefault();
        // setDefaults: сохранённые значения становятся исходными, «Отмена» потом вернёт к ним.
        form.put(url, { ...keepScroll, onSuccess: () => { form.setDefaults(); setEditing(false); } });
    }

    function cancel() {
        form.reset();
        form.clearErrors();
        setEditing(false);
    }

    function remove() {
        if (window.confirm(`Удалить дело «${task.title}»?`)) {
            router.delete(url, keepScroll);
        }
    }

    return (
        <li className={`packing-item advance-task ${task.isDone ? 'packing-item--checked' : ''} ${isOverdue ? 'advance-task--overdue' : ''}`}>
            <label className="custom-checkbox custom-checkbox--packing">
                <input type="checkbox" className="custom-checkbox__input" checked={task.isDone} onChange={() => toggleDone(tripId, task)} />
                <span className="custom-checkbox__box"><CheckIcon /></span>
            </label>

            <div className="packing-item__text">
                {isEditing ? (
                    <form className="advance-task-form" onSubmit={save} noValidate>
                        <input
                            className="form-input"
                            value={form.data.title}
                            maxLength={255}
                            autoFocus
                            onChange={(e) => form.setData('title', e.target.value)}
                            onKeyDown={(e) => e.key === 'Escape' && cancel()}
                        />
                        <input
                            type="date"
                            className="form-input advance-task-form__date"
                            value={form.data.due_date}
                            onChange={(e) => form.setData('due_date', e.target.value)}
                        />
                        <button type="submit" className="btn btn--solid-dark btn--sm" disabled={form.processing}>Сохранить</button>
                        <button type="button" className="btn btn--outline-dark btn--sm" onClick={cancel}>Отмена</button>
                        {form.errors.title && <span className="form-error">{form.errors.title}</span>}
                        {form.errors.due_date && <span className="form-error">{form.errors.due_date}</span>}
                    </form>
                ) : (
                    <>
                        <button type="button" className="packing-item__name packing-rename-btn" title="Нажмите, чтобы изменить" onClick={() => setEditing(true)}>
                            {task.title}
                        </button>
                        <DueLabel task={task} />
                    </>
                )}
            </div>

            <button type="button" className="packing-delete-btn" aria-label={`Удалить «${task.title}»`} onClick={remove}>×</button>
        </li>
    );
}

/**
 * Добавить своё дело: название и необязательный срок. После добавления поля очищаются.
 */
function AddTaskForm({ tripId }) {
    const form = useForm({ title: '', due_date: '' });

    function submit(e) {
        e.preventDefault();
        form.post(`/my-trips/${tripId}/tasks`, { ...keepScroll, onSuccess: () => form.reset() });
    }

    return (
        <form className="packing-add-form" onSubmit={submit} noValidate>
            <div className="packing-add-form__row">
                <input
                    className="form-input"
                    placeholder="Например, «Оформить страховку»"
                    maxLength={255}
                    value={form.data.title}
                    onChange={(e) => form.setData('title', e.target.value)}
                />
                <input
                    type="date"
                    className="form-input advance-task-form__date"
                    title="Срок (необязательно)"
                    value={form.data.due_date}
                    onChange={(e) => form.setData('due_date', e.target.value)}
                />
                <button type="submit" className="btn btn--solid btn--sm" disabled={form.processing}>+</button>
            </div>
            {form.errors.title && <span className="form-error">{form.errors.title}</span>}
            {form.errors.due_date && <span className="form-error">{form.errors.due_date}</span>}
        </form>
    );
}

/**
 * Вкладка «Заранее»: билеты и брони (собираются сами из транспорта, жилья и плана) и свои дела.
 * Все дела уже отсортированы сервером по сроку (app/Services/TripReadiness.php, advanceTasks()).
 */
export default function TripAdvance({ trip, tasks }) {
    const autoTasks = tasks.filter((task) => task.kind !== 'task');
    const ownTasks = tasks.filter((task) => task.kind === 'task');
    const doneCount = tasks.filter((task) => task.isDone).length;

    return (
        <TripLayout trip={trip} activeTab="advance">
            <main className="container trip-content">
                <header className="section-header">
                    <h2 className="section-title">Что сделать заранее</h2>
                    <p className="section-subtitle">
                        {tasks.length > 0 ? `Сделано: ${doneCount} из ${tasks.length}` : 'Дел пока нет'}
                    </p>
                </header>

                <div className="advance-grid">
                    <article className="packing-category-card">
                        <header className="packing-category__header">
                            <h3 className="packing-category__title">Билеты и брони</h3>
                        </header>
                        {autoTasks.length > 0 ? (
                            <ul className="advance-list">
                                {autoTasks.map((task) => (
                                    <AutoTaskRow key={`${task.kind}-${task.id}`} tripId={trip.id} task={task} />
                                ))}
                            </ul>
                        ) : (
                            <p className="prep-empty">
                                Здесь появятся билеты на транспорт, брони жилья и места плана с отметкой «нужен билет».
                            </p>
                        )}
                    </article>

                    <article className="packing-category-card">
                        <header className="packing-category__header">
                            <h3 className="packing-category__title">Свои дела</h3>
                        </header>
                        {ownTasks.length > 0 && (
                            <ul className="advance-list">
                                {ownTasks.map((task) => <OwnTaskRow key={task.id} tripId={trip.id} task={task} />)}
                            </ul>
                        )}
                        <AddTaskForm tripId={trip.id} />
                    </article>
                </div>
            </main>
        </TripLayout>
    );
}
