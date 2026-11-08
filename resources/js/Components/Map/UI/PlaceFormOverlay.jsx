import React, { useEffect, useMemo, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { formatDayLabel } from '../../../utils/format';
import { fetchWikipediaSummary } from '../../../utils/geocoding';
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
 * Карточка «Добавить место». Работает в двух режимах:
 * - draft.place отсутствует — новая точка с карты (можно сохранить в план или в избранное);
 * - draft.place задан — уже сохранённое место добавляется в план поездки.
 */
export default function PlaceFormOverlay({ draft, categories, trips, onClose, onSaved }) {
    const existingPlace = draft.place ?? null;
    const [tripId, setTripId] = useState(trips[0]?.id ?? '');
    const selectedTrip = useMemo(() => trips.find((trip) => trip.id === Number(tripId)), [trips, tripId]);

    const form = useForm({
        name: '',
        category_id: '',
        address: '',
        latitude: draft.lat,
        longitude: draft.lng,
        trip_day_id: trips[0]?.days[0]?.id ?? '',
        start_time: '',
        image: null,
        description: '',
        opening_hours: null,
        website: null,
        phone: null,
        wiki_url: null,
    });
    const { data, setData, errors, processing, clearErrors } = form;
    const [isWikiLoading, setIsWikiLoading] = useState(false);

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
            description: '',
            opening_hours: null,
            website: null,
            phone: null,
            wiki_url: null,
        }));
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
        }));
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
                // Описание, которое пользователь уже успел ввести сам, не затираем.
                description: current.description || summary.description || '',
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
        setData('trip_day_id', trip?.days[0]?.id ?? '');
    };

    const submit = (destination) => {
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
                            {draft.isLoading
                                ? 'Определяем, что находится в этой точке…'
                                : 'Вы выбрали точку на карте. Заполните данные, чтобы добавить её в избранное или маршрут путешествия.'}
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
                                placeholder={draft.isLoading ? 'Ищем название…' : 'Введите название'}
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

                        {isWikiLoading && <p className="place-form-info__loading">Загружаем информацию о месте…</p>}

                        {data.image && (
                            <div className={`place-form-info ${errors.image ? 'has-error' : ''}`}>
                                <PlacePhoto image={data.image} alt={data.name} size="popup" />
                                <button type="button" className="btn btn--outline-dark btn--sm" onClick={() => setData('image', null)}>
                                    Убрать фото
                                </button>
                                {errors.image && <span className="form-error">{errors.image}</span>}
                            </div>
                        )}

                        <div className={`form-group ${errors.description ? 'has-error' : ''}`}>
                            <label className="form-label" htmlFor="place-description">Описание (необязательно)</label>
                            <textarea
                                id="place-description"
                                className="form-input form-input--filled"
                                rows={isWikiLoading ? 2 : 3}
                                value={data.description}
                                onChange={(event) => setData('description', event.target.value)}
                                placeholder={isWikiLoading ? 'Ищем описание…' : 'Чем интересно это место'}
                                maxLength={2000}
                            />
                            {errors.description && <span className="form-error">{errors.description}</span>}
                        </div>

                        <PlaceContacts openingHours={data.opening_hours} phone={data.phone} website={data.website} />
                        {['opening_hours', 'website', 'phone', 'wiki_url'].map((field) => (
                            errors[field] && <span key={field} className="form-error">{errors[field]}</span>
                        ))}
                        {(data.image || data.description) && <WikipediaCredit url={data.wiki_url} />}
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
                ) : (
                    <p className="form-hint">
                        У вас пока нет путешествий, поэтому место можно сохранить только в избранное.{' '}
                        <Link href="/my-trips/create">Создать путешествие</Link>
                    </p>
                )}

                <div className="map-overlay__actions mt-16">
                    {hasTrips && (
                        <button type="submit" className="btn btn--solid-dark btn--full" disabled={processing}>
                            {existingPlace ? 'Добавить в план' : 'Сохранить место'}
                        </button>
                    )}
                    {!existingPlace && (
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
