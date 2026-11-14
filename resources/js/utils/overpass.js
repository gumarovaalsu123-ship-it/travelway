/**
 * Подсказки «Пора пообедать?», «Что по пути?», «Что рядом с жильём?».
 *
 * Источник — Overpass API: бесплатный поиск по данным OpenStreetMap, без ключа.
 * Запросы идут прямо из браузера, результаты нигде не храним: если человек захочет сохранить место,
 * оно уходит на сервер обычной формой (новое место в план или в избранное) и проходит валидацию.
 */

// Несколько независимых серверов Overpass (зеркала с одними и теми же данными OSM).
// Бесплатные серверы часто перегружены: один и тот же запрос то отвечает за 2 секунды, то висит полминуты.
// Поэтому спрашиваем все сразу и берём первый успешный ответ, остальные запросы отменяем.
const SERVERS = [
    'https://overpass-api.de/api/interpreter',
    'https://maps.mail.ru/osm/tools/overpass/api/interpreter',
    'https://overpass.kumi.systems/api/interpreter',
];

// Сколько ждём ответа всего. Больше 30 секунд человек ждать не станет.
const TIMEOUT_MS = 30_000;

// Ответы, полученные за время работы страницы: повторное открытие той же подсказки не идёт в сеть.
const cache = new Map();

/**
 * Наборы мест: какие теги OSM ищем. Ключ — тег, значение — список подходящих значений.
 */
export const PLACE_SETS = {
    food: { amenity: ['cafe', 'restaurant', 'fast_food'] },
    sights: {
        tourism: ['attraction', 'viewpoint', 'museum'],
        historic: ['monument', 'memorial'],
    },
    groceries: { shop: ['supermarket', 'convenience', 'grocery', 'greengrocer'] },
    pharmacies: { amenity: ['pharmacy'] },
};

/**
 * Тег OSM → наша категория (place_categories.slug) и подпись в списке.
 * Аптеки отдельной категории у нас нет — сохраняются как «Магазин».
 */
const TAG_INFO = {
    'amenity=cafe': { slug: 'cafe', label: 'Кофейня' },
    'amenity=restaurant': { slug: 'restaurant', label: 'Ресторан' },
    'amenity=fast_food': { slug: 'restaurant', label: 'Фастфуд' },
    'amenity=pharmacy': { slug: 'shop', label: 'Аптека' },
    'tourism=attraction': { slug: 'attraction', label: 'Достопримечательность' },
    'tourism=viewpoint': { slug: 'viewpoint', label: 'Смотровая' },
    'tourism=museum': { slug: 'museum', label: 'Музей' },
    'historic=monument': { slug: 'attraction', label: 'Памятник' },
    'historic=memorial': { slug: 'attraction', label: 'Мемориал' },
    'shop=supermarket': { slug: 'shop', label: 'Супермаркет' },
    'shop=convenience': { slug: 'shop', label: 'Магазин у дома' },
    'shop=grocery': { slug: 'shop', label: 'Продукты' },
    'shop=greengrocer': { slug: 'shop', label: 'Овощи и фрукты' },
};

/** Понятная ошибка для человека: сервис не ответил или ответил ошибкой. */
export class OverpassError extends Error {
    constructor(isTimeout = false) {
        super(isTimeout
            ? 'Сервис OpenStreetMap не ответил за 30 секунд — он сейчас перегружен. Попробуйте ещё раз чуть позже.'
            : 'Сервис подсказок OpenStreetMap сейчас недоступен. Попробуйте позже — план и сохранённые места в порядке.');
        this.name = 'OverpassError';
    }
}

/**
 * Расстояние между двумя точками в метрах (формула гаверсинусов — расстояние по поверхности Земли).
 */
export function distanceMeters(a, b) {
    const R = 6_371_000;
    const toRad = (deg) => (deg * Math.PI) / 180;
    const dLat = toRad(b.lat - a.lat);
    const dLng = toRad(b.lng - a.lng);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.sin(dLng / 2) ** 2;

    return 2 * R * Math.asin(Math.sqrt(h));
}

/**
 * Расстояние от точки до отрезка AB в метрах.
 * На нескольких километрах Землю можно считать плоской: переводим градусы в метры вокруг точки A
 * (1° широты ≈ 111 км, 1° долготы ≈ 111 км × cos(широты)) и ищем ближайшую точку отрезка, как в школьной геометрии.
 */
export function distanceToSegmentMeters(point, a, b) {
    const metersPerDegLat = 111_320;
    const metersPerDegLng = 111_320 * Math.cos((a.lat * Math.PI) / 180);
    const toXY = (p) => ({ x: (p.lng - a.lng) * metersPerDegLng, y: (p.lat - a.lat) * metersPerDegLat });

    const p = toXY(point);
    const end = toXY(b);
    const lengthSquared = end.x ** 2 + end.y ** 2;
    // t — где на отрезке ближайшая точка: 0 — в A, 1 — в B. За края отрезка не выходим.
    const t = lengthSquared === 0 ? 0 : Math.max(0, Math.min(1, (p.x * end.x + p.y * end.y) / lengthSquared));

    return Math.hypot(p.x - t * end.x, p.y - t * end.y);
}

/** 140 → «140 м», 1260 → «1,3 км». */
export function formatDistance(meters) {
    return meters < 1000 ? `${Math.round(meters / 10) * 10} м` : `${(meters / 1000).toFixed(1).replace('.', ',')} км`;
}

/**
 * Текст запроса на языке Overpass QL. Для каждого тега — строка вида
 *   nwr["amenity"~"^(cafe|restaurant)$"]["name"](around:500,55.79,49.10);
 * nwr — точки, линии и области (кафе бывает отмечено и точкой, и контуром здания); ["name"] — только с названием.
 * out center — для контуров Overpass сам посчитает центр, чтобы у каждого места были координаты.
 */
function buildQuery(placeSet, around) {
    const parts = Object.entries(placeSet).map(
        ([key, values]) => `nwr["${key}"~"^(${values.join('|')})$"]["name"](${around});`,
    );

    return `[out:json][timeout:25];(${parts.join('')});out center 60;`;
}

/**
 * Спросить один сервер. Ошибка — если сервер ответил не 200 или сам сообщил, что не успел выполнить запрос
 * (тогда в ответе есть поле remark с «runtime error», а список мест пустой или неполный).
 */
async function askServer(server, query, signal) {
    // POST с телом-формой: браузер отправляет такой запрос без предварительной проверки CORS,
    // а Overpass отвечает заголовком Access-Control-Allow-Origin: *.
    const response = await fetch(server, { method: 'POST', body: new URLSearchParams({ data: query }), signal });

    if (!response.ok) {
        throw new Error(`Overpass ответил ${response.status}`);
    }

    const json = await response.json();

    if (json.remark?.includes('runtime error')) {
        throw new Error(json.remark);
    }

    return json.elements ?? [];
}

/**
 * Отправить запрос всем серверам сразу и вернуть первый успешный ответ (Promise.any).
 * Один AbortController на всех: при первом ответе, по таймауту или когда окно закрыли (signal снаружи)
 * отменяем все оставшиеся запросы, чтобы не нагружать серверы зря.
 */
async function runQuery(query, signal) {
    if (cache.has(query)) {
        return cache.get(query);
    }

    const controller = new AbortController();
    let isTimeout = false;
    const timer = setTimeout(() => {
        isTimeout = true;
        controller.abort();
    }, TIMEOUT_MS);
    const abortFromOutside = () => controller.abort();
    signal?.addEventListener('abort', abortFromOutside);

    try {
        const elements = await Promise.any(SERVERS.map((server) => askServer(server, query, controller.signal)));
        cache.set(query, elements);

        return elements;
    } catch {
        // Все серверы ответили ошибкой или запросы отменены по таймауту.
        throw new OverpassError(isTimeout);
    } finally {
        clearTimeout(timer);
        signal?.removeEventListener('abort', abortFromOutside);
        controller.abort();
    }
}

/** Какой из тегов набора подошёл элементу: «amenity=cafe» и т. п. */
function matchedTag(tags, placeSet) {
    for (const [key, values] of Object.entries(placeSet)) {
        if (values.includes(tags[key])) {
            return `${key}=${tags[key]}`;
        }
    }

    return null;
}

/** Элемент Overpass → место для списка: название, координаты, категория, адрес. */
function toPlace(element, placeSet) {
    const tags = element.tags ?? {};
    const info = TAG_INFO[matchedTag(tags, placeSet)] ?? { slug: 'other', label: 'Место' };
    const street = [tags['addr:street'], tags['addr:housenumber']].filter(Boolean).join(', ');

    return {
        id: `${element.type}/${element.id}`,
        // Длина как в валидации на сервере (255), чтобы слишком длинное значение не помешало сохранить.
        name: tags.name.slice(0, 255),
        lat: element.lat ?? element.center?.lat,
        lng: element.lon ?? element.center?.lon,
        categorySlug: info.slug,
        kindLabel: info.label,
        address: street ? street.slice(0, 255) : null,
    };
}

function placesFrom(elements, placeSet) {
    return elements.map((element) => toPlace(element, placeSet)).filter((place) => place.lat !== undefined);
}

/**
 * Места вокруг точки в радиусе radius метров, от ближних к дальним.
 * signal — AbortSignal: если окно закрыли раньше ответа, запрос отменяется.
 */
export async function findNearby(point, placeSet, radius = 500, signal = undefined) {
    const elements = await runQuery(buildQuery(placeSet, `around:${radius},${point.lat},${point.lng}`), signal);

    return placesFrom(elements, placeSet)
        .map((place) => ({ ...place, distance: distanceMeters(point, place) }))
        .sort((a, b) => a.distance - b.distance);
}

/**
 * Места вдоль отрезка от точки A до точки B — не дальше width метров от прямой линии между ними.
 *
 * Как ищем: фильтр Overpass «around» умеет принимать не одну точку, а линию из нескольких точек:
 * (around:200, latA, lngA, latB, lngB) — это все объекты в 200 м от прямой A→B, то есть «коридор» вдоль неё.
 * Настоящий путь по улицам немного отличается от прямой, поэтому это приближение — для подсказки этого хватает.
 * Расстояние до линии и сортировку считаем сами (distanceToSegmentMeters).
 */
export async function findAlongSegment(a, b, placeSet, width = 200, limit = 5, signal = undefined) {
    const elements = await runQuery(buildQuery(placeSet, `around:${width},${a.lat},${a.lng},${b.lat},${b.lng}`), signal);

    return placesFrom(elements, placeSet)
        // Сами точки A и B (если это то же место из OSM) рядом с отрезком не интересны — отбрасываем совсем близкие к концам.
        .filter((place) => distanceMeters(place, a) > 30 && distanceMeters(place, b) > 30)
        .map((place) => ({ ...place, distance: distanceToSegmentMeters(place, a, b) }))
        .sort((x, y) => x.distance - y.distance)
        .slice(0, limit);
}
