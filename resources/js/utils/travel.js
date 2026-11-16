/**
 * Способы добраться до места — ключи как в TripPlace::TravelModes на сервере.
 * Иконки рисует компонент TravelModeIcon.
 */
export const TRAVEL_MODES = {
    walk: { label: 'Пешком' },
    transit: { label: 'Общественный транспорт' },
    car: { label: 'Машина' },
    taxi: { label: 'Такси / каршеринг' },
};

/**
 * 75 → «1 ч 15 мин», 40 → «40 мин», 120 → «2 ч».
 */
export function formatMinutes(total) {
    const hours = Math.floor(total / 60);
    const minutes = total % 60;

    if (hours === 0) {
        return `${minutes} мин`;
    }

    return minutes > 0 ? `${hours} ч ${minutes} мин` : `${hours} ч`;
}

/**
 * «10:00–11:30», «10:00» или пустая строка, если время не указано.
 */
export function timeLabel(place) {
    if (!place.startTime) {
        return '';
    }

    return place.endTime ? `${place.startTime}–${place.endTime}` : place.startTime;
}

/**
 * Тип маршрута Яндекс.Карт (параметр rtt) для наших способов — как DaySchedule::YandexRouteTypes на сервере.
 */
const YANDEX_ROUTE_TYPES = { walk: 'pd', transit: 'mt', car: 'auto', taxi: 'taxi' };

/**
 * Ссылка на маршрут через все точки в Яндекс.Картах — обычный адрес с координатами, без API.
 * rtext — точки через «~» в формате «широта,долгота».
 */
export function yandexRouteUrl(points, mode) {
    const text = points.map((point) => `${point.lat},${point.lng}`).join('~');

    return `https://yandex.ru/maps/?rtext=${text}&rtt=${YANDEX_ROUTE_TYPES[mode] ?? YANDEX_ROUTE_TYPES.walk}`;
}
