import React, { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { Marker, Popup } from 'react-leaflet';

import MapClickHandler from '../Map/Logic/MapClickHandler';
import { Spinner } from '../Loader';
import { draftIcon } from '../../utils/mapIcons';
import { reverseGeocode } from '../../utils/geocoding';

/**
 * Быстрое добавление места прямо на карте дня (вкладка «План»), без перехода на интерактивную карту.
 *
 * Клик по карте → маркер и маленькая плашка. Название и адрес берём из OpenStreetMap
 * так же, как на интерактивной карте (reverseGeocode). Кнопка «Добавить» ставит место
 * в конец выбранного дня через маршрут trips.days.places.store.
 *
 * Рисуется внутри MapContainer (компонент DayMap), только у владельца поездки.
 */
export default function AddPlaceOnMap({ tripId, day, categories }) {
    // Выбранная точка: { key, lat, lng, isLoading, name, address, categorySlug } или null.
    const [draft, setDraft] = useState(null);
    const [isSaving, setSaving] = useState(false);
    const geocodeRequest = useRef(null);

    // Ушли со страницы или сменили день — недоделанный запрос к Nominatim не нужен.
    useEffect(() => () => geocodeRequest.current?.abort(), []);

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
                    <Marker position={[draft.lat, draft.lng]} icon={draftIcon(draft.lat, draft.lng)} interactive={false} />
                    {/* Плашка без маркера-«хозяина»: открывается сама; закрыли крестиком или кликом мимо — точку убираем. */}
                    <Popup
                        key={draft.key}
                        position={[draft.lat, draft.lng]}
                        offset={[0, -14]}
                        eventHandlers={{ remove: close }}
                    >
                        <div className="map-add-popup">
                            {draft.isLoading ? (
                                <span className="map-add-popup__loading"><Spinner /> Определяем место…</span>
                            ) : (
                                <input
                                    className="form-input form-input--filled map-add-popup__name"
                                    value={draft.name}
                                    maxLength={255}
                                    placeholder="Название места"
                                    aria-label="Название места"
                                    onChange={(e) => setDraft({ ...draft, name: e.target.value })}
                                />
                            )}
                            {draft.address && draft.address !== draft.name && <p className="map-add-popup__address">{draft.address}</p>}
                            <button
                                type="button"
                                className="btn btn--solid-dark btn--sm"
                                disabled={draft.isLoading || isSaving || draft.name.trim() === ''}
                                onClick={add}
                            >
                                {isSaving ? 'Добавляем…' : `+ Добавить в день ${day.number}`}
                            </button>
                        </div>
                    </Popup>
                </>
            )}
        </>
    );
}
