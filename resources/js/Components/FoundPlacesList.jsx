import React, { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { formatDistance } from '../utils/overpass';

/**
 * Найденное место → поля формы, которые ждёт сервер. Категорию сервер принимает по id,
 * а браузер знает только slug (cafe, museum…), поэтому ищем id в списке категорий со страницы.
 */
function placeFormData(place, categories) {
    const category = categories.find((item) => item.slug === place.categorySlug)
        ?? categories.find((item) => item.slug === 'other');

    return {
        name: place.name,
        category_id: category?.id,
        address: place.address,
        latitude: place.lat,
        longitude: place.lng,
    };
}

/**
 * Список мест из подсказок Overpass: загрузка, ошибка, пустой результат и кнопки сохранения.
 *
 * load — функция, которая возвращает Promise со списком мест (findNearby / findAlongSegment из utils/overpass).
 * Загрузка запускается один раз при появлении списка; чтобы искать заново, родитель меняет key.
 * actions — кнопки у каждого места: { label, url, extra, onSaved }. Сохранение — обычная форма Inertia,
 * сервер проверяет данные как при добавлении места с карты.
 */
export default function FoundPlacesList({ load, categories, actions, emptyText }) {
    const [places, setPlaces] = useState(null);
    const [loadError, setLoadError] = useState(null);
    const [savingId, setSavingId] = useState(null);
    const [savedIds, setSavedIds] = useState([]);
    const [saveError, setSaveError] = useState(null);

    useEffect(() => {
        // Окно могли закрыть до ответа сервиса — тогда результат уже никому не нужен.
        let isActive = true;

        load()
            .then((result) => isActive && setPlaces(result))
            .catch((error) => isActive && setLoadError(error.message));

        return () => {
            isActive = false;
        };
    }, []);

    function save(place, action) {
        setSavingId(place.id);
        setSaveError(null);

        router.post(action.url, { ...placeFormData(place, categories), ...action.extra }, {
            preserveScroll: true,
            onSuccess: () => {
                setSavedIds((ids) => [...ids, place.id]);
                action.onSaved?.();
            },
            onError: (errors) => setSaveError(Object.values(errors)[0]),
            onFinish: () => setSavingId(null),
        });
    }

    if (loadError) {
        return <p className="form-error">{loadError}</p>;
    }

    if (places === null) {
        return <p className="form-hint">Ищем в OpenStreetMap…</p>;
    }

    if (places.length === 0) {
        return <p className="form-hint">{emptyText}</p>;
    }

    return (
        <>
            {saveError && <p className="form-error">{saveError}</p>}
            <ul className="plan-dialog__list">
                {places.map((place) => (
                    <li key={place.id} className="plan-dialog__item found-place">
                        <div className="stop-info">
                            <span className="stop-name">{place.name}</span>
                            <span className="stop-desc">
                                {place.kindLabel} · {formatDistance(place.distance)}
                                {place.address && ` · ${place.address}`}
                            </span>
                        </div>
                        <div className="found-place__actions">
                            {savedIds.includes(place.id) ? (
                                <span className="plan-stop__free">Сохранено</span>
                            ) : actions.map((action) => (
                                <button
                                    key={action.label}
                                    type="button"
                                    className="btn btn--outline-dark btn--sm"
                                    disabled={savingId !== null}
                                    onClick={() => save(place, action)}
                                >
                                    {action.label}
                                </button>
                            ))}
                        </div>
                    </li>
                ))}
            </ul>
            <p className="form-hint">Данные OpenStreetMap бывают неполными — проверьте место перед поездкой.</p>
        </>
    );
}
