import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import FoundPlacesList from '../../Components/FoundPlacesList';
import Modal from '../../Components/Modal';
import TripLayout, { canEditTab } from '../../Components/TripLayout';
import DayMap from '../../Components/Trip/DayMap';
import TravelModeIcon from '../../Components/Trip/TravelModeIcon';
import { formatDateRange, formatDayLabel, plural } from '../../utils/format';
import { PLACE_SETS, findAlongSegment, findNearby } from '../../utils/overpass';
import { dayRoute } from '../../utils/dayRoute';
import { findArea, isInsideArea } from '../../utils/geocoding';
import { TRAVEL_MODES, formatMinutes, timeLabel, yandexRouteUrl } from '../../utils/travel';
import PhotoPicker from '../../Components/PhotoPicker';

const dayMonthFormatter = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long' });
const weekdayFormatter = new Intl.DateTimeFormat('ru-RU', { weekday: 'long' });
const toDate = (isoDate) => new Date(`${isoDate}T00:00:00`);

/**
 * Все запросы на этой вкладке меняют только план — прокрутку страницы сохраняем.
 */
const keepScroll = { preserveScroll: true };

const placesLabel = (count) => `${count} ${plural(count, ['место', 'места', 'мест'])}`;

/**
 * Отрезок пути: иконка способа и «12 мин». Время на общественном транспорте — только оценка, поэтому «≈».
 */
export function segmentLabel(segment) {
    return (
        <>
            <TravelModeIcon mode={segment.mode} size={14} />
            {`${segment.approximate ? '≈ ' : ''}${formatMinutes(segment.minutes)}`}
        </>
    );
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
 * Окна еды (концепция, раздел 3.6). Завтрак не считаем.
 */
const MEAL_WINDOWS = [
    { start: '12:00', end: '15:00', question: 'Пора пообедать?' },
    { start: '18:00', end: '21:00', question: 'Пора поужинать?' },
];

const FOOD_CATEGORIES = ['cafe', 'restaurant'];

/**
 * Подсказки «Пора пообедать?» по расписанию дня. Возвращает { id места: [окна] }:
 * подсказка встаёт у места, где человек будет в начале окна.
 *
 * Без рассчитанного расписания подсказок нет — неизвестно, где человек будет в 12:00.
 * Окно пропускаем, если в нём уже есть кофейня/ресторан или если день его не захватывает
 * (закончился до 12:00 или начался после 15:00). Время «ЧЧ:ММ» сравниваем как строки.
 */
function mealHints(places, isCalculated) {
    const timed = places.filter((place) => place.startTime && place.endTime);

    if (!isCalculated || timed.length === 0) {
        return {};
    }

    const dayStart = timed.reduce((min, place) => (place.startTime < min ? place.startTime : min), timed[0].startTime);
    const dayEnd = timed.reduce((max, place) => (place.endTime > max ? place.endTime : max), timed[0].endTime);
    const hints = {};

    for (const meal of MEAL_WINDOWS) {
        const hasFood = timed.some((place) => FOOD_CATEGORIES.includes(place.categorySlug)
            && place.startTime < meal.end && place.endTime > meal.start);

        if (hasFood || dayEnd <= meal.start || dayStart >= meal.end) {
            continue;
        }

        // Последнее место, начавшееся не позже начала окна; если день начался уже внутри окна — первое место.
        const before = timed.filter((place) => place.startTime <= meal.start);
        const anchor = before.length > 0
            ? before.reduce((last, place) => (place.startTime >= last.startTime ? place : last))
            : timed.find((place) => place.startTime === dayStart);

        hints[anchor.id] = [...(hints[anchor.id] ?? []), meal];
    }

    return hints;
}

/**
 * Окно с местами из OpenStreetMap для «Пора пообедать?» и «Что по пути?».
 * Найденное место можно поставить в план после afterPlace (если план можно менять — canEdit) или сохранить в избранное.
 */
function FoundPlacesDialog({ title, load, emptyText, tripId, day, afterPlace, categories, canEdit, onClose }) {
    const addToPlan = [
        {
            label: 'Добавить в план',
            savedLabel: 'В плане',
            url: `/my-trips/${tripId}/days/${day.id}/places`,
            extra: { after_trip_place_id: afterPlace.id },
            // Порядок мест в дне изменился — закрываем окно, чтобы человек увидел новый план.
            onSaved: onClose,
        },
    ];
    const actions = [
        ...(canEdit ? addToPlan : []),
        { label: 'В избранное', savedLabel: 'В избранном', url: '/interactive-map/places', extra: { destination: 'favorite' } },
    ];

    return (
        <div className="plan-dialog-backdrop" onClick={onClose}>
            <div className="plan-dialog" role="dialog" aria-modal="true" aria-label={title} onClick={(e) => e.stopPropagation()}>
                <div className="plan-dialog__header">
                    <h3 className="plan-dialog__title">{title}</h3>
                    <button type="button" className="btn-close" aria-label="Закрыть" onClick={onClose}>×</button>
                </div>
                <FoundPlacesList load={load} categories={categories} actions={actions} emptyText={emptyText} />
            </div>
        </div>
    );
}

/**
 * «Пора пообедать?» у места: в окно обеда/ужина в плане нет кофейни или ресторана.
 */
function MealHintRow({ meal, place, onShow }) {
    return (
        <li className="plan-hint">
            <span>🍽 {meal.question} С {meal.start} до {meal.end} в плане нет кафе или ресторана.</span>
            <button type="button" className="link-button plan-hint__button" onClick={onShow}>
                Где поесть рядом с «{place.name}»
            </button>
        </li>
    );
}

/**
 * Закрывает выпадающее меню по клику мимо него и по Esc. Возвращает ref — его вешают на обёртку меню.
 */
function useCloseOnOutside(isOpen, close) {
    const ref = useRef(null);

    useEffect(() => {
        if (!isOpen) {
            return undefined;
        }

        const closeOnOutsideClick = (event) => {
            if (!ref.current?.contains(event.target)) {
                close();
            }
        };
        const closeOnEscape = (event) => event.key === 'Escape' && close();

        document.addEventListener('mousedown', closeOnOutsideClick);
        document.addEventListener('keydown', closeOnEscape);

        return () => {
            document.removeEventListener('mousedown', closeOnOutsideClick);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, [isOpen, close]);

    return ref;
}

/** «12 мая, вторник» — или пустая строка, если у дня нет даты (черновик без дат). */
const dayDateLabel = (date) => (date ? `${dayMonthFormatter.format(toDate(date))}, ${weekdayFormatter.format(toDate(date))}` : '');

/** Первые места дня для выпадающего списка: «Эрмитаж · Кафе · ещё 2». */
const PREVIEW_PLACES = 2;

function placesPreview(day) {
    if (day.placesCount === 0) {
        return 'Мест пока нет';
    }

    const names = day.placeNames.slice(0, PREVIEW_PLACES).join(' · ');
    const rest = day.placesCount - PREVIEW_PLACES;

    return rest > 0 ? `${names} · ещё ${rest}` : names;
}

/**
 * Переключатель дней над планом: «‹ День 2 из 4 ▾ ›».
 * Стрелки — соседний день, клик по названию открывает список всех дней с датой, числом мест и первыми местами.
 * Номер дня — в адресе (?day=N): ссылкой можно поделиться, а после любого действия сервер
 * возвращает back() и открыт тот же день.
 *
 * Публичная страница (Trips/Public.jsx) передаёт onSelectDay: там все дни уже пришли с сервера,
 * поэтому день меняется на месте (кнопкой, а не ссылкой), и ссылки «Изменить даты поездки» нет.
 */
export function DaySwitcher({ trip, days, selectedDay, onSelectDay = null }) {
    const [isOpen, setOpen] = useState(false);
    const close = useCallback(() => setOpen(false), []);
    const switcherRef = useCloseOnOutside(isOpen, close);

    const index = days.findIndex((day) => day.id === selectedDay.id);
    const previous = days[index - 1];
    const next = days[index + 1];
    const dayUrl = (day) => `/my-trips/${trip.id}/plan?day=${day.number}`;
    const placesCount = selectedDay.places.length;

    /** Переход к дню: ссылка с ?day=N у автора или кнопка на публичной странице. */
    function dayLink(day, props, children) {
        if (onSelectDay) {
            return (
                <button type="button" {...props} onClick={() => { close(); onSelectDay(day); }}>{children}</button>
            );
        }

        return <Link href={dayUrl(day)} preserveScroll {...props} onClick={close}>{children}</Link>;
    }

    /** Стрелка к соседнему дню; у первого и последнего дня — неактивная заглушка того же размера. */
    function arrow(day, label, symbol) {
        return day ? (
            dayLink(day, { className: 'btn btn--outline-dark btn--icon-sm', 'aria-label': label, title: label }, symbol)
        ) : (
            <span className="btn btn--outline-dark btn--icon-sm day-switcher__arrow--off" aria-hidden="true">{symbol}</span>
        );
    }

    return (
        <header className="day-switcher" ref={switcherRef}>
            {arrow(previous, 'Предыдущий день', '‹')}

            <button
                type="button"
                className="day-switcher__current"
                aria-expanded={isOpen}
                aria-haspopup="menu"
                onClick={() => setOpen(!isOpen)}
            >
                <span className="day-switcher__title">
                    День {selectedDay.number} из {days.length}
                    <span className={`day-switcher__caret ${isOpen ? 'is-open' : ''}`} aria-hidden="true">▾</span>
                </span>
                <span className="day-switcher__meta">
                    {[dayDateLabel(selectedDay.date), placesCount > 0 ? placesLabel(placesCount) : 'Нет мест'].filter(Boolean).join(' · ')}
                </span>
            </button>

            {arrow(next, 'Следующий день', '›')}

            {isOpen && (
                <div className="day-switcher__menu" role="menu">
                    <div className="day-switcher__menu-head">{formatDateRange(trip.startDate, trip.endDate)}</div>
                    {days.map((day) => (
                        <React.Fragment key={day.id}>
                            {dayLink(day, { role: 'menuitem', className: `day-switcher__item ${day.id === selectedDay.id ? 'is-active' : ''}` }, (
                                <>
                                    <span className="day-switcher__item-number">{day.number}</span>
                                    <span className="day-switcher__item-text">
                                        <span className="day-switcher__item-date">{dayDateLabel(day.date) || `День ${day.number}`}</span>
                                        <span className="day-switcher__item-places">{placesPreview(day)}</span>
                                    </span>
                                    <span className="day-switcher__item-count">{day.placesCount || '—'}</span>
                                </>
                            ))}
                        </React.Fragment>
                    ))}
                    {/* Длина поездки меняется только в форме редактирования, поэтому кнопки «+ Добавить день» здесь нет. */}
                    {!onSelectDay && (
                        <Link href={`/my-trips/${trip.id}/edit`} className="day-switcher__edit" onClick={close}>Изменить даты поездки</Link>
                    )}
                </div>
            )}
        </header>
    );
}

/**
 * Строка между местами: как добраться от предыдущей точки и сколько это займёт.
 * Точный маршрут в Яндекс.Картах — одной кнопкой на весь день (FullRouteLinks под списком), а не у каждого отрезка.
 */
export function SegmentRow({ segment, onShowAlong }) {
    return (
        <li className="plan-segment">
            {segment && <span className="plan-segment__label" title={TRAVEL_MODES[segment.mode]?.label}>{segmentLabel(segment)}</span>}
            {/* «Что по пути?» — только между двумя местами (у отрезка от жилья кнопки нет). */}
            {onShowAlong && (
                <button type="button" className="link-button plan-segment__link" onClick={onShowAlong}>Что по пути?</button>
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
        // «Начало дня» прижато влево, кнопка — вправо (justify-content: space-between в app.css).
        <form className="plan-schedule-form" onSubmit={submit} noValidate>
            <div className="plan-schedule-form__start">
                <label className="form-label" htmlFor="day-start-time">Начало дня</label>
                <input
                    id="day-start-time"
                    type="time"
                    className="form-input form-input--filled plan-schedule-form__time"
                    value={form.data.start_time}
                    title={`Если пусто — ${day.scheduleStartTime}`}
                    onChange={(e) => form.setData('start_time', e.target.value)}
                />
            </div>
            <button type="submit" className="btn btn--solid-dark btn--sm" disabled={form.processing}>
                {form.processing ? 'Считаем…' : 'Рассчитать время'}
            </button>
            {form.errors.start_time && <span className="form-error">{form.errors.start_time}</span>}
            {form.errors.schedule && <span className="form-error">{form.errors.schedule}</span>}
        </form>
    );
}

/**
 * Весь маршрут дня одной ссылкой: жильё утром → все места по порядку → жильё вечером.
 * Способ выбирает человек — Яндекс.Карты построят маршрут через все точки.
 */
const FULL_ROUTE_MODES = ['walk', 'transit', 'car'];

function FullRouteLinks({ day }) {
    const { points } = dayRoute(day);

    if (points.length < 2) {
        return null;
    }

    return (
        <div className="plan-full-route">
            <span className="plan-full-route__title">Весь маршрут дня в Яндекс.Картах</span>
            <div className="plan-full-route__links">
                {FULL_ROUTE_MODES.map((mode) => (
                    <a key={mode} className="btn btn--outline-dark btn--sm" href={yandexRouteUrl(points, mode)} target="_blank" rel="noreferrer">
                        <TravelModeIcon mode={mode} />
                        {TRAVEL_MODES[mode].label}
                    </a>
                ))}
            </div>
        </div>
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
            <FullRouteLinks day={day} />
        </div>
    );
}

/**
 * «10:00» + 90 минут → «11:30». Так же окончание считает сервер (TripPlanController::update).
 */
function addMinutes(time, minutes) {
    const [hours, mins] = time.split(':').map(Number);
    const total = Math.min(hours * 60 + mins + minutes, 23 * 60 + 59);

    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}

/**
 * Окно «Изменить место»: день, время, сколько провести, способ добраться, билет (для «прочих» категорий) и заметка.
 * Всё — поля одной формы и уходит одним запросом по «Сохранить», поэтому перенос в другой день не теряет правки.
 * Окончания в форме нет: оно всегда «начало + время на месте».
 */
function EditPlaceModal({ tripId, place, otherDays, onClose }) {
    // У музея, достопримечательности и ресторана вопрос о билете стоит на карточке, у остальных — только здесь.
    const asksBookingInForm = !BOOKING_QUESTIONS[place.categorySlug];

    const form = useForm({
        trip_day_id: '',
        start_time: place.startTime ?? '',
        note: place.note ?? '',
        stay_minutes: place.stayMinutes ?? '',
        travel_mode: place.travelMode ?? '',
        time_locked: place.timeLocked,
        ...(asksBookingInForm ? { needs_booking: place.needsBooking } : {}),
    });

    // Время, поставленное вручную, сразу закрепляем — иначе следующий расчёт его передвинет.
    function changeStartTime(value) {
        form.setData((data) => ({ ...data, start_time: value, time_locked: value !== '' ? true : data.time_locked }));
    }

    function submit(e) {
        e.preventDefault();
        form.patch(`/my-trips/${tripId}/trip-places/${place.id}`, { ...keepScroll, onSuccess: onClose });
    }

    const stayMinutes = Number(form.data.stay_minutes) || place.defaultStayMinutes;

    return (
        <Modal title={`Изменить «${place.name}»`} onClose={onClose}>
            <PhotoPicker
                label="Фото места"
                imageUrl={place.image}
                uploadUrl={`/my-trips/${tripId}/trip-places/${place.id}/photo`}
                resetLabel="Убрать своё фото"
                resetQuestion="Убрать своё фото? Вместо него снова подберём фото из Википедии."
            />
            <form className="plan-place-form" onSubmit={submit} noValidate>
                {otherDays.length > 0 && (
                    <div className={`form-group ${form.errors.trip_day_id ? 'has-error' : ''}`}>
                        <label className="form-label" htmlFor={`day-${place.id}`}>День</label>
                        <select
                            id={`day-${place.id}`}
                            className="form-input form-input--filled"
                            value={form.data.trip_day_id}
                            onChange={(e) => form.setData('trip_day_id', e.target.value)}
                        >
                            <option value="">Оставить в этом дне</option>
                            {otherDays.map((day) => (
                                <option key={day.id} value={day.id}>Перенести: {formatDayLabel(day.number, day.date)}</option>
                            ))}
                        </select>
                        {form.errors.trip_day_id && <span className="form-error">{form.errors.trip_day_id}</span>}
                    </div>
                )}

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
                        {form.data.start_time && form.data.time_locked && (
                            <span className="form-hint">Время закреплено — расчёт его не сдвинет</span>
                        )}
                    </div>
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
                        {form.data.start_time && (
                            <span className="form-hint">Окончание — {addMinutes(form.data.start_time, stayMinutes)}</span>
                        )}
                    </div>
                </div>

                <label className="plan-place-form__check">
                    <input
                        type="checkbox"
                        checked={form.data.time_locked}
                        onChange={(e) => form.setData('time_locked', e.target.checked)}
                    />
                    🔒 Закрепить время
                </label>
                {form.errors.time_locked && <span className="form-error">{form.errors.time_locked}</span>}

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
                            <option key={key} value={key}>{mode.label}</option>
                        ))}
                    </select>
                    {form.errors.travel_mode && <span className="form-error">{form.errors.travel_mode}</span>}
                </div>

                {asksBookingInForm && (
                    <label className="plan-place-form__check">
                        <input
                            type="checkbox"
                            checked={form.data.needs_booking}
                            onChange={(e) => form.setData('needs_booking', e.target.checked)}
                        />
                        {DEFAULT_BOOKING_QUESTION.question}
                    </label>
                )}

                <div className={`form-group ${form.errors.note ? 'has-error' : ''}`}>
                    <label className="form-label" htmlFor={`note-${place.id}`}>Заметка</label>
                    <textarea
                        id={`note-${place.id}`}
                        className="form-input form-input--filled"
                        rows={2}
                        maxLength={500}
                        placeholder="Например, взять с собой воду"
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
        </Modal>
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
 * Вопрос на карточке — только у музея, достопримечательности и ресторана; у кафе, парка и прочих
 * он в окне «Изменить», а на карточке появляется, лишь когда билет уже отмечен как нужный.
 * Без canEdit (завершённая поездка) галочки только показывают, что было, а вопрос без отметки не нужен вовсе.
 */
function BookingMarks({ tripId, place, canEdit }) {
    const categoryLabels = BOOKING_QUESTIONS[place.categorySlug];
    const labels = categoryLabels ?? DEFAULT_BOOKING_QUESTION;
    const url = `/my-trips/${tripId}/trip-places/${place.id}/booking`;

    if (!place.needsBooking && (!canEdit || !categoryLabels)) {
        return null;
    }

    return (
        <div className="plan-stop__booking">
            <label className="plan-place-form__check">
                <input
                    type="checkbox"
                    checked={place.needsBooking}
                    disabled={!canEdit}
                    onChange={(e) => router.patch(url, { needs_booking: e.target.checked }, keepScroll)}
                />
                {labels.question}
            </label>
            {place.needsBooking && (
                <label className="plan-place-form__check">
                    <input
                        type="checkbox"
                        checked={place.isBooked}
                        disabled={!canEdit}
                        onChange={(e) => router.patch(url, { is_booked: e.target.checked }, keepScroll)}
                    />
                    {labels.done}
                </label>
            )}
            {canEdit && place.needsBooking && !place.isBooked && (
                <Link href={`/my-trips/${tripId}/advance`} className="plan-stop__booking-link">В делах «Заранее» →</Link>
            )}
        </div>
    );
}

/**
 * Кнопка «⋮» с выпадающим меню действий места. Меню закрывается по клику мимо него и по Esc.
 */
function PlaceMenu({ onEdit, onRemove }) {
    const [isOpen, setOpen] = useState(false);
    const close = useCallback(() => setOpen(false), []);
    const menuRef = useCloseOnOutside(isOpen, close);

    function choose(action) {
        setOpen(false);
        action();
    }

    return (
        <div className="plan-stop__menu" ref={menuRef}>
            <button
                type="button"
                className="btn btn--outline-dark btn--icon-sm"
                aria-label="Действия"
                aria-expanded={isOpen}
                onClick={() => setOpen(!isOpen)}
                title="Действия"
            >
                {/* Три точки вертикально — в стиле иконок админки (24×24, линии) */}
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" aria-hidden="true">
                    <path d="M12 5h.01M12 12h.01M12 19h.01" />
                </svg>
            </button>
            {isOpen && (
                <div className="plan-stop__menu-list" role="menu">
                    <button type="button" role="menuitem" className="plan-stop__menu-item" onClick={() => choose(onEdit)}>Изменить</button>
                    <button type="button" role="menuitem" className="plan-stop__menu-item plan-stop__menu-item--danger" onClick={() => choose(onRemove)}>
                        Убрать из плана
                    </button>
                </div>
            )}
        </div>
    );
}

/**
 * Окно-подтверждение «Убрать из плана?» вместо браузерного window.confirm.
 */
function RemovePlaceModal({ place, onClose }) {
    const [isProcessing, setProcessing] = useState(false);

    function remove() {
        // Существующий маршрут trip-places.destroy: место исчезнет из базы, только если оно больше нигде не нужно.
        setProcessing(true);
        router.delete(`/trip-places/${place.id}`, { ...keepScroll, onFinish: () => setProcessing(false) });
    }

    return (
        <Modal title="Убрать из плана?" onClose={onClose}>
            <p>Место «{place.name}» пропадёт из этого дня. В избранном оно останется, если вы его туда сохраняли.</p>
            <div className="plan-place-form__actions">
                <button type="button" className="btn btn--solid-dark btn--sm" disabled={isProcessing} onClick={remove}>Убрать</button>
                <button type="button" className="btn btn--outline-dark btn--sm" onClick={onClose}>Отмена</button>
            </div>
        </Modal>
    );
}

/**
 * Плашка слева в карточке места — одной ширины у всех мест, чтобы карточки стояли ровной сеткой.
 * Есть фото: пока оно грузится — блик-скелетон (как в Loader), номер в углу.
 * Нет фото или оно не загрузилось: пустая плашка с номером по центру.
 */
export function PlacePhotoBox({ image, alt, number, badgeClass }) {
    // 'loading' | 'loaded' | 'failed'
    const [status, setStatus] = useState('loading');
    const hasPhoto = image && status !== 'failed';

    return (
        <div className={`plan-stop__photo ${hasPhoto ? '' : 'plan-stop__photo--empty'} ${hasPhoto && status === 'loading' ? 'plan-stop__photo--loading' : ''}`}>
            {hasPhoto && (
                <img
                    src={image}
                    alt={alt}
                    loading="lazy"
                    className={status === 'loaded' ? 'is-loaded' : ''}
                    onLoad={() => setStatus('loaded')}
                    onError={() => setStatus('failed')}
                />
            )}
            {/* Номер совпадает с маркером на карте. */}
            <span className={`${badgeClass} ${hasPhoto ? 'plan-stop__photo-number' : ''}`}>{number}</span>
        </div>
    );
}

/**
 * Одно место в плане дня: фото (или номер, если фото нет), время, название, адрес, заметка;
 * справа — стрелки порядка и между ними меню «⋮» (изменить / убрать).
 * Клик по карточке (не по кнопке) выбирает место — его маркер подсвечивается на карте.
 */
function PlaceRow({ tripId, place, number, isFirst, isLast, warning, otherDays, isSelected, canEdit, onSelect }) {
    // Открытое окно: 'edit', 'remove' или null.
    const [openModal, setOpenModal] = useState(null);
    const closeModal = () => setOpenModal(null);

    function move(direction) {
        router.patch(`/my-trips/${tripId}/trip-places/${place.id}/move`, { direction }, keepScroll);
    }

    function select(e) {
        // Нажали на кнопку, поле, ссылку или внутри окна — это своё действие, место не выбираем.
        if (!e.target.closest('button, a, input, select, textarea, label, .modal-backdrop')) {
            onSelect(place.id);
        }
    }

    const badgeClass = `stop-number-badge ${isSelected ? 'stop-number-badge--active' : ''}`;

    return (
        // id нужен, чтобы по клику на маркер прокрутить список к этому месту.
        <li id={`plan-place-${place.id}`} className={`stop-item plan-stop ${isSelected ? 'plan-stop--selected' : ''}`} onClick={select}>
            <PlacePhotoBox image={place.image} alt={place.name} number={number} badgeClass={badgeClass} />

            <div className="stop-info">
                {place.startTime && (
                    <span className="stop-time plan-stop__time">
                        {timeLabel(place)}
                        {place.timeLocked && <span title="Время закреплено: расчёт его не двигает"> 🔒</span>}
                    </span>
                )}
                <span className="stop-name">{place.name}</span>
                {(place.address || place.category) && <span className="stop-desc">{place.address || place.category}</span>}
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
                <BookingMarks tripId={tripId} place={place} canEdit={canEdit} />
            </div>

            {/* Колонка справа: «выше» сверху, меню посередине, «ниже» снизу.
                У первого и последнего места вместо недостающей стрелки — пустое место того же размера, чтобы меню не съезжало. */}
            {/* Сколько провести на месте — крупно, по центру карточки по высоте, с подписью «на месте» под цифрой. */}
            <span
                className="plan-stop__stay"
                title={`На месте ${formatMinutes(place.stayMinutes ?? place.defaultStayMinutes)}${place.stayMinutes === null ? ' (по категории)' : ''}`}
            >
                {formatMinutes(place.stayMinutes ?? place.defaultStayMinutes)}
                <span className="plan-stop__stay-label">на месте</span>
            </span>

            {/* Порядок и меню «⋮» меняют план — только если его можно менять. */}
            {canEdit && <div className="plan-stop__side">
                {isFirst ? <span className="plan-stop__side-gap" /> : (
                    <button type="button" className="btn btn--outline-dark btn--icon-sm" aria-label="Выше" title="Выше" onClick={() => move('up')}>↑</button>
                )}
                <PlaceMenu onEdit={() => setOpenModal('edit')} onRemove={() => setOpenModal('remove')} />
                {isLast ? <span className="plan-stop__side-gap" /> : (
                    <button type="button" className="btn btn--outline-dark btn--icon-sm" aria-label="Ниже" title="Ниже" onClick={() => move('down')}>↓</button>
                )}
            </div>}

            {openModal === 'edit' && <EditPlaceModal tripId={tripId} place={place} otherDays={otherDays} onClose={closeModal} />}
            {openModal === 'remove' && <RemovePlaceModal place={place} onClose={closeModal} />}
        </li>
    );
}

/**
 * Избранное только из города (страны) поездки: Nominatim по названию даёт рамку города,
 * оставляем места, чьи координаты в неё попадают.
 * Пока рамку ищем — null (кнопку «Избранное» не показываем, чтобы число не прыгало).
 * Nominatim не ответил или не знает такого города — всё избранное, как раньше.
 */
function useTripFavorites(favorites, destination) {
    const [area, setArea] = useState({ status: 'loading', bounds: null });

    useEffect(() => {
        let isActive = true;
        setArea({ status: 'loading', bounds: null });

        findArea(destination)
            .then((city) => isActive && setArea({ status: 'done', bounds: city?.bounds ?? null }))
            .catch(() => isActive && setArea({ status: 'done', bounds: null }));

        return () => {
            isActive = false;
        };
    }, [destination]);

    if (area.status === 'loading') {
        return null;
    }

    return area.bounds ? favorites.filter((place) => isInsideArea(area.bounds, place.lat, place.lng)) : favorites;
}

/**
 * Карточка «Добавить место» под списком (во всю ширину, как карточки мест). Два способа, не уходя со страницы:
 * клик по карте справа (см. Components/Trip/AddPlaceOnMap.jsx) и избранное — список раскрывается прямо в карточке.
 * Место из избранного встаёт в конец дня через уже существующий маршрут trip-places.store.
 * favorites — уже отфильтрованные по городу поездки (useTripFavorites); null — ещё ищем город.
 * Места, которые уже стоят в этом дне, в списке не показываем.
 */
function AddPlaceCard({ favorites: tripFavorites, day }) {
    const [isFavoritesOpen, setFavoritesOpen] = useState(false);
    const [processingId, setProcessingId] = useState(null);
    const placeIdsInDay = new Set(day.places.map((place) => place.placeId));
    const favorites = (tripFavorites ?? []).filter((place) => !placeIdsInDay.has(place.id));

    function add(place) {
        setProcessingId(place.id);
        router.post(`/places/${place.id}/trip-places`, { trip_day_id: day.id }, {
            ...keepScroll,
            onFinish: () => setProcessingId(null),
        });
    }

    return (
        <div className="plan-add-card">
            <div className="plan-add-card__head">
                <div className="plan-add-card__text">
                    <span className="plan-add-card__title">Добавить место в день {day.number}</span>
                    <span className="plan-add-card__hint">
                        Нажмите на любую точку карты — появится плашка с кнопкой «Добавить».
                        {favorites.length > 0 && ' Или выберите из избранного.'}
                    </span>
                </div>
                {favorites.length > 0 && (
                    <button
                        type="button"
                        className="btn btn--outline-dark btn--sm"
                        aria-expanded={isFavoritesOpen}
                        onClick={() => setFavoritesOpen(!isFavoritesOpen)}
                    >
                        ★ Избранное ({favorites.length}) {isFavoritesOpen ? '▴' : '▾'}
                    </button>
                )}
            </div>

            {/* Список всегда в разметке: так его можно плавно раскрыть и свернуть (анимация в app.css).
                inert — у свёрнутого списка кнопки не ловят Tab и клики. */}
            <div className={`plan-add-card__drawer ${isFavoritesOpen ? 'is-open' : ''}`} inert={!isFavoritesOpen}>
                <ul className="plan-add-card__favorites">
                    {favorites.map((place) => (
                        <li key={place.id} className="plan-add-card__favorite">
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
                                {processingId === place.id ? 'Добавляем…' : 'Добавить'}
                            </button>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}

/**
 * Вкладка «План»: слева (на узком экране — под картой) список мест выбранного дня со всеми действиями,
 * справа — карта этого дня (Components/Trip/DayMap.jsx). Список и карта связаны через selectedPlaceId.
 */
export default function Plan({ trip, days, selectedDay, favorites, categories, focusLodging, mapCenter }) {
    // Открытое окно подсказки Overpass: { title, load, emptyText, afterPlace } или null.
    const [foundDialog, setFoundDialog] = useState(null);
    // Место, выбранное в списке или на карте (id), — подсвечено и там, и там.
    const [selectedPlaceId, setSelectedPlaceId] = useState(null);
    const tripFavorites = useTripFavorites(favorites, trip.destination);
    // Только просмотр (завершённая поездка): не добавить, не переставить, не рассчитать время.
    // Переключатель дней, «Что по пути?», «Где поесть» и ссылки на Яндекс — просмотр, они работают всегда.
    const canEdit = canEditTab(trip, 'plan');

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
    const meals = mealHints(places, selectedDay.isCalculated);

    /** Клик по маркеру: подсвечиваем место в списке и прокручиваем к нему. */
    function selectFromMap(placeId) {
        setSelectedPlaceId(placeId);
        document.getElementById(`plan-place-${placeId}`)?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }

    function showMealPlaces(place) {
        setFoundDialog({
            title: `Где поесть рядом с «${place.name}»`,
            load: (signal) => findNearby(place, PLACE_SETS.food, 500, signal),
            emptyText: 'В 500 м кафе и ресторанов не нашлось.',
            afterPlace: place,
        });
    }

    function showAlong(from, to) {
        setFoundDialog({
            title: `Что по пути: «${from.name}» → «${to.name}»`,
            load: (signal) => findAlongSegment(from, to, PLACE_SETS.sights, 200, 5, signal),
            emptyText: 'В 200 м от пути достопримечательностей не нашлось.',
            afterPlace: from,
        });
    }

    return (
        <TripLayout trip={trip} activeTab="plan">
            <main className="container trip-plan-main">
                <div className="trip-plan-grid">
                    {/* Переключатель — отдельная ячейка сетки над списком: на узком экране он встаёт выше карты (app.css). */}
                    <DaySwitcher trip={trip} days={days} selectedDay={selectedDay} />

                    <div className="trip-plan__main-col">
                        <div className="day-itinerary-card">
                            <div className="itinerary-block">
                                {places.length === 0 ? (
                                    canEdit ? <AddPlaceCard favorites={tripFavorites} day={selectedDay} /> : <p className="form-hint">В этот день мест в плане не было.</p>
                                ) : (
                                    <>
                                        {/* key: у каждого дня своё начало, форма создаётся заново при переходе. */}
                                        {canEdit && <ScheduleForm key={selectedDay.id} tripId={trip.id} day={selectedDay} />}

                                        <ul className="stop-list">
                                            {places.map((place, index) => (
                                                <React.Fragment key={place.id}>
                                                    {/* Отрезок перед местом: от жилья (у первого) или от предыдущего места. */}
                                                    {(place.segment || index > 0) && (
                                                        <SegmentRow
                                                            segment={place.segment}
                                                            onShowAlong={index > 0 ? () => showAlong(places[index - 1], place) : null}
                                                        />
                                                    )}
                                                    <PlaceRow
                                                        tripId={trip.id}
                                                        place={place}
                                                        number={index + 1}
                                                        isFirst={index === 0}
                                                        isLast={index === places.length - 1}
                                                        warning={timeWarning(place, places[index - 1])}
                                                        otherDays={otherDays}
                                                        isSelected={place.id === selectedPlaceId}
                                                        canEdit={canEdit}
                                                        onSelect={setSelectedPlaceId}
                                                    />
                                                    {meals[place.id]?.map((meal) => (
                                                        <MealHintRow key={meal.start} meal={meal} place={place} onShow={() => showMealPlaces(place)} />
                                                    ))}
                                                </React.Fragment>
                                            ))}
                                        </ul>
                                        {canEdit && <AddPlaceCard favorites={tripFavorites} day={selectedDay} />}
                                        <DayTotal day={selectedDay} />
                                    </>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Карта «липкая»: при прокрутке длинного списка остаётся на экране (см. .trip-plan__map-col в app.css).
                        key: при смене дня карта создаётся заново и вписывается в места нового дня. */}
                    <div className="trip-plan__map-col">
                        {focusLodging && <p className="form-hint">⌂ На карте отмечено жильё «{focusLodging.name}».</p>}
                        <DayMap
                            key={selectedDay.id}
                            tripId={trip.id}
                            day={selectedDay}
                            focusLodging={focusLodging}
                            selectedPlaceId={selectedPlaceId}
                            onMarkerClick={selectFromMap}
                            categories={categories}
                            canEdit={canEdit}
                            emptyCenter={mapCenter}
                            destination={trip.destination}
                        />
                    </div>
                </div>
            </main>

            {foundDialog && (
                <FoundPlacesDialog
                    {...foundDialog}
                    tripId={trip.id}
                    day={selectedDay}
                    categories={categories}
                    canEdit={canEdit}
                    onClose={() => setFoundDialog(null)}
                />
            )}
        </TripLayout>
    );
}
