const NOMINATIM_URL = 'https://nominatim.openstreetmap.org';

/**
 * Соответствие типов объектов OpenStreetMap и наших категорий (place_categories.slug).
 */
const OSM_TYPE_TO_CATEGORY = {
    cafe: 'cafe',
    restaurant: 'restaurant',
    fast_food: 'restaurant',
    bar: 'restaurant',
    pub: 'restaurant',
    food_court: 'restaurant',
    museum: 'museum',
    gallery: 'museum',
    attraction: 'attraction',
    artwork: 'attraction',
    monument: 'attraction',
    memorial: 'attraction',
    castle: 'attraction',
    ruins: 'attraction',
    place_of_worship: 'attraction',
    theatre: 'attraction',
    viewpoint: 'viewpoint',
    park: 'park',
    garden: 'park',
    nature_reserve: 'park',
    hotel: 'hotel',
    hostel: 'hotel',
    guest_house: 'hotel',
    apartment: 'hotel',
    station: 'transport',
    bus_stop: 'transport',
    tram_stop: 'transport',
    subway_entrance: 'transport',
    aerodrome: 'transport',
};

const OSM_CLASS_TO_CATEGORY = {
    shop: 'shop',
    railway: 'transport',
    public_transport: 'transport',
};

/**
 * Тип жилья (Accommodation::Types) для объекта OSM — подставляется, если человек решит здесь жить.
 */
const OSM_TYPE_TO_LODGING = {
    hostel: 'hostel',
    apartment: 'apartment',
};

/** Тип жилья по значению тега tourism (hostel → hostel), для всего остального — гостиница. */
export const lodgingTypeFor = (osmType) => OSM_TYPE_TO_LODGING[osmType] ?? 'hotel';

function categorySlugFor(result) {
    return OSM_TYPE_TO_CATEGORY[result.type]
        ?? OSM_CLASS_TO_CATEGORY[result.class ?? result.category]
        ?? 'other';
}

function shortAddress(address = {}) {
    const street = [address.road ?? address.pedestrian ?? address.square, address.house_number]
        .filter(Boolean)
        .join(', ');
    const city = address.city ?? address.town ?? address.village;

    return [street, city].filter(Boolean).join(', ') || null;
}

/** Строка не длиннее limit символов, иначе null — чтобы слишком длинное значение не помешало сохранить место. */
function limited(value, limit) {
    const text = value?.trim();

    return text && text.length <= limit ? text : null;
}

/** В OSM сайт часто записан без схемы («kazan-kremlin.ru»). Добавляем https://, а всё, кроме http(s), отбрасываем. */
function normalizeWebsite(value) {
    const text = limited(value?.split(';')[0], 490);

    if (!text) {
        return null;
    }

    if (/^https?:\/\//i.test(text)) {
        return text;
    }

    return /^[a-z][a-z0-9+.-]*:/i.test(text) ? null : `https://${text}`;
}

/** Тег wikipedia имеет вид «ru:Казанский кремль»: язык и заголовок статьи. */
function parseWikipediaTag(tag) {
    const match = tag?.match(/^([a-z-]+):(.+)$/i);

    return match ? { lang: match[1].toLowerCase(), title: match[2].trim().replaceAll(' ', '_') } : null;
}

/**
 * Полезные теги объекта OSM (запрос с extratags=1): часы работы, сайт, телефон, статья в Википедии.
 * Остальные теги не берём — их редко заполняют.
 */
export function detailsFrom(extratags = {}) {
    const article = parseWikipediaTag(extratags.wikipedia);
    const wikiUrl = article ? `https://${article.lang}.wikipedia.org/wiki/${encodeURIComponent(article.title)}` : null;

    return {
        openingHours: limited(extratags.opening_hours, 255),
        website: normalizeWebsite(extratags.website ?? extratags['contact:website']),
        phone: limited(extratags.phone ?? extratags['contact:phone'], 50),
        wikipedia: wikiUrl && wikiUrl.length <= 500 ? extratags.wikipedia : null,
        wikiUrl: wikiUrl && wikiUrl.length <= 500 ? wikiUrl : null,
    };
}

function toPlace(result) {
    const address = shortAddress(result.address);

    return {
        lat: Number(result.lat),
        lng: Number(result.lon),
        name: result.name || address || result.display_name?.split(',')[0] || '',
        address: address ?? result.display_name ?? null,
        categorySlug: categorySlugFor(result),
        lodgingType: lodgingTypeFor(result.type),
        ...detailsFrom(result.extratags ?? {}),
    };
}

// Дольше 15 секунд ответа Nominatim не ждём: человек увидит понятную ошибку вместо бесконечной загрузки.
const NOMINATIM_TIMEOUT_MS = 15_000;

/** Ошибка «сервис не ответил вовремя» — чтобы показать человеку отдельный текст. */
export const isTimeoutError = (error) => error?.name === 'TimeoutError';

async function request(path, params, signal) {
    // extratags=1 — Nominatim вместе с адресом отдаёт теги объекта; отдельного запроса не нужно.
    const query = new URLSearchParams({ format: 'jsonv2', 'accept-language': 'ru', addressdetails: '1', extratags: '1', ...params });
    // AbortSignal.any — запрос отменится по любой из причин: снаружи (новый поиск, закрыли форму) или по таймауту.
    // При таймауте fetch бросает ошибку с именем TimeoutError, при отмене снаружи — AbortError.
    const timeoutSignal = AbortSignal.timeout(NOMINATIM_TIMEOUT_MS);
    const response = await fetch(`${NOMINATIM_URL}/${path}?${query}`, {
        signal: signal ? AbortSignal.any([signal, timeoutSignal]) : timeoutSignal,
    });

    if (!response.ok) {
        throw new Error(`Nominatim ответил ${response.status}`);
    }

    return response.json();
}

/**
 * Название, адрес и категория объекта по координатам.
 */
export async function reverseGeocode(lat, lng, signal) {
    const result = await request('reverse', { lat, lon: lng, zoom: '18' }, signal);

    if (result.error) {
        return { lat, lng, name: '', address: null, categorySlug: 'other' };
    }

    return { ...toPlace(result), lat, lng };
}

/**
 * Поиск мест по названию или адресу. Вызывается только по отправке формы:
 * правила Nominatim запрещают поиск «на каждую букву».
 */
export async function searchPlaces(text, map, signal) {
    const params = { q: text, limit: '10' };
    let results = [];

    // Сначала ищем только вокруг видимой области карты (с запасом в несколько экранов),
    // и лишь если там ничего нет — по всему миру.
    if (map) {
        const nearbyArea = map.getBounds().pad(2);
        results = await request('search', { ...params, viewbox: nearbyArea.toBBoxString(), bounded: '1' }, signal);
    }

    if (results.length === 0) {
        results = await request('search', params, signal);
    }

    const center = map?.getCenter();

    return results
        .map((result) => {
            const place = toPlace(result);

            return {
                ...place,
                id: result.place_id,
                fullAddress: result.display_name,
                distanceKm: center ? center.distanceTo([place.lat, place.lng]) / 1000 : null,
            };
        })
        // Длинные улицы OSM отдаёт несколькими одинаковыми отрезками — оставляем ближайший.
        .sort((first, second) => (first.distanceKm ?? 0) - (second.distanceKm ?? 0))
        .filter((place, index, all) => all.findIndex((other) => other.fullAddress === place.fullAddress) === index)
        .slice(0, 6);
}

// Найденные города (страны) по названию: одна поездка — один запрос, даже если город нужен и карте, и списку избранного.
const areaRequests = new Map();

// Запас вокруг рамки, в градусах (~30 км): чтобы в поездку можно было взять и пригород (Свияжск у Казани, Петергоф у Петербурга).
const AREA_MARGIN = 0.3;

/**
 * Город или страна по названию: центр и рамка (boundingbox) от Nominatim. null — ничего не нашли.
 * Рамка — четыре числа: самая южная и северная широта, самая западная и восточная долгота.
 */
export function findArea(text) {
    if (!areaRequests.has(text)) {
        const promise = request('search', { q: text, limit: '1' })
            .then(([result]) => {
                if (!result?.boundingbox) {
                    return null;
                }

                const [south, north, west, east] = result.boundingbox.map(Number);

                return {
                    lat: Number(result.lat),
                    lng: Number(result.lon),
                    bounds: { south: south - AREA_MARGIN, north: north + AREA_MARGIN, west: west - AREA_MARGIN, east: east + AREA_MARGIN },
                };
            })
            .catch((error) => {
                areaRequests.delete(text); // Сеть или таймаут — при следующем открытии спросим ещё раз.
                throw error;
            });

        areaRequests.set(text, promise);
    }

    return areaRequests.get(text);
}

/**
 * Лежит ли точка внутри рамки из findArea.
 * Если west > east, рамка пересекает 180-й меридиан (так бывает у России) — долгота подходит с любой его стороны.
 */
export function isInsideArea(bounds, lat, lng) {
    const fitsLatitude = lat >= bounds.south && lat <= bounds.north;
    const fitsLongitude = bounds.west <= bounds.east
        ? lng >= bounds.west && lng <= bounds.east
        : lng >= bounds.west || lng <= bounds.east;

    return fitsLatitude && fitsLongitude;
}

/**
 * Какие объекты OpenStreetMap подходят как пункт отправления/прибытия для вида транспорта:
 * keyword — слово, которое добавляем к запросу («вокзал Казань» Nominatim понимает лучше, чем просто «Казань»),
 * kinds — пары «класс:тип» из ответа Nominatim, остальные результаты отбрасываем.
 */
export const STATION_KINDS = {
    plane: { keyword: 'аэропорт', kinds: ['aeroway:aerodrome', 'aeroway:terminal'] },
    train: { keyword: 'вокзал', kinds: ['railway:station', 'railway:halt', 'building:train_station', 'public_transport:station'] },
    bus: { keyword: 'автовокзал', kinds: ['amenity:bus_station', 'highway:bus_stop', 'public_transport:station'] },
    ferry: { keyword: 'морской вокзал', kinds: ['amenity:ferry_terminal', 'landuse:port', 'industrial:port'] },
};

const STATION_TIMEOUT_MS = 8000;

/**
 * Вокзалы, автовокзалы, аэропорты или порты по тексту из поля «Откуда»/«Куда».
 * Вызывается по кнопке или Enter, а не на каждую букву — так требуют правила Nominatim.
 * Если сервис не ответил за 8 секунд, запрос отменяется и функция бросает ошибку TimeoutError.
 */
export async function searchStations(text, transportType, signal) {
    const station = STATION_KINDS[transportType];
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(new DOMException('Сервис не ответил', 'TimeoutError')), STATION_TIMEOUT_MS);
    // Отмена снаружи (форму закрыли или начали новый поиск) тоже отменяет запрос.
    signal?.addEventListener('abort', () => controller.abort(signal.reason));

    try {
        // Если человек уже написал «вокзал», второй раз слово не добавляем.
        const query = text.toLowerCase().includes(station.keyword) ? text : `${station.keyword} ${text}`;
        const results = await request('search', { q: query, limit: '15' }, controller.signal);

        return results
            .filter((result) => station.kinds.includes(`${result.category}:${result.type}`))
            .map((result) => {
                const city = result.address?.city ?? result.address?.town ?? result.address?.village ?? result.address?.state;
                const name = result.name || result.display_name.split(',')[0];

                return {
                    id: result.place_id,
                    // В поле записываем «Казанский вокзал, Москва» — название и город, если города нет в названии.
                    label: (city && !name.includes(city) ? `${name}, ${city}` : name).slice(0, 150),
                    fullAddress: result.display_name,
                };
            })
            .filter((place, index, all) => all.findIndex((other) => other.label === place.label) === index)
            .slice(0, 6);
    } finally {
        clearTimeout(timer);
    }
}

const WIKIPEDIA_TIMEOUT_MS = 4000;
/**
 * Фото из статьи Википедии по тегу «ru:Казанский кремль».
 * Любая ошибка (нет статьи, сеть, таймаут 4 с) означает просто «данных нет» — пользователю её не показываем.
 * signal — отмена снаружи: форму закрыли или кликнули в другую точку.
 */
const WIKIMEDIA_IMAGE = /^https:\/\/(upload|thumb)\.wikimedia\.org\//;

export async function fetchWikipediaSummary(wikipediaTag, signal) {
    const empty = { image: null };
    const article = parseWikipediaTag(wikipediaTag);

    if (!article) {
        return empty;
    }

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), WIKIPEDIA_TIMEOUT_MS);
    signal?.addEventListener('abort', () => controller.abort());

    try {
        const response = await fetch(
            `https://${article.lang}.wikipedia.org/api/rest_v1/page/summary/${encodeURIComponent(article.title)}`,
            { signal: controller.signal },
        );

        if (!response.ok) {
            return empty;
        }

        const summary = await response.json();
        // Метки ?utm_source=… Википедия добавляет для статистики — для показа картинки они не нужны.
        const image = summary.thumbnail?.source?.split('?')[0] ?? null;

        return {
            // Сервер принимает фото только с серверов картинок Википедии
            // (upload.wikimedia.org или thumb.wikimedia.org) — другие ссылки сразу отбрасываем.
            image: WIKIMEDIA_IMAGE.test(image ?? '') ? image : null,
        };
    } catch {
        return empty;
    } finally {
        clearTimeout(timer);
    }
}

/**
 * «350 м», «4,2 км», «1 250 км»
 */
export function formatDistance(distanceKm) {
    if (distanceKm < 1) {
        return `${Math.round(distanceKm * 1000)} м`;
    }

    return `${distanceKm.toLocaleString('ru-RU', { maximumFractionDigits: distanceKm < 10 ? 1 : 0 })} км`;
}
