import React, { useRef, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import Checkbox from '../../Components/Checkbox';
import FoundPlacesList from '../../Components/FoundPlacesList';
import TripLayout from '../../Components/TripLayout';
import { formatDuration, plural } from '../../utils/format';
import { searchPlaces } from '../../utils/geocoding';
import { PLACE_SETS, findNearby } from '../../utils/overpass';

/**
 * Подписи типов жилья. Ключи совпадают с Accommodation::Types на сервере.
 */
const TYPE_LABELS = {
    hotel: 'Отель',
    apartment: 'Квартира',
    hostel: 'Хостел',
    friends: 'У знакомых',
    other: 'Другое',
};

const fullDateFormatter = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' });
const dayMonthFormatter = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long' });

// Сервер присылает дату-время как «2026-06-15T15:00»: дата — до «T», время — после.
const datePart = (dateTime) => dateTime.slice(0, 10);
const timePart = (dateTime) => dateTime.slice(11, 16);
const formatFullDate = (isoDate) => fullDateFormatter.format(new Date(`${isoDate}T00:00:00`));
const formatDayMonth = (isoDate) => dayMonthFormatter.format(new Date(`${isoDate}T00:00:00`));

const nightsLabel = (count) => `${count} ${plural(count, ['ночь', 'ночи', 'ночей'])}`;

/**
 * «Ночь 19–20 июня» или «Ночи 17–19 июня» для промежутка ночей без жилья.
 */
function missingRangeLabel({ from, to }) {
    const isOneNight = new Date(to) - new Date(from) === 86_400_000;
    const sameMonth = from.slice(0, 7) === to.slice(0, 7);
    const fromLabel = sameMonth ? String(Number(from.slice(8, 10))) : formatDayMonth(from);

    return `${isOneNight ? 'Ночь' : 'Ночи'} ${fromLabel}–${formatDayMonth(to)}`;
}

function CheckBlock({ label, dateTime, hint }) {
    return (
        <div className="check-block">
            <span className="check-label">{label}</span>
            <span className="check-date">{dateTime ? formatFullDate(datePart(dateTime)) : 'Не указан'}</span>
            {hint && <span className="check-hint">{hint}</span>}
        </div>
    );
}

/**
 * Метка «Забронировано» / «Не забронировано». У знакомых бронировать нечего.
 */
function BookingStatus({ accommodation }) {
    if (!accommodation.needsBooking) {
        return <span className="booking-status booking-status--none">Бронь не нужна</span>;
    }

    return accommodation.isBooked
        ? <span className="booking-status booking-status--done">Забронировано</span>
        : <span className="booking-status booking-status--todo">Не забронировано</span>;
}

/**
 * Вкладки «Что рядом с жильём?»: какой набор мест OpenStreetMap ищем в 500 м.
 */
const NEARBY_TABS = {
    groceries: { label: 'Продукты', placeSet: PLACE_SETS.groceries, empty: 'Продуктовых магазинов в 500 м не нашлось.' },
    pharmacies: { label: 'Аптеки', placeSet: PLACE_SETS.pharmacies, empty: 'Аптек в 500 м не нашлось.' },
    food: { label: 'Кафе', placeSet: PLACE_SETS.food, empty: 'Кафе и ресторанов в 500 м не нашлось.' },
};

/**
 * «Что рядом с жильём?»: магазины, аптеки и кафе в 500 м по данным OpenStreetMap (Overpass API).
 * Найденное место можно сохранить в избранное или сразу добавить в конец плана выбранного дня.
 *
 * Поездку и её дни берём из props страницы (usePage), чтобы не передавать их через карточку жилья.
 * По умолчанию выбран день заезда (checkIn), если он есть среди дней поездки, иначе первый день.
 */
function NearbyPanel({ point, categories, checkIn }) {
    const { trip, days } = usePage().props;
    const checkInDay = checkIn && days.find((day) => day.date === datePart(checkIn));
    const [tab, setTab] = useState('groceries');
    const [dayId, setDayId] = useState(checkInDay?.id ?? days[0]?.id ?? null);
    const current = NEARBY_TABS[tab];
    const selectedDay = days.find((day) => day.id === dayId);

    const actions = [
        { label: 'В избранное', savedLabel: 'В избранном', url: '/interactive-map/places', extra: { destination: 'favorite' } },
    ];

    if (selectedDay) {
        // Без after_trip_place_id сервер ставит место в конец дня (TripPlanController::storePlace).
        actions.push({
            label: `В план дня ${selectedDay.number}`,
            savedLabel: `В плане дня ${selectedDay.number}`,
            url: `/my-trips/${trip.id}/days/${selectedDay.id}/places`,
            extra: {},
        });
    }

    return (
        <div className="acc-nearby">
            <div className="acc-nearby__tabs" role="tablist">
                {Object.entries(NEARBY_TABS).map(([key, item]) => (
                    <button
                        key={key}
                        type="button"
                        role="tab"
                        aria-selected={key === tab}
                        className={`btn btn--sm ${key === tab ? 'btn--solid-dark' : 'btn--outline-dark'}`}
                        onClick={() => setTab(key)}
                    >
                        {item.label}
                    </button>
                ))}
            </div>
            {days.length > 0 && (
                <label className="acc-nearby__day">
                    Добавлять в план:
                    <select className="form-input form-select" value={dayId} onChange={(event) => setDayId(Number(event.target.value))}>
                        {days.map((day) => (
                            <option key={day.id} value={day.id}>
                                День {day.number}{day.date ? ` — ${formatDayMonth(day.date)}` : ''}
                            </option>
                        ))}
                    </select>
                </label>
            )}
            {/* key: при смене вкладки список создаётся заново и делает новый запрос. */}
            <FoundPlacesList
                key={tab}
                load={(signal) => findNearby(point, current.placeSet, 500, signal)}
                categories={categories}
                actions={actions}
                emptyText={current.empty}
            />
        </div>
    );
}

function AccommodationCard({ accommodation, categories, onEdit, onDelete }) {
    const { checkIn, checkOut, nights, latitude, longitude } = accommodation;
    const [isNearbyOpen, setNearbyOpen] = useState(false);
    const checkOutHint = [checkOut && `До ${timePart(checkOut)}`, nights !== null && nightsLabel(nights)].filter(Boolean).join(' · ');

    return (
        <article className="acc-card">
            <div className="acc-card__image" style={{ backgroundImage: "url('/images/accommodation.png')" }} />

            <div className="acc-card__body">
                <header className="acc-header">
                    <div className="acc-title-group">
                        <h3 className="acc-title">{accommodation.name}</h3>
                        {accommodation.address && <p className="acc-address">{accommodation.address}</p>}
                    </div>
                    {nights !== null && <span className="acc-badge">{nightsLabel(nights)}</span>}
                </header>

                <div className="acc-card__statuses">
                    <span className="booking-status booking-status--none">{TYPE_LABELS[accommodation.type]}</span>
                    <BookingStatus accommodation={accommodation} />
                </div>

                <div className="check-blocks-row">
                    <CheckBlock label="Заезд" dateTime={checkIn} hint={checkIn && `С ${timePart(checkIn)}`} />
                    <CheckBlock label="Выезд" dateTime={checkOut} hint={checkOutHint} />
                </div>

                {accommodation.bookingInfo && (
                    <div className="acc-details-col">
                        <h4 className="acc-detail-title">Бронирование</h4>
                        <p className="acc-detail-desc acc-text">{accommodation.bookingInfo}</p>
                    </div>
                )}

                {accommodation.note && (
                    <>
                        <hr className="itinerary-divider acc-divider" />
                        <p className="acc-hint acc-text">{accommodation.note}</p>
                    </>
                )}

                <div className="acc-actions">
                    <button type="button" className="btn btn--solid-dark" onClick={onEdit}>Изменить</button>
                    {latitude !== null && (
                        // Отдельной карты на вкладке нет — открываем точку на OpenStreetMap в новой вкладке.
                        <a
                            href={`https://www.openstreetmap.org/?mlat=${latitude}&mlon=${longitude}#map=17/${latitude}/${longitude}`}
                            target="_blank"
                            rel="noreferrer"
                            className="btn btn--outline-dark"
                        >
                            Показать на карте
                        </a>
                    )}
                    {latitude !== null && (
                        <button type="button" className="btn btn--outline-dark" onClick={() => setNearbyOpen(!isNearbyOpen)}>
                            {isNearbyOpen ? 'Скрыть, что рядом' : 'Что рядом?'}
                        </button>
                    )}
                    <button type="button" className="btn btn--outline-dark acc-actions__delete" onClick={onDelete}>Удалить</button>
                </div>

                {isNearbyOpen && latitude !== null && (
                    <NearbyPanel point={{ lat: latitude, lng: longitude }} categories={categories} checkIn={checkIn} />
                )}
            </div>
        </article>
    );
}

function Field({ id, label, required = false, hint, error, children }) {
    return (
        <div className={`form-group ${error ? 'has-error' : ''}`}>
            <label htmlFor={id} className="form-label">
                {label} {required && <span className="required">*</span>}
            </label>
            {children}
            {(error || hint) && <span className="form-hint">{error ?? hint}</span>}
        </div>
    );
}

/**
 * Поиск адреса через Nominatim: по выбранному результату подставляем адрес и координаты.
 */
function AddressSearch({ query, onSelect }) {
    const [results, setResults] = useState(null);
    const [status, setStatus] = useState('idle');
    const abortController = useRef(null);

    const search = async () => {
        const text = query.trim();
        if (text.length < 2) {
            return;
        }

        abortController.current?.abort();
        abortController.current = new AbortController();
        setStatus('loading');

        try {
            setResults(await searchPlaces(text, null, abortController.current.signal));
            setStatus('idle');
        } catch (error) {
            if (error.name !== 'AbortError') {
                setResults([]);
                setStatus('error');
            }
        }
    };

    return (
        <>
            <button type="button" className="link-button acc-form__search" onClick={search} disabled={status === 'loading'}>
                {status === 'loading' ? 'Ищем…' : 'Найти адрес на карте'}
            </button>

            {status === 'error' && <span className="form-hint">Поиск сейчас недоступен. Попробуйте позже.</span>}

            {status === 'idle' && results && (
                <ul className="map-search__results">
                    {results.length === 0 && <li className="map-search__empty">Ничего не нашлось</li>}
                    {results.map((result) => (
                        <li key={result.id}>
                            <button
                                type="button"
                                className="map-search__result"
                                onClick={() => {
                                    setResults(null);
                                    onSelect(result);
                                }}
                            >
                                <span className="map-search__result-name">{result.name}</span>
                                <span className="map-search__result-address">{result.fullAddress}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}

/**
 * Форма добавления (accommodation = null) или редактирования жилья.
 */
function AccommodationForm({ tripId, accommodation, types, typesWithoutBooking, onClose }) {
    const isEditing = Boolean(accommodation);

    const { data, setData, post, put, processing, errors } = useForm({
        type: accommodation?.type ?? 'hotel',
        name: accommodation?.name ?? '',
        address: accommodation?.address ?? '',
        check_in: accommodation?.checkIn ?? '',
        check_out: accommodation?.checkOut ?? '',
        booking_info: accommodation?.bookingInfo ?? '',
        is_booked: accommodation?.isBooked ?? false,
        note: accommodation?.note ?? '',
        latitude: accommodation?.latitude ?? null,
        longitude: accommodation?.longitude ?? null,
    });

    // У знакомых бронировать нечего, поэтому галочку «Забронировано» не показываем.
    const needsBooking = !typesWithoutBooking.includes(data.type);

    const submit = (event) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: onClose };

        if (isEditing) {
            put(`/my-trips/${tripId}/accommodation/${accommodation.id}`, options);
        } else {
            post(`/my-trips/${tripId}/accommodation`, options);
        }
    };

    // Адрес изменили руками — старые координаты ему больше не соответствуют, сбрасываем их.
    const changeAddress = (value) => setData((current) => ({ ...current, address: value, latitude: null, longitude: null }));

    const chooseAddress = (place) => setData((current) => ({
        ...current,
        name: current.name || place.name,
        address: place.address ?? place.fullAddress,
        latitude: place.lat,
        longitude: place.lng,
    }));

    return (
        <section className="form-card acc-form">
            <header className="form-card__header">
                <h3 className="acc-title">{isEditing ? 'Изменить проживание' : 'Новое проживание'}</h3>
                <p className="form-card__subtitle">Обязательно только название — остальное можно дописать позже.</p>
            </header>

            <form className="create-trip-form" onSubmit={submit} noValidate>
                <Field id="acc-type" label="Тип жилья" required error={errors.type}>
                    <select
                        id="acc-type"
                        className="form-input form-input--filled form-select"
                        value={data.type}
                        onChange={(event) => setData('type', event.target.value)}
                    >
                        {types.map((type) => (
                            <option key={type} value={type}>{TYPE_LABELS[type]}</option>
                        ))}
                    </select>
                </Field>

                <Field id="acc-name" label="Название" required error={errors.name}>
                    <input
                        id="acc-name"
                        type="text"
                        className="form-input form-input--filled"
                        placeholder={data.type === 'friends' ? 'У кого остановитесь, например «У тёти Веры»' : 'Название отеля, квартиры или хостела'}
                        value={data.name}
                        onChange={(event) => setData('name', event.target.value)}
                        maxLength={150}
                    />
                </Field>

                <Field
                    id="acc-address"
                    label="Адрес"
                    error={errors.address ?? errors.latitude}
                    hint={data.latitude !== null ? 'Адрес найден на карте' : null}
                >
                    <input
                        id="acc-address"
                        type="text"
                        className="form-input form-input--filled"
                        placeholder="Улица, дом, город"
                        value={data.address}
                        onChange={(event) => changeAddress(event.target.value)}
                        maxLength={255}
                    />
                    <AddressSearch query={data.address || data.name} onSelect={chooseAddress} />
                </Field>

                <div className="form-row">
                    <Field id="acc-check-in" label="Заезд" error={errors.check_in}>
                        <input
                            id="acc-check-in"
                            type="datetime-local"
                            className="form-input form-input--filled"
                            value={data.check_in}
                            onChange={(event) => setData('check_in', event.target.value)}
                        />
                    </Field>
                    <Field id="acc-check-out" label="Выезд" error={errors.check_out}>
                        <input
                            id="acc-check-out"
                            type="datetime-local"
                            className="form-input form-input--filled"
                            min={data.check_in || undefined}
                            value={data.check_out}
                            onChange={(event) => setData('check_out', event.target.value)}
                        />
                    </Field>
                </div>

                <Field id="acc-booking" label="Данные брони" error={errors.booking_info}>
                    <textarea
                        id="acc-booking"
                        className="form-input form-input--filled"
                        rows={2}
                        placeholder="Номер брони, тип номера, стоимость, оплата"
                        value={data.booking_info}
                        onChange={(event) => setData('booking_info', event.target.value)}
                        maxLength={2000}
                    />
                </Field>

                {needsBooking && (
                    <div className="form-group">
                        <Checkbox
                            id="acc-is-booked"
                            label="Забронировано"
                            checked={data.is_booked}
                            onChange={(checked) => setData('is_booked', checked)}
                        />
                    </div>
                )}

                <Field id="acc-note" label="Заметка" error={errors.note}>
                    <textarea
                        id="acc-note"
                        className="form-input form-input--filled"
                        rows={2}
                        placeholder="Условия отмены, что взять с собой при заселении"
                        value={data.note}
                        onChange={(event) => setData('note', event.target.value)}
                        maxLength={2000}
                    />
                </Field>

                <div className="form-actions">
                    <button type="button" className="btn btn--outline-dark" onClick={onClose}>Отмена</button>
                    <button type="submit" className="btn btn--solid-dark" disabled={processing}>
                        {isEditing ? 'Сохранить изменения' : 'Добавить проживание'}
                    </button>
                </div>
            </form>
        </section>
    );
}

/**
 * Страница поездки, вкладка «Проживание»: где живём, даты заезда/выезда и ночи, на которые жильё ещё не найдено.
 */
export default function TripAccommodation({ trip, accommodations, nights, types, typesWithoutBooking, categories }) {
    // Какая форма открыта: null — никакая, 'new' — добавление, число — id редактируемого жилья.
    const [openForm, setOpenForm] = useState(null);

    const destroy = (accommodation) => {
        if (window.confirm(`Удалить проживание «${accommodation.name}»?`)) {
            router.delete(`/my-trips/${trip.id}/accommodation/${accommodation.id}`, { preserveScroll: true });
        }
    };

    const addButton = (className) => (
        <button type="button" className={className} onClick={() => setOpenForm('new')}>+ Добавить проживание</button>
    );

    const subtitle = nights.covered === null || nights.total === 0
        ?'Бронирование, адрес и важные детали заселения.'
        : `Бронирование, адрес и важные детали заселения. Добавлены ${nights.covered} из ${nightsLabel(nights.total)}.`;

    return (
        <TripLayout trip={trip} activeTab="accommodation">
            <main className="container trip-accommodation-main mb-40">
                <header className="section-header section-header--spaced">
                    <div className="section-header__text">
                        <h2 className="section-title">Проживание</h2>
                        <p className="section-subtitle section-subtitle--light">{subtitle}</p>
                    </div>
                    {openForm !== 'new' && addButton('btn btn--solid-dark')}
                </header>

                <div className="trip-plan-grid mt-16">
                    <div className="accommodation-list">
                        {openForm === 'new' && (
                            <AccommodationForm
                                tripId={trip.id}
                                accommodation={null}
                                types={types}
                                typesWithoutBooking={typesWithoutBooking}
                                onClose={() => setOpenForm(null)}
                            />
                        )}

                        {accommodations.length === 0 && openForm !== 'new' && (
                            <section className="trips-empty">
                                <h3 className="trips-empty__title">Жильё ещё не добавлено</h3>
                                <p className="trips-empty__text">
                                    Сохраните здесь отель или апартаменты: адрес, даты заезда и выезда, номер брони.
                                </p>
                                {addButton('btn btn--solid')}
                            </section>
                        )}

                        {accommodations.map((accommodation) => (openForm === accommodation.id ? (
                            <AccommodationForm
                                key={accommodation.id}
                                tripId={trip.id}
                                accommodation={accommodation}
                                types={types}
                                typesWithoutBooking={typesWithoutBooking}
                                onClose={() => setOpenForm(null)}
                            />
                        ) : (
                            <AccommodationCard
                                key={accommodation.id}
                                accommodation={accommodation}
                                categories={categories}
                                onEdit={() => setOpenForm(accommodation.id)}
                                onDelete={() => destroy(accommodation)}
                            />
                        )))}

                        {/* Карточки «ночь без жилья» показываем, только когда уже что-то добавлено. */}
                        {accommodations.length > 0 && nights.missing.map((range) => (
                            <article key={range.from} className="missing-night-card">
                                <h3 className="missing-night__title">{missingRangeLabel(range)}: жильё не добавлено</h3>
                                <p className="missing-night__desc">
                                    Добавьте ещё одну бронь или заметку о ночном переезде, чтобы закрыть эти даты.
                                </p>
                                {addButton('btn btn--outline-dark missing-night__btn')}
                            </article>
                        ))}
                    </div>

                    <div className="trip-plan__side-col">
                        <aside className="trip-details-card">
                            <h3 className="trip-details__title">Детали поездки</h3>

                            <div className="trip-details__list">
                                <div className="detail-item">
                                    <span className="detail-label">Продолжительность</span>
                                    <span className="detail-value">{formatDuration(trip.daysCount)}</span>
                                </div>
                                <div className="detail-item">
                                    <span className="detail-label">Ночи с жильём</span>
                                    <span className="detail-value">
                                        {nights.covered === null ? 'Даты поездки не выбраны' : `${nights.covered} из ${nights.total}`}
                                    </span>
                                </div>
                                <div className="detail-item">
                                    <span className="detail-label">Мест проживания</span>
                                    <span className="detail-value">{accommodations.length}</span>
                                </div>
                            </div>
                        </aside>
                    </div>
                </div>
            </main>
        </TripLayout>
    );
}
