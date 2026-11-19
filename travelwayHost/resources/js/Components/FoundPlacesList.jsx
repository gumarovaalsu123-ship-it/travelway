import React, { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { formatDistance } from '../utils/overpass';
import Loader, { Spinner } from './Loader';

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
 * Список мест из подсказок Overpass: загрузка, ошибка с кнопкой «Повторить», пустой результат и кнопки сохранения.
 *
 * load — функция, которая получает AbortSignal и возвращает Promise со списком мест
 * (findNearby / findAlongSegment из utils/overpass). Загрузка запускается при появлении списка и по кнопке «Повторить»;
 * если список убрали со страницы раньше ответа, запрос отменяется.
 * actions — кнопки у каждого места: { label, savedLabel, url, extra, onSaved }. Сохранение — обычная форма Inertia,
 * сервер проверяет данные как при добавлении места с карты.
 */
export default function FoundPlacesList({ load, categories, actions, emptyText }) {
    const [places, setPlaces] = useState(null);
    const [loadError, setLoadError] = useState(null);
    // Номер попытки: «Повторить» увеличивает его, и useEffect ниже запускает загрузку заново.
    const [attempt, setAttempt] = useState(0);
    // Какая кнопка сейчас сохраняет: «id места|адрес запроса».
    const [savingKey, setSavingKey] = useState(null);
    const [savedKeys, setSavedKeys] = useState([]);
    const [saveError, setSaveError] = useState(null);

    useEffect(() => {
        const controller = new AbortController();
        setPlaces(null);
        setLoadError(null);

        load(controller.signal)
            .then((result) => !controller.signal.aborted && setPlaces(result))
            .catch((error) => !controller.signal.aborted && setLoadError(error.message));

        // Окно закрыли до ответа сервиса — отменяем запрос, результат уже никому не нужен.
        return () => controller.abort();
    }, [attempt]);

    function save(place, action) {
        // Ключ включает адрес: одно и то же место можно добавить и в избранное, и в план (в разные дни — тоже).
        const key = `${place.id}|${action.url}`;
        setSavingKey(key);
        setSaveError(null);

        router.post(action.url, { ...placeFormData(place, categories), ...action.extra }, {
            preserveScroll: true,
            onSuccess: () => {
                setSavedKeys((keys) => [...keys, key]);
                action.onSaved?.();
            },
            onError: (errors) => setSaveError(Object.values(errors)[0]),
            onFinish: () => setSavingKey(null),
        });
    }

    if (loadError) {
        return (
            <div className="found-places__error">
                <p className="form-error">{loadError}</p>
                <button type="button" className="btn btn--outline-dark btn--sm" onClick={() => setAttempt(attempt + 1)}>
                    Повторить
                </button>
            </div>
        );
    }

    if (places === null) {
        return <Loader variant="block" text="Ищем места поблизости… Бесплатный сервис OpenStreetMap бывает перегружен — это может занять до минуты." />;
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
                            {actions.map((action) => {
                                const key = `${place.id}|${action.url}`;

                                if (savedKeys.includes(key)) {
                                    return <span key={action.label} className="plan-stop__free">{action.savedLabel ?? 'Сохранено'}</span>;
                                }

                                return (
                                    <button
                                        key={action.label}
                                        type="button"
                                        className="btn btn--outline-dark btn--sm"
                                        disabled={savingKey !== null}
                                        onClick={() => save(place, action)}
                                    >
                                        {savingKey === key && <Spinner />}
                                        {action.label}
                                    </button>
                                );
                            })}
                        </div>
                    </li>
                ))}
            </ul>
            <p className="form-hint">Данные OpenStreetMap бывают неполными — проверьте место перед поездкой.</p>
        </>
    );
}
