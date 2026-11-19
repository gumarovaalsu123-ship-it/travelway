import { profileFor } from '../Components/Map/Logic/useRouteLines';

/**
 * Все точки дня по порядку, как в расписании: [жильё утром] → места → [жильё вечером].
 * Профиль отрезка (пешком / по дорогам) берём по способу, которым добираемся до следующей точки.
 *
 * @returns {{ points: Array<{lat: number, lng: number}>, profiles: string[] }}
 */
export function dayRoute(day) {
    const chain = [
        ...(day.morningLodging ? [{ ...day.morningLodging, mode: null }] : []),
        ...day.places,
        ...(day.eveningLodging ? [{ ...day.eveningLodging, mode: day.returnMode }] : []),
    ];

    return {
        points: chain.map((point) => ({ lat: point.lat, lng: point.lng })),
        profiles: chain.slice(1).map((point) => profileFor(point.mode)),
    };
}

/**
 * Жильё, которое показываем на карте дня. Утром и вечером обычно одно и то же жильё — оставляем один значок.
 * Жильё из «Показать на карте» (вкладка «Проживание») добавляем, даже если маршрут дня его не касается.
 */
export function dayLodgings(day, focusLodging) {
    return [day.morningLodging, day.eveningLodging, focusLodging]
        .filter(Boolean)
        .filter((lodging, index, all) => all.findIndex((other) => other.id === lodging.id) === index);
}
