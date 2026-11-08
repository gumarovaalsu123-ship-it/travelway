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
function detailsFrom(extratags = {}) {
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
        ...detailsFrom(result.extratags ?? {}),
    };
}

async function request(path, params, signal) {
    // extratags=1 — Nominatim вместе с адресом отдаёт теги объекта; отдельного запроса не нужно.
    const query = new URLSearchParams({ format: 'jsonv2', 'accept-language': 'ru', addressdetails: '1', extratags: '1', ...params });
    const response = await fetch(`${NOMINATIM_URL}/${path}?${query}`, { signal });

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

const WIKIPEDIA_TIMEOUT_MS = 4000;
const DESCRIPTION_LENGTH = 500;

/** Обрезаем описание по границе слова, чтобы карточки были одинаково компактными. */
function shortDescription(text) {
    if (!text || text.length <= DESCRIPTION_LENGTH) {
        return text || null;
    }

    const cut = text.slice(0, DESCRIPTION_LENGTH);

    return `${cut.slice(0, cut.lastIndexOf(' ')).trimEnd()}…`;
}

/**
 * Фото и краткое описание статьи Википедии по тегу «ru:Казанский кремль».
 * Любая ошибка (нет статьи, сеть, таймаут 4 с) означает просто «данных нет» — пользователю её не показываем.
 * signal — отмена снаружи: форму закрыли или кликнули в другую точку.
 */
export async function fetchWikipediaSummary(wikipediaTag, signal) {
    const empty = { image: null, description: null };
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
        const image = summary.thumbnail?.source ?? null;

        return {
            // Сервер принимает фото только с upload.wikimedia.org — другие ссылки сразу отбрасываем.
            image: image?.startsWith('https://upload.wikimedia.org/') ? image : null,
            description: summary.type === 'disambiguation' ? null : shortDescription(summary.extract),
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
