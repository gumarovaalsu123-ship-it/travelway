/**
 * Подсказки «Пора пообедать?», «Что по пути?», «Что рядом с жильём?» и поиск по категории на карте («отели»).
 *
 * Источник — Overpass API: бесплатный поиск по данным OpenStreetMap, без ключа.
 * Запасной источник — Nominatim (тот же OpenStreetMap): если Overpass долго молчит, ищем через него (см. searchNominatim).
 * Запросы идут прямо из браузера, результаты нигде не храним: если человек захочет сохранить место,
 * оно уходит на сервер обычной формой (новое место в план или в избранное) и проходит валидацию.
 */

import { detailsFrom, lodgingTypeFor } from './geocoding';

// Несколько независимых серверов Overpass (зеркала с одними и теми же данными OSM).
// Бесплатные серверы часто перегружены: один и тот же запрос то отвечает за 2 секунды, то висит полминуты,
// а то и сразу отвечает ошибкой 502/504/429 («занят, попробуйте позже»).
// Поэтому спрашиваем все сразу и берём первый успешный ответ, остальные запросы отменяем,
// а сервер, ответивший ошибкой, спрашиваем ещё раз через паузу (см. askServerWithRetries).
const SERVERS = [
    'https://overpass-api.de/api/interpreter',
    'https://maps.mail.ru/osm/tools/overpass/api/interpreter',
    'https://overpass.kumi.systems/api/interpreter',
    'https://overpass.private.coffee/api/interpreter',
];

// Сколько ждём ответа всего. Под нагрузкой главный сервер отвечает за 30–40 секунд,
// поэтому даём минуту: лучше подождать, чем показать ошибку, когда ответ вот-вот придёт.
const TIMEOUT_MS = 60_000;

// Если ни один сервер Overpass не ответил за 8 секунд, параллельно запускаем запасной поиск через Nominatim.
const FALLBACK_AFTER_MS = 8_000;
const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';
// Правила Nominatim: не чаще одного запроса в секунду, поэтому запросы по разным тегам идут по очереди с паузой.
const NOMINATIM_PAUSE_MS = 1_100;

// Пауза перед повтором запроса к серверу, который ответил ошибкой: 2 с, потом 4 с, 8 с, но не больше 10 с.
const FIRST_RETRY_DELAY_MS = 2_000;
const MAX_RETRY_DELAY_MS = 10_000;

// Ответы, полученные за время работы страницы: повторное открытие той же подсказки не идёт в сеть.
const cache = new Map();

// Ответы хранятся и в localStorage браузера — неделю: после перезагрузки страницы те же места
// показываются сразу, даже если серверы Overpass в этот момент перегружены. Магазины и кафе за неделю почти не меняются.
const STORAGE_PREFIX = 'overpass:';
const STORAGE_TTL_MS = 7 * 24 * 60 * 60 * 1000;

/**
 * Достать сохранённый ответ из localStorage. Хранилище может быть недоступно (приватный режим, запрет сайта) —
 * тогда просто идём в сеть, поэтому все ошибки глушим.
 */
function readStored(query) {
    try {
        const stored = JSON.parse(window.localStorage.getItem(STORAGE_PREFIX + query));

        return stored && Date.now() - stored.savedAt < STORAGE_TTL_MS ? stored.elements : null;
    } catch {
        return null;
    }
}

function writeStored(query, elements) {
    try {
        window.localStorage.setItem(STORAGE_PREFIX + query, JSON.stringify({ savedAt: Date.now(), elements }));
    } catch {
        // Хранилище переполнено или недоступно — не страшно, ответ останется в памяти страницы.
    }
}

/** Подождать ms миллисекунд; если запрос отменили раньше — сразу закончить ошибкой. */
function wait(ms, signal) {
    return new Promise((resolve, reject) => {
        const timer = setTimeout(resolve, ms);
        signal.addEventListener('abort', () => {
            clearTimeout(timer);
            reject(new Error('aborted'));
        }, { once: true });
    });
}

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
    hotels: { tourism: ['hotel', 'hostel', 'guest_house', 'motel', 'apartment'] },
    museums: { tourism: ['museum', 'gallery'] },
    parks: { leisure: ['park', 'garden'] },
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
    'tourism=hotel': { slug: 'hotel', label: 'Отель' },
    'tourism=hostel': { slug: 'hotel', label: 'Хостел' },
    'tourism=guest_house': { slug: 'hotel', label: 'Гостевой дом' },
    'tourism=motel': { slug: 'hotel', label: 'Мотель' },
    'tourism=apartment': { slug: 'hotel', label: 'Апартаменты' },
    'tourism=gallery': { slug: 'museum', label: 'Галерея' },
    'leisure=park': { slug: 'park', label: 'Парк' },
    'leisure=garden': { slug: 'park', label: 'Сад' },
};

/** Понятная ошибка для человека: сервис не ответил или ответил ошибкой. */
export class OverpassError extends Error {
    constructor(isTimeout = false) {
        super(isTimeout
            ? 'Сервис OpenStreetMap не ответил за минуту — он сейчас перегружен. Попробуйте ещё раз чуть позже.'
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
 * В скобках — фильтр области: круг «around:…» или прямоугольник «юг,запад,север,восток».
 * out center — для контуров Overpass сам посчитает центр, чтобы у каждого места были координаты.
 * limit — сколько мест вернуть, не больше.
 */
function buildQuery(placeSet, areaFilter, limit = 60) {
    const parts = Object.entries(placeSet).map(
        ([key, values]) => `nwr["${key}"~"^(${values.join('|')})$"]["name"](${areaFilter});`,
    );

    return `[out:json][timeout:25];(${parts.join('')});out center ${limit};`;
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
 * Спрашивать один сервер, пока не ответит, с паузой между попытками (2 с, 4 с, 8 с, 10 с…).
 * Остановится, только когда запрос отменят: пришёл ответ от другого сервера, вышло время или окно закрыли.
 */
async function askServerWithRetries(server, query, signal) {
    let delay = FIRST_RETRY_DELAY_MS;

    for (;;) {
        try {
            return await askServer(server, query, signal);
        } catch (error) {
            if (signal.aborted) {
                throw error;
            }

            await wait(delay, signal);
            delay = Math.min(delay * 2, MAX_RETRY_DELAY_MS);
        }
    }
}

/**
 * Запасной поиск через Nominatim — когда все серверы Overpass перегружены.
 *
 * Nominatim понимает «специальные фразы»: запрос «[pharmacy]» с bounded=1 — это объекты amenity=pharmacy
 * внутри прямоугольника viewbox. За один запрос — один тег и не больше 40 мест, поэтому для набора
 * (например, продукты = supermarket, convenience, grocery, greengrocer) делаем несколько запросов по очереди.
 * Результаты переводим в тот же вид, что отдаёт Overpass ({ type, id, lat, lon, tags }), —
 * дальше они идут через те же функции toPlace / placesFrom.
 * box — { south, west, north, east } в градусах.
 */
async function searchNominatim(placeSet, box, signal) {
    const elements = [];
    const pairs = Object.entries(placeSet).flatMap(([key, values]) => values.map((value) => [key, value]));

    for (const [index, [key, value]] of pairs.entries()) {
        if (index > 0) {
            await wait(NOMINATIM_PAUSE_MS, signal);
        }

        const params = new URLSearchParams({
            q: `[${value}]`,
            format: 'jsonv2',
            'accept-language': 'ru',
            addressdetails: '1',
            bounded: '1',
            limit: '40',
            viewbox: `${box.west},${box.north},${box.east},${box.south}`,
        });
        const response = await fetch(`${NOMINATIM_URL}?${params}`, { signal });

        if (!response.ok) {
            throw new Error(`Nominatim ответил ${response.status}`);
        }

        for (const result of await response.json()) {
            // Фраза «[cafe]» может найти и объекты другого типа — оставляем только точное совпадение тега.
            if (result.category !== key || result.type !== value || !result.name) {
                continue;
            }

            elements.push({
                type: result.osm_type,
                id: result.osm_id,
                lat: Number(result.lat),
                lon: Number(result.lon),
                tags: {
                    name: result.name,
                    [key]: value,
                    'addr:street': result.address?.road,
                    'addr:housenumber': result.address?.house_number,
                },
            });
        }
    }

    return elements;
}

/**
 * Найти места набора placeSet в области area: сначала через Overpass, а если он молчит дольше 8 секунд —
 * параллельно ещё и через Nominatim. Берём тот ответ, что придёт первым (Promise.any).
 *
 * area.around — фильтр Overpass вида «around:500,55.79,49.10»; area.box — тот же район прямоугольником для Nominatim.
 * Один AbortController на всех: при первом ответе, по таймауту или когда окно закрыли (signal снаружи)
 * отменяем все оставшиеся запросы, чтобы не нагружать серверы зря.
 */
async function runQuery(placeSet, area, signal) {
    const query = buildQuery(placeSet, area.around, area.limit);

    if (cache.has(query)) {
        return cache.get(query);
    }

    const stored = readStored(query);

    if (stored) {
        cache.set(query, stored);

        return stored;
    }

    const controller = new AbortController();
    let isTimeout = false;
    const timer = setTimeout(() => {
        isTimeout = true;
        controller.abort();
    }, TIMEOUT_MS);
    const abortFromOutside = () => controller.abort();
    signal?.addEventListener('abort', abortFromOutside);

    const fromOverpass = Promise.any(SERVERS.map((server) => askServerWithRetries(server, query, controller.signal)))
        .then((elements) => {
            // В localStorage кладём только полный ответ Overpass: ответ Nominatim бывает неполным,
            // и после перезагрузки страницы лучше снова попробовать Overpass.
            writeStored(query, elements);

            return elements;
        });
    const fromNominatim = wait(FALLBACK_AFTER_MS, controller.signal)
        .then(() => searchNominatim(placeSet, area.box, controller.signal));

    try {
        const elements = await Promise.any([fromOverpass, fromNominatim]);
        cache.set(query, elements);

        return elements;
    } catch {
        // За минуту не ответили ни Overpass, ни Nominatim, или окно закрыли раньше.
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
        // Если человек решит здесь жить (отель с карты): хостел, апартаменты или просто гостиница.
        lodgingType: lodgingTypeFor(tags.tourism),
        // Overpass отдаёт все теги объекта: часы работы, сайт, телефон, статья в Википедии — как у Nominatim с extratags.
        ...detailsFrom(tags),
    };
}

function placesFrom(elements, placeSet) {
    return elements.map((element) => toPlace(element, placeSet)).filter((place) => place.lat !== undefined);
}

/**
 * Прямоугольник, в который попадают все точки points с запасом margin метров со всех сторон (для Nominatim).
 * 1° широты ≈ 111 км, 1° долготы ≈ 111 км × cos(широты).
 */
function boxAround(points, margin) {
    const lats = points.map((p) => p.lat);
    const lngs = points.map((p) => p.lng);
    const dLat = margin / 111_320;
    const dLng = margin / (111_320 * Math.cos((Math.max(...lats.map(Math.abs)) * Math.PI) / 180));

    return {
        south: Math.min(...lats) - dLat,
        north: Math.max(...lats) + dLat,
        west: Math.min(...lngs) - dLng,
        east: Math.max(...lngs) + dLng,
    };
}

/**
 * Места вокруг точки в радиусе radius метров, от ближних к дальним.
 * signal — AbortSignal: если окно закрыли раньше ответа, запрос отменяется.
 */
export async function findNearby(point, placeSet, radius = 500, signal = undefined) {
    const area = { around: `around:${radius},${point.lat},${point.lng}`, box: boxAround([point], radius) };
    const elements = await runQuery(placeSet, area, signal);

    return placesFrom(elements, placeSet)
        .map((place) => ({ ...place, distance: distanceMeters(point, place) }))
        // Прямоугольник Nominatim шире круга: углы отбрасываем, чтобы было ровно «в радиусе».
        .filter((place) => place.distance <= radius)
        .sort((a, b) => a.distance - b.distance);
}

/**
 * Все места набора placeSet внутри прямоугольника box ({ south, west, north, east }) — поиск по категории на карте.
 * Фильтр Overpass «(юг,запад,север,восток)» — объекты внутри рамки; до 150 мест, чтобы не завалить карту маркерами.
 */
export async function findInBox(box, placeSet, signal = undefined) {
    const area = { around: `${box.south},${box.west},${box.north},${box.east}`, box, limit: 150 };

    return placesFrom(await runQuery(placeSet, area, signal), placeSet);
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
    const area = { around: `around:${width},${a.lat},${a.lng},${b.lat},${b.lng}`, box: boxAround([a, b], width) };
    const elements = await runQuery(placeSet, area, signal);

    return placesFrom(elements, placeSet)
        // Сами точки A и B (если это то же место из OSM) рядом с отрезком не интересны — отбрасываем совсем близкие к концам.
        .filter((place) => distanceMeters(place, a) > 30 && distanceMeters(place, b) > 30)
        .map((place) => ({ ...place, distance: distanceToSegmentMeters(place, a, b) }))
        // Ответ Nominatim — весь прямоугольник вокруг отрезка, оставляем только «коридор» шириной width.
        .filter((place) => place.distance <= width)
        .sort((x, y) => x.distance - y.distance)
        .slice(0, limit);
}
