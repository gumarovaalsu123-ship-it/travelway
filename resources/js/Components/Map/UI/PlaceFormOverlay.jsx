import React, { useEffect, useMemo, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { formatDayLabel } from '../../../utils/format';
import { fetchWikipediaSummary } from '../../../utils/geocoding';
import Loader from '../../Loader';
import { PlaceContacts, PlacePhoto, WikipediaCredit } from '../../PlaceInfo';

function ChevronIcon() {
    return (
        <span className="form-icon-suffix">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <polyline points="6 9 12 15 18 9" />
            </svg>
        </span>
    );
}

function categoryIdFor(categories, slug) {
    return (categories.find((category) => category.slug === slug) ?? categories.at(-1))?.id ?? '';
}

/**
 * Типы жилья, которые можно выбрать на карте. Ключи — Accommodation::Types на сервере
 * («У знакомых» здесь нет: это не объект на карте).
 */
const LODGING_TYPES = {
    hotel: 'Отель',
    hostel: 'Хостел',
    apartment: 'Квартира',
    other: 'Другое',
};

/**
 * Даты, которые форма жилья подставит сама: заезд в первый день поездки в 14:00, выезд в последний в 12:00.
 * У черновика без дат подставлять нечего. Сервер всё равно проверит даты (AccommodationRequest).
 *
 * @returns {{check_in: string, check_out: string}}
 */
function defaultStayDates(trip) {
    const dates = (trip?.days ?? []).map((day) => day.date).filter(Boolean).sort();

    if (dates.length === 0) {
        return { check_in: '', check_out: '' };
    }

    const first = dates[0];
    const last = dates.at(-1);

    return { check_in: `${first}T14:00`, check_out: last > first ? `${last}T12:00` : '' };
}

/**
 * Карточка «Добавить место». Работает в двух режимах:
 * - draft.place отсутствует — новая точка с карты (можно сохранить в план или в избранное);
 * - draft.place задан — уже сохранённое место добавляется в план поездки.
 * focusDayId — день, выбранный по умолчанию (пришли с вкладки «План»), иначе первый день первой поездки.
 */
export default function PlaceFormOverlay({ draft, categories, trips, focusDayId = null, onClose, onSaved }) {
    const existingPlace = draft.place ?? null;
    const focusTrip = trips.find((trip) => trip.days.some((day) => day.id === focusDayId));
    const [tripId, setTripId] = useState(focusTrip?.id ?? trips[0]?.id ?? '');
    const selectedTrip = useMemo(() => trips.find((trip) => trip.id === Number(tripId)), [trips, tripId]);

    const form = useForm({
        name: '',
        category_id: '',
        address: '',
        latitude: draft.lat,
        longitude: draft.lng,
        trip_day_id: focusTrip ? focusDayId : (trips[0]?.days[0]?.id ?? ''),
        start_time: '',
        image: null,
        opening_hours: null,
        website: null,
        phone: null,
        wiki_url: null,
        // Поля для режима «Здесь живу» — уходят на вкладку «Проживание» (TripAccommodationController@store).
        lodging_type: 'hotel',
        ...defaultStayDates(focusTrip ?? trips[0]),
    });
    const { data, setData, errors, processing, clearErrors } = form;
    const [isWikiLoading, setIsWikiLoading] = useState(false);

    // Отель на карте может быть и местом для посещения (красивое здание, ресторан внутри), и жильём.
    // purpose: 'visit' — обычное место в план дня, 'stay' — проживание в поездке.
    const [purpose, setPurpose] = useState('visit');
    const hotelCategoryId = categories.find((category) => category.slug === 'hotel')?.id;
    const isLodgingCategory = hotelCategoryId !== undefined && data.category_id === hotelCategoryId;
    const isStay = !existingPlace && trips.length > 0 && isLodgingCategory && purpose === 'stay';

    // Новая точка — заполняем форму заново.
    useEffect(() => {
        clearErrors();
        setData((current) => ({
            ...current,
            name: existingPlace?.name ?? '',
            category_id: existingPlace?.category?.id ?? '',
            address: existingPlace?.address ?? '',
            latitude: draft.lat,
            longitude: draft.lng,
            start_time: '',
            image: null,
            opening_hours: null,
            website: null,
            phone: null,
            wiki_url: null,
        }));
        setPurpose('visit');
    }, [draft.key]);

    // Результат обратного геокодирования приходит позже: подставляем его, не затирая то, что ввёл пользователь.
    useEffect(() => {
        if (existingPlace || draft.isLoading) {
            return;
        }

        setData((current) => ({
            ...current,
            name: current.name || draft.name || '',
            address: draft.address ?? '',
            category_id: current.category_id || categoryIdFor(categories, draft.categorySlug),
            opening_hours: draft.openingHours ?? null,
            website: draft.website ?? null,
            phone: draft.phone ?? null,
            wiki_url: draft.wikiUrl ?? null,
            lodging_type: draft.lodgingType ?? 'hotel',
        }));

        // Чаще всего отель на карте выбирают, чтобы в нём жить, — сразу включаем этот режим.
        if (draft.categorySlug === 'hotel') {
            setPurpose('stay');
        }
    }, [draft.key, draft.isLoading]);

    // Если у объекта есть статья в Википедии — подтягиваем фото и описание как предпросмотр.
    // При закрытии формы или клике в другую точку запрос отменяется, чтобы его ответ не попал в новую форму.
    useEffect(() => {
        if (existingPlace || draft.isLoading || !draft.wikipedia) {
            return undefined;
        }

        const controller = new AbortController();
        setIsWikiLoading(true);

        fetchWikipediaSummary(draft.wikipedia, controller.signal).then((summary) => {
            if (controller.signal.aborted) {
                return;
            }

            setIsWikiLoading(false);
            setData((current) => ({
                ...current,
                image: summary.image,
            }));
        });

        return () => {
            controller.abort();
            setIsWikiLoading(false);
        };
    }, [draft.key, draft.isLoading, draft.wikipedia]);

    const changeTrip = (value) => {
        setTripId(value);
        const trip = trips.find((item) => item.id === Number(value));
        setData((current) => ({ ...current, trip_day_id: trip?.days[0]?.id ?? '', ...defaultStayDates(trip) }));
    };

    const submit = (destination) => {
        // Проживание сохраняется не как место плана, а как жильё поездки.
        // После сохранения сервер откроет вкладку «Проживание» с новой карточкой.
        if (isStay) {
            form.transform((formData) => ({
                type: formData.lodging_type,
                name: formData.name,
                address: formData.address || null,
                latitude: formData.latitude,
                longitude: formData.longitude,
                check_in: formData.check_in || null,
                check_out: formData.check_out || null,
            }));
            form.post(`/my-trips/${tripId}/accommodation`);

            return;
        }

        const options = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onSaved(),
        };

        if (existingPlace) {
            form.transform(({ trip_day_id, start_time }) => ({ trip_day_id, start_time: start_time || null }));
            form.post(`/places/${existingPlace.id}/trip-places`, options);

            return;
        }

        form.transform((formData) => ({
            ...formData,
            destination,
            start_time: formData.start_time || null,
        }));
        form.post('/interactive-map/places', options);
    };

    const hasTrips = trips.length > 0;

    return (
        <aside className="map-overlay-form" aria-label="Добавить место">
            <div className="map-overlay__header">
                <h2 className="map-overlay__title">{existingPlace ? 'Добавить в поездку' : 'Добавить место'}</h2>
                <button type="button" className="btn-close" aria-label="Закрыть" onClick={onClose}>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                </button>
            </div>

            <div className="draft-status-box">
                {existingPlace ? (
                    <>
                        <span className="draft-status__title">{existingPlace.name}</span>
                        <p className="draft-status__desc">Выберите поездку и день, в план которых нужно добавить это место.</p>
                    </>
                ) : (
                    <>
                        <span className="draft-status__title">Пока не сохранено</span>
                        <p className="draft-status__desc">
                            {draft.isLoading && <Loader text="Определяем, что находится в этой точке…" />}
                            {!draft.isLoading && draft.geocodeFailed
                                && 'Не удалось узнать адрес этой точки — сервис OpenStreetMap не ответил. Заполните название сами.'}
                            {!draft.isLoading && !draft.geocodeFailed
                                && 'Вы выбрали точку на карте. Заполните данные, чтобы добавить её в избранное или маршрут путешествия.'}
                        </p>
                    </>
                )}
            </div>

            <form className="map-overlay__form" onSubmit={(event) => { event.preventDefault(); submit('trip'); }} noValidate>
                {!existingPlace && (
                    <>
                        <div className={`form-group ${errors.name ? 'has-error' : ''}`}>
                            <label className="form-label" htmlFor="place-name">Название</label>
                            <input
                                id="place-name"
                                type="text"
                                className="form-input form-input--filled"
                                value={data.name}
                                onChange={(event) => setData('name', event.target.value)}
                                placeholder="Введите название"
                                maxLength={255}
                            />
                            {errors.name && <span className="form-error">{errors.name}</span>}
                        </div>

                        <div className={`form-group ${errors.category_id ? 'has-error' : ''}`}>
                            <label className="form-label" htmlFor="place-category">Категория</label>
                            <div className="form-input-inner">
                                <select
                                    id="place-category"
                                    className="form-input form-input--filled form-select"
                                    value={data.category_id}
                                    onChange={(event) => setData('category_id', Number(event.target.value))}
                                >
                                    <option value="" disabled>Выберите категорию</option>
                                    {categories.map((category) => (
                                        <option key={category.id} value={category.id}>{category.name}</option>
                                    ))}
                                </select>
                                <ChevronIcon />
                            </div>
                            {errors.category_id && <span className="form-error">{errors.category_id}</span>}
                        </div>

                        {isLodgingCategory && hasTrips && (
                            <div className="form-group">
                                <span className="form-label">Что это для вас?</span>
                                <div className="place-purpose" role="radiogroup" aria-label="Что это для вас?">
                                    {[['stay', 'Здесь живу'], ['visit', 'Хочу посетить']].map(([value, label]) => (
                                        <button
                                            key={value}
                                            type="button"
                                            role="radio"
                                            aria-checked={purpose === value}
                                            className={`btn btn--sm ${purpose === value ? 'btn--solid-dark' : 'btn--outline-dark'}`}
                                            onClick={() => setPurpose(value)}
                                        >
                                            {label}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}

                        {isWikiLoading && <Loader variant="block" rows={1} text="Загружаем фото из Википедии…" />}

                        {data.image && (
                            <div className={`place-form-info ${errors.image ? 'has-error' : ''}`}>
                                <PlacePhoto image={data.image} alt={data.name} size="popup" />
                                <button type="button" className="btn btn--outline-dark btn--sm" onClick={() => setData('image', null)}>
                                    Убрать фото
                                </button>
                                {errors.image && <span className="form-error">{errors.image}</span>}
                            </div>
                        )}


                        <PlaceContacts openingHours={data.opening_hours} phone={data.phone} website={data.website} />
                        {['opening_hours', 'website', 'phone', 'wiki_url'].map((field) => (
                            errors[field] && <span key={field} className="form-error">{errors[field]}</span>
                        ))}
                        {data.image && <WikipediaCredit url={data.wiki_url} />}
                    </>
                )}

                {hasTrips ? (
                    <>
                        <div className="form-group">
                            <label className="form-label" htmlFor="place-trip">Путешествие</label>
                            <div className="form-input-inner">
                                <select
                                    id="place-trip"
                                    className="form-input form-input--filled form-select"
                                    value={tripId}
                                    onChange={(event) => changeTrip(event.target.value)}
                                >
                                    {trips.map((trip) => (
                                        <option key={trip.id} value={trip.id}>{trip.title}</option>
                                    ))}
                                </select>
                                <ChevronIcon />
                            </div>
                        </div>

                        {isStay ? (
                            <>
                                <div className={`form-group ${errors.type ? 'has-error' : ''}`}>
                                    <label className="form-label" htmlFor="place-lodging-type">Тип жилья</label>
                                    <div className="form-input-inner">
                                        <select
                                            id="place-lodging-type"
                                            className="form-input form-input--filled form-select"
                                            value={data.lodging_type}
                                            onChange={(event) => setData('lodging_type', event.target.value)}
                                        >
                                            {Object.entries(LODGING_TYPES).map(([value, label]) => (
                                                <option key={value} value={value}>{label}</option>
                                            ))}
                                        </select>
                                        <ChevronIcon />
                                    </div>
                                    {errors.type && <span className="form-error">{errors.type}</span>}
                                </div>

                                <div className={`form-group ${errors.check_in ? 'has-error' : ''}`}>
                                    <label className="form-label" htmlFor="place-check-in">Заезд</label>
                                    <input
                                        id="place-check-in"
                                        type="datetime-local"
                                        className="form-input form-input--filled"
                                        value={data.check_in}
                                        onChange={(event) => setData('check_in', event.target.value)}
                                    />
                                    {errors.check_in && <span className="form-error">{errors.check_in}</span>}
                                </div>

                                <div className={`form-group ${errors.check_out ? 'has-error' : ''}`}>
                                    <label className="form-label" htmlFor="place-check-out">Выезд</label>
                                    <input
                                        id="place-check-out"
                                        type="datetime-local"
                                        className="form-input form-input--filled"
                                        min={data.check_in || undefined}
                                        value={data.check_out}
                                        onChange={(event) => setData('check_out', event.target.value)}
                                    />
                                    {errors.check_out && <span className="form-error">{errors.check_out}</span>}
                                </div>

                                <p className="form-hint">Бронь, заметки и номер подтверждения можно добавить потом на вкладке «Проживание».</p>
                            </>
                        ) : (
                        <>
                        <div className={`form-group ${errors.trip_day_id ? 'has-error' : ''}`}>
                            <label className="form-label" htmlFor="place-day">День поездки</label>
                            <div className="form-input-inner">
                                <select
                                    id="place-day"
                                    className="form-input form-input--filled form-select"
                                    value={data.trip_day_id}
                                    onChange={(event) => setData('trip_day_id', Number(event.target.value))}
                                >
                                    {selectedTrip?.days.map((day) => (
                                        <option key={day.id} value={day.id}>{formatDayLabel(day.number, day.date)}</option>
                                    ))}
                                </select>
                                <ChevronIcon />
                            </div>
                            {errors.trip_day_id && <span className="form-error">{errors.trip_day_id}</span>}
                        </div>

                        <div className={`form-group ${errors.start_time ? 'has-error' : ''}`}>
                            <label className="form-label" htmlFor="place-time">Время (необязательно)</label>
                            <input
                                id="place-time"
                                type="time"
                                className="form-input form-input--filled"
                                value={data.start_time}
                                onChange={(event) => setData('start_time', event.target.value)}
                            />
                            {errors.start_time && <span className="form-error">{errors.start_time}</span>}
                        </div>
                        </>
                        )}
                    </>
                ) : (
                    <p className="form-hint">
                        У вас пока нет путешествий, поэтому место можно сохранить только в избранное.{' '}
                        <Link href="/my-trips/create">Создать путешествие</Link>
                    </p>
                )}

                <div className="map-overlay__actions mt-16">
                    {hasTrips && (
                        <button type="submit" className="btn btn--solid-dark btn--full" disabled={processing}>
                            {existingPlace ? 'Добавить в план' : (isStay ? 'Добавить проживание' : 'Сохранить место')}
                        </button>
                    )}
                    {!existingPlace && !isStay && (
                        <button
                            type="button"
                            className={`btn ${hasTrips ? 'btn--outline-dark' : 'btn--solid-dark'} btn--full`}
                            disabled={processing}
                            onClick={() => submit('favorite')}
                        >
                            В избранное
                        </button>
                    )}
                    <button type="button" className="btn btn--outline-dark btn--full" onClick={onClose}>Отмена</button>
                </div>

                {!existingPlace && <div className="map-overlay__footer-hint mt-8">Отмена удалит временный маркер.</div>}
            </form>
        </aside>
    );
}
