import { useEffect, useState } from 'react';
import L from 'leaflet';
import { PLACE_SETS, findInBox } from '../../../utils/overpass';

/**
 * Дальше этого масштаба не ищем: на экране целый регион, мест тысячи, и бесплатный Overpass такой запрос не любит.
 */
export const MIN_SEARCH_ZOOM = 12;

/** Ждём, пока карта постоит спокойно 0,8 с: пока человек тащит карту, запросов нет. */
const DEBOUNCE_MS = 800;

/**
 * Края области округляем до сетки 0,02° (~2 км): после небольшого сдвига или возврата назад
 * запрос получится тем же самым, и ответ возьмётся из кэша overpass.js без похода в сеть.
 */
const GRID = 0.02;

function snapToGrid(bounds) {
    return {
        south: Math.floor(bounds.getSouth() / GRID) * GRID,
        west: Math.floor(bounds.getWest() / GRID) * GRID,
        north: Math.ceil(bounds.getNorth() / GRID) * GRID,
        east: Math.ceil(bounds.getEast() / GRID) * GRID,
    };
}

/**
 * Места выбранной категории в видимой части карты; при сдвиге карты подгружаются сами.
 *
 * Чтобы не делать лишних запросов:
 * - грузим область с запасом в полэкрана с каждой стороны и, пока видимая часть внутри неё, новых запросов нет;
 * - запрос уходит только после остановки карты (DEBOUNCE_MS), старый незаконченный запрос отменяется;
 * - на мелком масштабе (весь город и дальше) не ищем вовсе — статус zoomIn.
 *
 * category — элемент SEARCH_CATEGORIES или null (поиск по категории выключен).
 * status: idle | loading | ready | zoomIn | error.
 */
export default function useCategorySearch(map, category) {
    const [result, setResult] = useState({ status: 'idle', places: [] });

    useEffect(() => {
        if (!map || !category) {
            setResult({ status: 'idle', places: [] });

            return undefined;
        }

        let loadedArea = null;
        let controller = null;
        let timer = null;

        const load = async () => {
            if (map.getZoom() < MIN_SEARCH_ZOOM) {
                controller?.abort();
                loadedArea = null;
                setResult({ status: 'zoomIn', places: [] });

                return;
            }

            if (loadedArea?.contains(map.getBounds())) {
                return;
            }

            const box = snapToGrid(map.getBounds().pad(0.5));
            controller?.abort();
            const request = new AbortController();
            controller = request;
            // Старые маркеры не убираем, пока не пришли новые, — так карта не мигает.
            setResult((current) => ({ status: 'loading', places: current.places }));

            try {
                const places = await findInBox(box, PLACE_SETS[category.set], request.signal);
                const center = map.getCenter();
                loadedArea = L.latLngBounds([box.south, box.west], [box.north, box.east]);
                setResult({
                    status: 'ready',
                    places: places
                        .map((place) => ({ ...place, distanceKm: center.distanceTo([place.lat, place.lng]) / 1000 }))
                        .sort((first, second) => first.distanceKm - second.distanceKm),
                });
            } catch (error) {
                if (!request.signal.aborted) {
                    setResult({ status: 'error', message: error.message, places: [] });
                }
            }
        };

        const scheduleLoad = () => {
            clearTimeout(timer);
            timer = setTimeout(load, DEBOUNCE_MS);
        };

        load();
        map.on('moveend', scheduleLoad);

        return () => {
            map.off('moveend', scheduleLoad);
            clearTimeout(timer);
            controller?.abort();
        };
    }, [map, category]);

    return result;
}
