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
 * Способы добраться до места — ключи как в TripPlace::TravelModes на сервере.
 */
const TRAVEL_MODES = {
    walk: { icon: '🚶', label: 'Пешком' },
    transit: { icon: '🚌', label: 'Общественный транспорт' },
    car: { icon: '🚗', label: 'Машина' },
    taxi: { icon: '🚕', label: 'Такси / каршеринг' },
};

/**
 * 75 → «1 ч 15 мин», 40 → «40 мин», 120 → «2 ч».
 */
function formatMinutes(total) {
    const hours = Math.floor(total / 60);
    const minutes = total % 60;

    if (hours === 0) {
        return `${minutes} мин`;
    }

    return minutes > 0 ? `${hours} ч ${minutes} мин` : `${hours} ч`;
}

/**
 * Отрезок пути: «🚶 12 мин». Время на общественном транспорте — только оценка, поэтому «≈».
 */
function segmentLabel(segment) {
    const mode = TRAVEL_MODES[segment.mode] ?? TRAVEL_MODES.walk;

    return `${mode.icon} ${segment.approximate ? '≈ ' : ''}${formatMinutes(segment.minutes)}`;
}

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
 * Строка между местами: как добраться от предыдущей точки и сколько это займёт.
 * Для общественного транспорта — ссылка на точный маршрут в Яндекс.Картах (обычная ссылка, без API).
 */
function SegmentRow({ segment }) {
    return (
        <li className="plan-segment">
            <span className="plan-segment__label" title={TRAVEL_MODES[segment.mode]?.label}>{segmentLabel(segment)}</span>
            {segment.yandexUrl && (
                <a className="plan-segment__link" href={segment.yandexUrl} target="_blank" rel="noreferrer">
                    Точный маршрут в Яндекс.Картах
                </a>
            )}
        </li>
    );
}

/**
 * «Начало дня» и кнопка «Рассчитать время». Сервер спрашивает время в пути у OSRM
 * и раскладывает места по времени, закреплённое время 🔒 не трогает.
 */
function ScheduleForm({ tripId, day }) {
    const form = useForm({ start_time: day.startTime ?? '' });

    function submit(e) {
        e.preventDefault();
        form.post(`/my-trips/${tripId}/days/${day.id}/schedule`, keepScroll);
    }

    return (
        <form className="plan-schedule-form" onSubmit={submit} noValidate>
            <label className="form-label" htmlFor="day-start-time">Начало дня</label>
            <input
                id="day-start-time"
                type="time"
                className="form-input form-input--filled plan-schedule-form__time"
                value={form.data.start_time}
                title={`Если пусто — ${day.scheduleStartTime}`}
                onChange={(e) => form.setData('start_time', e.target.value)}
            />
            <button type="submit" className="btn btn--solid-dark btn--sm" disabled={form.processing}>
                {form.processing ? 'Считаем…' : 'Рассчитать время'}
            </button>
            {form.errors.start_time && <span className="form-error">{form.errors.start_time}</span>}
            {form.errors.schedule && <span className="form-error">{form.errors.schedule}</span>}
        </form>
    );
}

/**
 * Итог дня под списком: дорога к жилью, во сколько вернётесь, сколько в пути и на местах.
 */
function DayTotal({ day }) {
    const returnSegment = day.returnSegment;

    return (
        <div className="plan-day-total">
            {returnSegment && (
                <p className="plan-day-total__return">
                    {segmentLabel(returnSegment)} до «{returnSegment.lodging}» — вернётесь ~{day.endTime}
                </p>
            )}
            <p className="plan-day-total__summary">
                {day.endTime && <>Итог дня: {day.scheduleStartTime}–{day.endTime} · </>}
                на местах {formatMinutes(day.stayMinutes)}
                {day.isCalculated && <> · в пути {formatMinutes(day.travelMinutes)}</>}
            </p>
            {!day.isCalculated && (
                <p className="form-hint">Нажмите «Рассчитать время», чтобы узнать время в пути и разложить места по часам.</p>
            )}
            {day.warnings.map((warning) => (
                <p key={warning} className="plan-stop__warning">{warning}</p>
            ))}
        </div>
    );
}

/**
 * Форма «Изменить» у места: время, сколько провести, способ добраться, закрепление и заметка.
 */
function EditPlaceForm({ tripId, place, onClose }) {
    const form = useForm({
        start_time: place.startTime ?? '',
        end_time: place.endTime ?? '',
        note: place.note ?? '',
        stay_minutes: place.stayMinutes ?? '',
        travel_mode: place.travelMode ?? '',
        time_locked: place.timeLocked,
    });

    // Время, поставленное вручную, сразу закрепляем — иначе следующий расчёт его передвинет.
    function changeStartTime(value) {
        form.setData((data) => ({ ...data, start_time: value, time_locked: value !== '' ? true : data.time_locked }));
    }

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
                        onChange={(e) => changeStartTime(e.target.value)}
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

            <label className="plan-place-form__check">
                <input
                    type="checkbox"
                    checked={form.data.time_locked}
                    onChange={(e) => form.setData('time_locked', e.target.checked)}
                />
                🔒 Закрепить время — «Рассчитать время» его не сдвинет
            </label>
            {form.errors.time_locked && <span className="form-error">{form.errors.time_locked}</span>}

            <div className="form-row">
                <div className={`form-group ${form.errors.stay_minutes ? 'has-error' : ''}`}>
                    <label className="form-label" htmlFor={`stay-${place.id}`}>Сколько провести, мин</label>
                    <input
                        id={`stay-${place.id}`}
                        type="number"
                        min={5}
                        max={720}
                        step={5}
                        className="form-input form-input--filled"
                        placeholder={`${place.defaultStayMinutes} (по категории)`}
                        value={form.data.stay_minutes}
                        onChange={(e) => form.setData('stay_minutes', e.target.value)}
                    />
                    {form.errors.stay_minutes && <span className="form-error">{form.errors.stay_minutes}</span>}
                </div>
                <div className={`form-group ${form.errors.travel_mode ? 'has-error' : ''}`}>
                    <label className="form-label" htmlFor={`mode-${place.id}`}>Как добраться</label>
                    <select
                        id={`mode-${place.id}`}
                        className="form-input form-input--filled"
                        value={form.data.travel_mode}
                        onChange={(e) => form.setData('travel_mode', e.target.value)}
                    >
                        <option value="">Автоматически</option>
                        {Object.entries(TRAVEL_MODES).map(([key, mode]) => (
                            <option key={key} value={key}>{mode.icon} {mode.label}</option>
                        ))}
                    </select>
                    {form.errors.travel_mode && <span className="form-error">{form.errors.travel_mode}</span>}
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
 * Вопрос о билете зависит от категории места (концепция, раздел 3.4). Ключи — slug категории.
 * Решает человек: приложение только спрашивает.
 */
const BOOKING_QUESTIONS = {
    museum: { question: 'Нужен ли сюда билет заранее?', done: 'Билет куплен' },
    attraction: { question: 'Нужен ли сюда билет заранее?', done: 'Билет куплен' },
    restaurant: { question: 'Нужна бронь столика?', done: 'Столик забронирован' },
};

const DEFAULT_BOOKING_QUESTION = { question: 'Нужен билет или бронь заранее?', done: 'Куплено' };

/**
 * Отметки «нужен билет/бронь» и «куплено» у места. Место с билетом, который ещё не куплен,
 * попадает в дела вкладки «Заранее»; отметка «куплено» там и здесь — одна и та же.
 */
function BookingMarks({ tripId, place }) {
    const labels = BOOKING_QUESTIONS[place.categorySlug] ?? DEFAULT_BOOKING_QUESTION;
    const url = `/my-trips/${tripId}/trip-places/${place.id}/booking`;

    return (
        <div className="plan-stop__booking">
            <label className="plan-place-form__check">
                <input
                    type="checkbox"
                    checked={place.needsBooking}
                    onChange={(e) => router.patch(url, { needs_booking: e.target.checked }, keepScroll)}
                />
                {labels.question}
            </label>
            {place.needsBooking && (
                <label className="plan-place-form__check">
                    <input
                        type="checkbox"
                        checked={place.isBooked}
                        onChange={(e) => router.patch(url, { is_booked: e.target.checked }, keepScroll)}
                    />
                    {labels.done}
                </label>
            )}
            {place.needsBooking && !place.isBooked && (
                <Link href={`/my-trips/${tripId}/advance`} className="plan-stop__booking-link">В делах «Заранее» →</Link>
            )}
        </div>
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
                {place.startTime && (
                    <span className="stop-time plan-stop__time">
                        {timeLabel(place)}
                        {place.timeLocked && <span title="Время закреплено: расчёт его не двигает"> 🔒</span>}
                    </span>
                )}
                <span className="stop-name">{place.name}</span>
                {(place.address || place.category) && <span className="stop-desc">{place.address || place.category}</span>}
                <span className="plan-stop__stay">
                    На месте {formatMinutes(place.stayMinutes ?? place.defaultStayMinutes)}
                    {place.stayMinutes === null && ' (по категории)'}
                </span>
                {place.note && <p className="plan-stop__note">{place.note}</p>}
                {warning && <span className="plan-stop__warning">{warning}</span>}
                {/* Подсказки после расчёта: приезд позже закреплённого времени или заметно раньше него. */}
                {place.lateMinutes > 0 && (
                    <span className="plan-stop__warning">
                        Не успеваете к {place.startTime} (опоздание {formatMinutes(place.lateMinutes)})
                    </span>
                )}
                {place.freeMinutes > 0 && place.timeLocked && (
                    <span className="plan-stop__free">Свободное окно {formatMinutes(place.freeMinutes)}</span>
                )}
                <BookingMarks tripId={tripId} place={place} />

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
                                        {/* key: у каждого дня своё начало, форма создаётся заново при переходе. */}
                                        <ScheduleForm key={selectedDay.id} tripId={trip.id} day={selectedDay} />

                                        <ul className="stop-list">
                                            {places.map((place, index) => (
                                                <React.Fragment key={place.id}>
                                                    {/* Отрезок перед местом: от жилья (у первого) или от предыдущего места. */}
                                                    {place.segment && <SegmentRow segment={place.segment} />}
                                                    <PlaceRow
                                                        tripId={trip.id}
                                                        place={place}
                                                        number={index + 1}
                                                        isFirst={index === 0}
                                                        isLast={index === places.length - 1}
                                                        warning={timeWarning(place, places[index - 1])}
                                                        otherDays={otherDays}
                                                    />
                                                </React.Fragment>
                                            ))}
                                        </ul>
                                        <DayTotal day={selectedDay} />
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
