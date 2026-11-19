import { useEffect, useMemo, useState } from 'react';

/**
 * Серверы OSRM сообщества OpenStreetMap (те же, что считают время в пути на сервере, см. RoutingService).
 */
const OSRM_URL = 'https://routing.openstreetmap.de';

/**
 * Сколько ждать сервис маршрутов. Бесплатный сервер иногда не отвечает — тогда рисуем прямые.
 */
const TIMEOUT_MS = 8000;

/**
 * Способ на отрезке → профиль OSRM. Пешком (или способ ещё не известен) — foot,
 * машина, такси и общественный транспорт едут по дорогам — car.
 */
export function profileFor(mode) {
    return mode && mode !== 'walk' ? 'car' : 'foot';
}

/**
 * Соседние отрезки с одинаковым профилем объединяем в один запрос:
 * обычно весь день пешком — и это всего один запрос к серверу.
 *
 * @param points [{lat, lng}, ...] — точки дня по порядку
 * @param profiles ['foot' | 'car', ...] — профиль каждого отрезка (их на один меньше, чем точек)
 */
function splitIntoRuns(points, profiles) {
    const runs = [];

    profiles.forEach((profile, index) => {
        const last = runs[runs.length - 1];

        if (last && last.profile === profile) {
            last.points.push(points[index + 1]);
        } else {
            runs.push({ profile, points: [points[index], points[index + 1]] });
        }
    });

    return runs;
}

const straightLine = (run) => ({
    profile: run.profile,
    positions: run.points.map((point) => [point.lat, point.lng]),
    isStraight: true,
});

async function fetchRun(run, signal) {
    // OSRM ждёт «долгота,широта» через «;». overview=full + geojson — вся линия дороги, а не только время.
    const coordinates = run.points.map((point) => `${point.lng},${point.lat}`).join(';');
    const url = `${OSRM_URL}/routed-${run.profile}/route/v1/driving/${coordinates}?overview=full&geometries=geojson`;

    try {
        const response = await fetch(url, { signal });
        const data = await response.json();
        const geometry = data.routes?.[0]?.geometry?.coordinates;

        if (!response.ok || data.code !== 'Ok' || !geometry) {
            return straightLine(run);
        }

        // В GeoJSON точки в порядке [долгота, широта], а Leaflet ждёт [широта, долгота].
        return { profile: run.profile, positions: geometry.map(([lng, lat]) => [lat, lng]), isStraight: false };
    } catch {
        // Нет сети, таймаут (AbortController) или неверный ответ — рисуем прямую.
        return straightLine(run);
    }
}

/**
 * Линия маршрута дня. Пока ждём ответ (или если сервис не ответил) — прямые между точками.
 *
 * @returns {{ lines: Array<{profile: string, positions: number[][], isStraight: boolean}>, isLoading: boolean }}
 */
export default function useRouteLines(points, profiles) {
    const runs = useMemo(() => splitIntoRuns(points, profiles), [points, profiles]);
    const [result, setResult] = useState({ lines: runs.map(straightLine), isLoading: runs.length > 0 });

    // Ключ из координат и профилей: запрос повторяется, только если сами точки поменялись.
    const runsKey = JSON.stringify(runs);

    useEffect(() => {
        if (runs.length === 0) {
            setResult({ lines: [], isLoading: false });
            return undefined;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);
        let isCancelled = false;

        setResult({ lines: runs.map(straightLine), isLoading: true });

        Promise.all(runs.map((run) => fetchRun(run, controller.signal))).then((lines) => {
            clearTimeout(timer);

            if (!isCancelled) {
                setResult({ lines, isLoading: false });
            }
        });

        // Ушли со страницы или сменили день — отменяем незавершённые запросы.
        return () => {
            isCancelled = true;
            clearTimeout(timer);
            controller.abort();
        };
    }, [runsKey]);

    return result;
}
