import React, { useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import TripLayout from '../../Components/TripLayout';

/**
 * Подписи и иконки видов транспорта. Ключи совпадают с Transport::Types на сервере.
 */
const TYPE_LABELS = {
    plane: 'Самолёт',
    train: 'Поезд',
    bus: 'Автобус',
    car: 'Автомобиль',
    ferry: 'Паром',
    other: 'Другое',
};

const ICON_PATHS = {
    plane: <path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.2-1.1.7l-1.2 3.6 7.6 4.3-3.6 3.6-3.8-.9c-.4-.1-.8.1-1 .5l-1 2.2 4.8 1.4 1.4 4.8 2.2-1c.4-.2.6-.6.5-1l-.9-3.8 3.6-3.6 4.3 7.6c.5-.2.8-.6.7-1.1z" />,
    train: (
        <>
            <rect x="4" y="3" width="16" height="16" rx="2" />
            <path d="M4 11h16M12 3v8M8 19l-2 3M18 22l-2-3M8 15h.01M16 15h.01" />
        </>
    ),
    bus: (
        <>
            <rect x="3" y="4" width="18" height="13" rx="2" />
            <path d="M3 11h18M7 17v3M17 17v3M7 14h.01M17 14h.01" />
        </>
    ),
    car: (
        <>
            <path d="M5 17H3v-5l2-5h14l2 5v5h-2" />
            <circle cx="7.5" cy="17" r="2" />
            <circle cx="16.5" cy="17" r="2" />
            <path d="M9.5 17h5M3 12h18" />
        </>
    ),
    ferry: <path d="M2 20c2 1 4 1 6 0s4-1 6 0 4 1 6 0M4 16l-1-5h18l-1 5M6 11V6h12v5M12 6V3" />,
    other: <path d="M5 12h14M12 5l7 7-7 7" />,
};

const EMPTY_FORM = {
    type: 'plane',
    departure_place: '',
    arrival_place: '',
    departure_at: '',
    arrival_at: '',
    booking_info: '',
    note: '',
};

const dateFormatter = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' });

/**
 * Время приходит строкой «2026-06-15T13:15»: часы берём прямо из строки, дату показываем словами.
 */
const formatTime = (dateTime) => (dateTime ? dateTime.slice(11, 16) : '—');
const formatDate = (dateTime) => (dateTime ? dateFormatter.format(new Date(dateTime)) : 'Время не указано');

function TransportIcon({ type }) {
    return (
        <div className="transport-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                {ICON_PATHS[type] ?? ICON_PATHS.other}
            </svg>
        </div>
    );
}

function TransportCard({ transport, onEdit, onDelete }) {
    const meta = [transport.bookingInfo && `Бронь: ${transport.bookingInfo}`, transport.note].filter(Boolean);

    return (
        <article className="transport-card">
            <header className="transport-card__header">
                <div className="transport-card__title-group">
                    <TransportIcon type={transport.type} />
                    <div className="transport-card__headings">
                        <h3 className="transport-title">{transport.departurePlace} → {transport.arrivalPlace}</h3>
                        <p className="transport-subtitle">{formatDate(transport.departureAt)}</p>
                    </div>
                </div>
                <span className="day-badge">{TYPE_LABELS[transport.type]}</span>
            </header>

            <div className="transport-card__body">
                <div className="time-block">
                    <div className="time-block__time">{formatTime(transport.departureAt)}</div>
                    <div className="time-block__place">{transport.departurePlace}</div>
                </div>
                <div className="time-block__arrow">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <path d="M5 12h14M12 5l7 7-7 7" />
                    </svg>
                </div>
                <div className="time-block">
                    <div className="time-block__time">{formatTime(transport.arrivalAt)}</div>
                    <div className="time-block__place">{transport.arrivalPlace}</div>
                </div>
            </div>

            <footer className="transport-card__footer">
                <p className="transport-card__meta">{meta.length > 0 ? meta.join(' · ') : 'Без брони и заметок'}</p>
                <div className="transport-card__actions">
                    <button type="button" className="btn btn--outline-dark btn--sm" onClick={() => onEdit(transport)}>Изменить</button>
                    <button type="button" className="btn btn--danger-outline btn--sm" onClick={() => onDelete(transport)}>Удалить</button>
                </div>
            </footer>
        </article>
    );
}

/**
 * Поле формы: при ошибке под полем показывается её текст.
 */
function Field({ id, label, required = false, error, children }) {
    return (
        <div className={`form-group ${error ? 'has-error' : ''}`}>
            <label htmlFor={id} className="form-label">
                {label} {required && <span className="required">*</span>}
            </label>
            {children}
            {error && <span className="form-hint">{error}</span>}
        </div>
    );
}

/**
 * Страница поездки, вкладка «Транспорт»: переезды по времени и форма добавления/редактирования справа.
 */
export default function TripTransport({ trip, transports, types }) {
    // id записи, которую сейчас редактируем; null — форма добавляет новую запись.
    const [editingId, setEditingId] = useState(null);
    const firstFieldRef = useRef(null);

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm(EMPTY_FORM);

    const startAdding = () => {
        setEditingId(null);
        reset();
        clearErrors();
        firstFieldRef.current?.focus();
    };

    const startEditing = (transport) => {
        setEditingId(transport.id);
        clearErrors();
        setData({
            type: transport.type,
            departure_place: transport.departurePlace,
            arrival_place: transport.arrivalPlace,
            departure_at: transport.departureAt ?? '',
            arrival_at: transport.arrivalAt ?? '',
            booking_info: transport.bookingInfo ?? '',
            note: transport.note ?? '',
        });
        firstFieldRef.current?.focus();
    };

    const submit = (event) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setEditingId(null);
                reset();
            },
        };

        if (editingId) {
            put(`/my-trips/${trip.id}/transport/${editingId}`, options);
        } else {
            post(`/my-trips/${trip.id}/transport`, options);
        }
    };

    const destroy = (transport) => {
        if (!window.confirm(`Удалить запись «${transport.departurePlace} → ${transport.arrivalPlace}»?`)) {
            return;
        }

        router.delete(`/my-trips/${trip.id}/transport/${transport.id}`, {
            preserveScroll: true,
            // Если удалили запись, открытую в форме, форма возвращается к добавлению.
            onSuccess: () => {
                if (transport.id === editingId) {
                    startAdding();
                }
            },
        });
    };

    return (
        <TripLayout trip={trip} activeTab="transport">
            <main className="container trip-transport-main trip-content">
                <header className="section-header section-header--spaced">
                    <div className="section-header__text">
                        <h2 className="section-title">Транспорт и билеты</h2>
                        <p className="section-subtitle section-subtitle--light">Перелёты, поезда и другие переезды поездки — в одном месте.</p>
                    </div>
                    <button type="button" className="btn btn--solid-dark" onClick={startAdding}>+ Добавить запись</button>
                </header>

                <div className="trip-plan-grid transport-grid">
                    <div className="transport-list">
                        {transports.length === 0 ? (
                            <section className="trips-empty">
                                <h3 className="trips-empty__title">Переездов пока нет</h3>
                                <p className="trips-empty__text">Добавьте перелёт, поезд или трансфер — записи выстроятся по времени отправления.</p>
                            </section>
                        ) : transports.map((transport) => (
                            <TransportCard key={transport.id} transport={transport} onEdit={startEditing} onDelete={destroy} />
                        ))}
                    </div>

                    <aside className="trip-details-card">
                        <h3 className="trip-details__title">{editingId ? 'Изменить запись' : 'Новая запись'}</h3>

                        <form onSubmit={submit} noValidate>
                            <Field id="type" label="Вид транспорта" required error={errors.type}>
                                <select
                                    id="type"
                                    ref={firstFieldRef}
                                    className="form-input form-input--filled form-select"
                                    value={data.type}
                                    onChange={(event) => setData('type', event.target.value)}
                                >
                                    {types.map((type) => (
                                        <option key={type} value={type}>{TYPE_LABELS[type]}</option>
                                    ))}
                                </select>
                            </Field>

                            <Field id="departure_place" label="Откуда" required error={errors.departure_place}>
                                <input
                                    id="departure_place"
                                    type="text"
                                    className="form-input form-input--filled"
                                    placeholder="Москва, Шереметьево"
                                    value={data.departure_place}
                                    onChange={(event) => setData('departure_place', event.target.value)}
                                    maxLength={150}
                                />
                            </Field>

                            <Field id="departure_at" label="Отправление" error={errors.departure_at}>
                                <input
                                    id="departure_at"
                                    type="datetime-local"
                                    className="form-input form-input--filled"
                                    value={data.departure_at}
                                    onChange={(event) => setData('departure_at', event.target.value)}
                                />
                            </Field>

                            <Field id="arrival_place" label="Куда" required error={errors.arrival_place}>
                                <input
                                    id="arrival_place"
                                    type="text"
                                    className="form-input form-input--filled"
                                    placeholder="Казань, аэропорт"
                                    value={data.arrival_place}
                                    onChange={(event) => setData('arrival_place', event.target.value)}
                                    maxLength={150}
                                />
                            </Field>

                            <Field id="arrival_at" label="Прибытие" error={errors.arrival_at}>
                                <input
                                    id="arrival_at"
                                    type="datetime-local"
                                    className="form-input form-input--filled"
                                    min={data.departure_at || undefined}
                                    value={data.arrival_at}
                                    onChange={(event) => setData('arrival_at', event.target.value)}
                                />
                            </Field>

                            <Field id="booking_info" label="Номер брони или билета" error={errors.booking_info}>
                                <input
                                    id="booking_info"
                                    type="text"
                                    className="form-input form-input--filled"
                                    placeholder="SU 1234, место 12A"
                                    value={data.booking_info}
                                    onChange={(event) => setData('booking_info', event.target.value)}
                                    maxLength={1000}
                                />
                            </Field>

                            <Field id="note" label="Заметка" error={errors.note}>
                                <textarea
                                    id="note"
                                    className="form-input form-input--filled"
                                    rows={3}
                                    placeholder="Багаж 23 кг, регистрация онлайн"
                                    value={data.note}
                                    onChange={(event) => setData('note', event.target.value)}
                                    maxLength={2000}
                                />
                            </Field>

                            <div className="form-actions-row">
                                {editingId && (
                                    <button type="button" className="btn btn--outline-dark" onClick={startAdding}>Отмена</button>
                                )}
                                <button type="submit" className="btn btn--solid-dark" disabled={processing}>
                                    {editingId ? 'Сохранить' : 'Добавить'}
                                </button>
                            </div>
                        </form>
                    </aside>
                </div>
            </main>
        </TripLayout>
    );
}
