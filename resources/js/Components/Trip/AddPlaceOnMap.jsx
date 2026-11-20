import React, { useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Marker, Popup } from 'react-leaflet';

import MapClickHandler from '../Map/Logic/MapClickHandler';
import { Spinner } from '../Loader';
import { categorySvg, draftIcon } from '../../utils/mapIcons';
import { reverseGeocode } from '../../utils/geocoding';
import { showToast } from '../../utils/toast';

/**
 * Быстрое добавление места прямо на карте дня (вкладка «План»), без перехода на интерактивную карту.
 *
 * Клик по карте → маркер и маленькая плашка. Название и адрес берём из OpenStreetMap
 * так же, как на интерактивной карте (reverseGeocode). Кнопка «Добавить» ставит место
 * в конец выбранного дня через маршрут trips.days.places.store.
 *
 * pickedPlace — место, выбранное в поиске над картой (название и адрес уже известны, геокодировать не нужно):
 * для него открывается та же плашка. Новый объект на каждый выбор, чтобы повторный выбор того же места сработал.
 *
 * Рисуется внутри MapContainer (компонент DayMap), только у владельца поездки.
 */
export default function AddPlaceOnMap({ tripId, day, categories, pickedPlace = null }) {
    // Выбранная точка: { key, lat, lng, isLoading, name, address, categorySlug } или null.
    const [draft, setDraft] = useState(null);
    const [isSaving, setSaving] = useState(false);
    const geocodeRequest = useRef(null);
    // Последний день поездки — для даты выезда, если отель добавляют как жильё.
    const tripEndDate = usePage().props.trip?.endDate ?? null;
    const isLodging = draft?.categorySlug === 'hotel';
    const canSave = draft && !draft.isLoading && !isSaving && draft.name.trim() !== '';

    // Ушли со страницы или сменили день — недоделанный запрос к Nominatim не нужен.
    useEffect(() => () => geocodeRequest.current?.abort(), []);

    useEffect(() => {
        if (pickedPlace) {
            geocodeRequest.current?.abort();
            setDraft({ ...pickedPlace, key: `search:${pickedPlace.id}:${pickedPlace.pickedAt}`, isLoading: false });
        }
    }, [pickedPlace]);

    async function selectPoint({ lat, lng }) {
        geocodeRequest.current?.abort();
        geocodeRequest.current = new AbortController();

        // key отличает клики друг от друга: ответ на старый клик не должен перезаписать новую точку.
        const key = `${lat}:${lng}:${Date.now()}`;
        setDraft({ key, lat, lng, isLoading: true, name: '' });

        try {
            const place = await reverseGeocode(lat, lng, geocodeRequest.current.signal);
            setDraft((current) => (current?.key === key ? { ...current, ...place, lat, lng, isLoading: false } : current));
        } catch (error) {
            if (error.name !== 'AbortError') {
                // Nominatim не ответил — человек впишет название сам.
                setDraft((current) => (current?.key === key ? { ...current, isLoading: false } : current));
            }
        }
    }

    function close() {
        geocodeRequest.current?.abort();
        setDraft(null);
    }

    /**
     * Плашка закрылась. Если к этому моменту уже выбрана другая точка (например, из поиска),
     * старая плашка не должна сбросить новую — закрываем, только если это всё ещё та же точка.
     */
    function closeIfCurrent(key) {
        setDraft((current) => (current?.key === key ? null : current));
    }

    /**
     * «Здесь живу» — отель сохраняется не в план дня, а как проживание поездки.
     * Заезд — в этот день в 14:00, выезд — в последний день поездки в 12:00 (если он позже).
     * Сервер откроет вкладку «Проживание»: там можно поправить даты и добавить бронь.
     */
    function addAsStay() {
        const checkIn = day.date ? `${day.date}T14:00` : null;
        const checkOut = day.date && tripEndDate > day.date ? `${tripEndDate}T12:00` : null;

        setSaving(true);
        router.post(`/my-trips/${tripId}/accommodation`, {
            type: draft.lodgingType ?? 'hotel',
            name: draft.name.trim(),
            address: draft.address,
            latitude: draft.lat,
            longitude: draft.lng,
            check_in: checkIn,
            check_out: checkOut,
        }, {
            // В маленькой плашке негде показать ошибку под полем — показываем первую всплывашкой.
            onError: (errors) => showToast(Object.values(errors)[0]),
            onFinish: () => setSaving(false),
        });
    }

    function add() {
        // Сервер принимает категорию по id, а OSM даёт slug (cafe, museum…) — ищем id в списке категорий.
        const category = categories.find((item) => item.slug === draft.categorySlug)
            ?? categories.find((item) => item.slug === 'other');

        setSaving(true);
        // after_trip_place_id не передаём — место встанет последним в дне.
        router.post(`/my-trips/${tripId}/days/${day.id}/places`, {
            name: draft.name.trim(),
            category_id: category?.id,
            address: draft.address,
            latitude: draft.lat,
            longitude: draft.lng,
        }, {
            preserveScroll: true,
            onSuccess: close,
            onFinish: () => setSaving(false),
        });
    }

    return (
        <>
            <MapClickHandler onSelect={selectPoint} />

            {draft && (
                <>
                    <Marker position={[draft.lat, draft.lng]} icon={draftIcon(draft.lat, draft.lng, false)} interactive={false} />
                    {/* Плашка без маркера-«хозяина»: открывается сама; закрыли крестиком или кликом мимо — точку убираем. */}
                    <Popup
                        key={draft.key}
                        position={[draft.lat, draft.lng]}
                        offset={[0, -14]}
                        className="map-add-popup-wrap"
                        eventHandlers={{ remove: () => closeIfCurrent(draft.key) }}
                    >
                        <div className="map-add-popup">
                            <div className="map-add-popup__head">
                                {/* Иконка — наша строка-константа из mapIcons, не данные пользователя. */}
                                <span
                                    className="map-add-popup__icon"
                                    dangerouslySetInnerHTML={{ __html: categorySvg(draft.categorySlug ?? 'other', 16) }}
                                />
                                <span className="map-add-popup__eyebrow">
                                    {draft.kindLabel ? `Новое место · ${draft.kindLabel}` : 'Новое место'}
                                </span>
                            </div>

                            {draft.isLoading ? (
                                <span className="map-add-popup__loading"><Spinner /> Определяем место…</span>
                            ) : (
                                // Название можно поправить: поле выглядит как заголовок, рамка появляется при наведении.
                                <input
                                    className="map-add-popup__name"
                                    value={draft.name}
                                    maxLength={255}
                                    placeholder="Название места"
                                    aria-label="Название места"
                                    title="Название можно изменить"
                                    onChange={(e) => setDraft({ ...draft, name: e.target.value })}
                                />
                            )}

                            {draft.address && draft.address !== draft.name && (
                                <p className="map-add-popup__address">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                                        <circle cx="12" cy="10" r="3" />
                                    </svg>
                                    {draft.address}
                                </p>
                            )}

                            <div className="map-add-popup__actions">
                                <button
                                    type="button"
                                    className="btn btn--solid-dark map-add-popup__button"
                                    disabled={!canSave}
                                    onClick={add}
                                >
                                    {isSaving ? 'Добавляем…' : `+ Добавить в день ${day.number}`}
                                </button>
                                {/* Отель может быть и местом для посещения, и жильём — для жилья отдельная кнопка. */}
                                {isLodging && (
                                    <button type="button" className="btn btn--outline-dark map-add-popup__button" disabled={!canSave} onClick={addAsStay}>
                                        Здесь живу
                                    </button>
                                )}
                            </div>
                        </div>
                    </Popup>
                </>
            )}
        </>
    );
}
