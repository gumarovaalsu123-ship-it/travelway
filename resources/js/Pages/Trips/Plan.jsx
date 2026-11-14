import React, { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import TripLayout from '../../Components/TripLayout';
import { formatDateRange, formatDayLabel, formatDuration, plural } from '../../utils/format';

const dayMonthFormatter = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long' });
const weekdayFormatter = new Intl.DateTimeFormat('ru-RU', { weekday: 'long' });
const toDate = (isoDate) => new Date(`${isoDate}T00:00:00`);

/**
 * Все запросы на этой вкладке меняют только план — прокрутку страницы сохраняем.
 */
const keepScroll = { preserveScroll: true };

const placesLabel = (count) => `${count} ${plural(count, ['место', 'места', 'мест'])}`;

/**
 * «10:00–11:30», «10:00» или пустая строка, если время не указано.
 */
function timeLabel(place) {
    if (!place.startTime) {
        return '';
    }

    return place.endTime ? `${place.startTime}–${place.endTime}` : place.startTime;
}

/**
 * Мягкая подсказка, если время места не сходится с предыдущим местом.
 * Порядок задаёт только visit_order, поэтому мы ничего не запрещаем — только подсказываем.
 * Время в формате «ЧЧ:ММ» можно сравнивать как строки: «09:00» < «12:00».
 */
function timeWarning(place, previous) {
    if (!previous?.startTime || !place.startTime) {
        return null;
    }

    if (place.startTime < previous.startTime) {
        return 'Время раньше, чем у предыдущего места';
    }

    if (previous.endTime && place.startTime < previous.endTime) {
        return 'Пересекается с предыдущим местом';
    }

    return null;
}

/**
 * Список дней поездки. Номер дня — в адресе (?day=N): ссылкой можно поделиться,
 * а после любого действия сервер возвращает back() и открыт тот же день.
 */
function DaysCalendar({ trip, days, selectedDayId }) {
    return (
        <section className="days-calendar">
            <div className="days-calendar__header">
                <h2 className="days-calendar__title">Дни поездки</h2>
                <span className="days-calendar__month">{formatDateRange(trip.startDate, trip.endDate)}</span>
            </div>

            <div className="days-calendar__list">
                {days.map((day) => (
                    <Link
                        key={day.id}
                        href={`/my-trips/${trip.id}/plan?day=${day.number}`}
                        preserveScroll
                        className={`day-card ${day.id === selectedDayId ? 'active' : ''}`}
                    >
                        <span className="day-card__name">День {day.number}</span>
                        {day.date && <span className="day-card__date">{dayMonthFormatter.format(toDate(day.date))}</span>}
                        <span className="day-card__date">{day.placesCount > 0 ? placesLabel(day.placesCount) : 'Пусто'}</span>
                    </Link>
                ))}
            </div>

            {/* Длина поездки меняется только в форме редактирования, поэтому кнопки «+ Добавить день» здесь нет. */}
            <Link href={`/my-trips/${trip.id}/edit`} className="plan-edit-dates-link">Изменить даты поездки</Link>
        </section>
    );
}

/**
 * Название дня, которое редактируется на месте: клик — поле, Enter — сохранить, Esc — отменить.
 */
function DayTitle({ tripId, day }) {
    const [isEditing, setEditing] = useState(false);
    const form = useForm({ title: day.title ?? '' });

    function save() {
        if (form.data.title.trim() === (day.title ?? '')) {
            setEditing(false);
            return;
        }

        form.patch(`/my-trips/${tripId}/days/${day.id}`, { ...keepScroll, onSuccess: () => setEditing(false) });
    }

    function cancel() {
        form.setData('title', day.title ?? '');
        form.clearErrors();
        setEditing(false);
    }

    if (!isEditing) {
        return (
            <button type="button" className="plan-day-title" title="Нажмите, чтобы изменить название" onClick={() => setEditing(true)}>
                {day.title || <span className="plan-day-title__placeholder">+ Добавить название дня</span>}
            </button>
        );
    }

    return (
        <div className="plan-day-title-form">
            <input
                className="form-input form-input--filled"
                value={form.data.title}
                maxLength={100}
                placeholder="Например, «Знакомство с Алфамой»"
                autoFocus
                onChange={(e) => form.setData('title', e.target.value)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        save();
                    }
                    if (e.key === 'Escape') {
                        cancel();
                    }
                }}
            />
            <button type="button" className="btn btn--solid-dark btn--sm" disabled={form.processing} onClick={save}>Сохранить</button>
            <button type="button" className="btn btn--outline-dark btn--sm" onClick={cancel}>Отмена</button>
            {form.errors.title && <span className="form-error">{form.errors.title}</span>}
        </div>
    );
}

/**
 * Форма «Изменить» у места: начало, окончание и заметка.
 */
function EditPlaceForm({ tripId, place, onClose }) {
    const form = useForm({
        start_time: place.startTime ?? '',
        end_time: place.endTime ?? '',
        note: place.note ?? '',
    });

    function submit(e) {
        e.preventDefault();
        form.patch(`/my-trips/${tripId}/trip-places/${place.id}`, { ...keepScroll, onSuccess: onClose });
    }

    return (
        <form className="plan-place-form" onSubmit={submit} noValidate>
            <div className="form-row">
                <div className={`form-group ${form.errors.start_time ? 'has-error' : ''}`}>
                    <label className="form-label" htmlFor={`start-${place.id}`}>Начало</label>
                    <input
                        id={`start-${place.id}`}
                        type="time"
                        className="form-input form-input--filled"
                        value={form.data.start_time}
                        onChange={(e) => form.setData('start_time', e.target.value)}
                    />
                    {form.errors.start_time && <span className="form-error">{form.errors.start_time}</span>}
                </div>
                <div className={`form-group ${form.errors.end_time ? 'has-error' : ''}`}>
                    <label className="form-label" htmlFor={`end-${place.id}`}>Окончание</label>
                    <input
                        id={`end-${place.id}`}
                        type="time"
                        className="form-input form-input--filled"
                        value={form.data.end_time}
                        onChange={(e) => form.setData('end_time', e.target.value)}
                    />
                    {form.errors.end_time && <span className="form-error">{form.errors.end_time}</span>}
                </div>
            </div>

            <div className={`form-group ${form.errors.note ? 'has-error' : ''}`}>
                <label className="form-label" htmlFor={`note-${place.id}`}>Заметка</label>
                <textarea
                    id={`note-${place.id}`}
                    className="form-input form-input--filled"
                    rows={2}
                    maxLength={500}
                    placeholder="Например, купить билеты заранее"
                    value={form.data.note}
                    onChange={(e) => form.setData('note', e.target.value)}
                />
                {form.errors.note && <span className="form-error">{form.errors.note}</span>}
            </div>

            <div className="plan-place-form__actions">
                <button type="submit" className="btn btn--solid-dark btn--sm" disabled={form.processing}>Сохранить</button>
                <button type="button" className="btn btn--outline-dark btn--sm" onClick={onClose}>Отмена</button>
            </div>
        </form>
    );
}

/**
 * Одно место в плане дня: номер, время, название, адрес, заметка и действия.
 */
function PlaceRow({ tripId, place, number, isFirst, isLast, warning, otherDays }) {
    const [isEditing, setEditing] = useState(false);
    const url = `/my-trips/${tripId}/trip-places/${place.id}`;

    function move(direction) {
        router.patch(`${url}/move`, { direction }, keepScroll);
    }

    function changeDay(dayId) {
        if (dayId) {
            router.patch(`${url}/day`, { trip_day_id: Number(dayId) }, keepScroll);
        }
    }

    function remove() {
        // Существующий маршрут trip-places.destroy: место исчезнет из базы, только если оно больше нигде не нужно.
        if (window.confirm(`Убрать «${place.name}» из плана?`)) {
            router.delete(`/trip-places/${place.id}`, keepScroll);
        }
    }

    return (
        <li className="stop-item plan-stop">
            <span className="stop-number-badge">{number}</span>

            <div className="stop-info">
                {place.startTime && <span className="stop-time plan-stop__time">{timeLabel(place)}</span>}
                <span className="stop-name">{place.name}</span>
                {(place.address || place.category) && <span className="stop-desc">{place.address || place.category}</span>}
                {place.note && <p className="plan-stop__note">{place.note}</p>}
                {warning && <span className="plan-stop__warning">{warning}</span>}

                {isEditing ? (
                    <EditPlaceForm tripId={tripId} place={place} onClose={() => setEditing(false)} />
                ) : (
                    <div className="plan-stop__actions">
                        {!isFirst && (
                            <button type="button" className="btn btn--outline-dark btn--icon-sm" aria-label="Выше" title="Выше" onClick={() => move('up')}>↑</button>
                        )}
                        {!isLast && (
                            <button type="button" className="btn btn--outline-dark btn--icon-sm" aria-label="Ниже" title="Ниже" onClick={() => move('down')}>↓</button>
                        )}
                        <button type="button" className="btn btn--outline-dark btn--sm" onClick={() => setEditing(true)}>Изменить</button>
                        {otherDays.length > 0 && (
                            <select
                                className="form-input plan-stop__move-select"
                                value=""
                                aria-label="Перенести в другой день"
                                onChange={(e) => changeDay(e.target.value)}
                            >
                                <option value="">Перенести в…</option>
                                {otherDays.map((day) => (
                                    <option key={day.id} value={day.id}>{formatDayLabel(day.number, day.date)}</option>
                                ))}
                            </select>
                        )}
                        <button type="button" className="link-button plan-stop__remove" onClick={remove}>Убрать из плана</button>
                    </div>
                )}
            </div>
        </li>
    );
}

/**
 * Окно «Из избранного»: место добавляется в конец выбранного дня
 * через уже существующий маршрут trip-places.store.
 */
function FavoritesDialog({ favorites, day, onClose }) {
    const [processingId, setProcessingId] = useState(null);

    function add(place) {
        setProcessingId(place.id);
        router.post(`/places/${place.id}/trip-places`, { trip_day_id: day.id }, {
            ...keepScroll,
            onSuccess: onClose,
            onFinish: () => setProcessingId(null),
        });
    }

    return (
        <div className="plan-dialog-backdrop" onClick={onClose}>
            {/* Клик внутри окна не должен закрывать его, поэтому не пропускаем событие к подложке. */}
            <div className="plan-dialog" role="dialog" aria-modal="true" aria-label="Добавить из избранного" onClick={(e) => e.stopPropagation()}>
                <div className="plan-dialog__header">
                    <h3 className="plan-dialog__title">Из избранного в день {day.number}</h3>
                    <button type="button" className="btn-close" aria-label="Закрыть" onClick={onClose}>×</button>
                </div>

                {favorites.length === 0 ? (
                    <p className="form-hint">
                        В избранном пока пусто. Сохраняйте места на <Link href="/interactive-map">интерактивной карте</Link>.
                    </p>
                ) : (
                    <ul className="plan-dialog__list">
                        {favorites.map((place) => (
                            <li key={place.id} className="plan-dialog__item">
                                <div className="stop-info">
                                    <span className="stop-name">{place.name}</span>
                                    {(place.address || place.category) && <span className="stop-desc">{place.address || place.category}</span>}
                                </div>
                                <button
                                    type="button"
                                    className="btn btn--solid-dark btn--sm"
                                    disabled={processingId !== null}
                                    onClick={() => add(place)}
                                >
                                    Добавить
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}

export default function Plan({ trip, days, selectedDay, favorites }) {
    const [isFavoritesOpen, setFavoritesOpen] = useState(false);

    if (!selectedDay) {
        return (
            <TripLayout trip={trip} activeTab="plan">
                <main className="container trip-plan-main">
                    <p className="form-hint">У поездки пока нет дней. Укажите даты или число дней в настройках поездки.</p>
                </main>
            </TripLayout>
        );
    }

    const places = selectedDay.places;
    const otherDays = days.filter((day) => day.id !== selectedDay.id);
    const findOnMapUrl = `/interactive-map?day=${selectedDay.id}`;

    const addButtons = (
        <>
            <Link href={findOnMapUrl} className="btn btn--solid-dark btn--sm">Найти на карте</Link>
            <button type="button" className="btn btn--outline-dark btn--sm" onClick={() => setFavoritesOpen(true)}>Из избранного</button>
        </>
    );

    return (
        <TripLayout trip={trip} activeTab="plan">
            <main className="container trip-plan-main">
                <DaysCalendar trip={trip} days={days} selectedDayId={selectedDay.id} />

                <div className="trip-plan-grid">
                    <div className="trip-plan__main-col">
                        <header className="selected-day-header">
                            <div className="selected-day-info">
                                <h2 className="selected-day-title">{formatDayLabel(selectedDay.number, selectedDay.date)}</h2>
                                {selectedDay.date && <span className="selected-day-weekday">{weekdayFormatter.format(toDate(selectedDay.date))}</span>}
                            </div>
                            <span className="selected-day-badge">{places.length > 0 ? placesLabel(places.length) : 'Нет мест'}</span>
                        </header>

                        <div className="day-itinerary-card">
                            <div className="itinerary-block">
                                <div className="itinerary-block__header plan-day-header">
                                    {/* key: при переходе на другой день форма названия создаётся заново со своим значением. */}
                                    <DayTitle key={selectedDay.id} tripId={trip.id} day={selectedDay} />
                                    <div className="itinerary-block__actions">
                                        {places.length > 1 && (
                                            <button
                                                type="button"
                                                className="btn btn--outline-dark btn--sm"
                                                onClick={() => router.post(`/my-trips/${trip.id}/days/${selectedDay.id}/sort-by-time`, {}, keepScroll)}
                                            >
                                                Упорядочить по времени
                                            </button>
                                        )}
                                        <Link href={`/my-trips/${trip.id}/map?day=${selectedDay.number}`} className="btn btn--outline-dark btn--sm">
                                            Построить маршрут
                                        </Link>
                                    </div>
                                </div>

                                {places.length === 0 ? (
                                    <div className="empty-day-row plan-empty-day">
                                        <div className="empty-day-info">
                                            <span className="empty-day-name">{formatDayLabel(selectedDay.number, selectedDay.date)}</span>
                                            <span className="empty-day-desc">Места ещё не добавлены</span>
                                        </div>
                                        <div className="plan-add-buttons">{addButtons}</div>
                                    </div>
                                ) : (
                                    <>
                                        <ul className="stop-list">
                                            {places.map((place, index) => (
                                                <PlaceRow
                                                    key={place.id}
                                                    tripId={trip.id}
                                                    place={place}
                                                    number={index + 1}
                                                    isFirst={index === 0}
                                                    isLast={index === places.length - 1}
                                                    warning={timeWarning(place, places[index - 1])}
                                                    otherDays={otherDays}
                                                />
                                            ))}
                                        </ul>
                                        <div className="plan-add-buttons">{addButtons}</div>
                                    </>
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="trip-plan__side-col">
                        <aside className="trip-details-card">
                            <h3 className="trip-details__title">Детали поездки</h3>

                            <div className="trip-details__list">
                                <div className="detail-item">
                                    <span className="detail-label">Направление</span>
                                    <span className="detail-value">{trip.destination}</span>
                                </div>
                                <div className="detail-item">
                                    <span className="detail-label">Даты поездки</span>
                                    <span className="detail-value">{formatDateRange(trip.startDate, trip.endDate)}</span>
                                </div>
                                <div className="detail-item">
                                    <span className="detail-label">Продолжительность</span>
                                    <span className="detail-value">{formatDuration(trip.daysCount)}</span>
                                </div>
                                <div className="detail-item">
                                    <span className="detail-label">Места в плане</span>
                                    <span className="detail-value">{trip.placesCount > 0 ? placesLabel(trip.placesCount) : 'Пока не добавлены'}</span>
                                </div>
                            </div>

                            <hr className="detail-divider" />

                            <Link href={`/my-trips/${trip.id}/edit`} className="btn btn--outline-dark btn--full">Настройки поездки</Link>
                        </aside>
                    </div>
                </div>
            </main>

            {isFavoritesOpen && <FavoritesDialog favorites={favorites} day={selectedDay} onClose={() => setFavoritesOpen(false)} />}
        </TripLayout>
    );
}
