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

function toPlace(result) {
    const address = shortAddress(result.address);

    return {
        lat: Number(result.lat),
        lng: Number(result.lon),
        name: result.name || address || result.display_name?.split(',')[0] || '',
        address: address ?? result.display_name ?? null,
        categorySlug: categorySlugFor(result),
    };
}

async function request(path, params, signal) {
    const query = new URLSearchParams({ format: 'jsonv2', 'accept-language': 'ru', addressdetails: '1', ...params });
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

/**
 * «350 м», «4,2 км», «1 250 км»
 */
export function formatDistance(distanceKm) {
    if (distanceKm < 1) {
        return `${Math.round(distanceKm * 1000)} м`;
    }

    return `${distanceKm.toLocaleString('ru-RU', { maximumFractionDigits: distanceKm < 10 ? 1 : 0 })} км`;
}
