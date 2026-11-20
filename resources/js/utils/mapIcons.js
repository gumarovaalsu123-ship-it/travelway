import L from 'leaflet';

const escapeHtml = (text) => String(text)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;');

/**
 * Пин сохранённого места: зелёный с номером дня — место в плане поездки,
 * оранжевый с сердцем — только в избранном.
 */
export function placeIcon(place, isSelected = false) {
    const firstVisit = place.visits[0];
    const isFavoriteOnly = !firstVisit;
    const classes = [
        'place-pin',
        isFavoriteOnly ? 'place-pin--favorite' : 'place-pin--trip',
        isSelected ? 'place-pin--selected' : '',
    ].join(' ');

    return L.divIcon({
        className: classes,
        html: `<div class="place-pin__point">${isFavoriteOnly ? '♥' : `Д${firstVisit.dayNumber}`}</div>`,
        iconSize: [30, 30],
        iconAnchor: [15, 15],
        popupAnchor: [0, -16],
    });
}

/**
 * Временный (несохранённый) маркер выбранной точки.
 */
export function draftIcon(lat, lng) {
    return L.divIcon({
        className: 'place-pin place-pin--draft',
        html: `<div class="place-pin__draft">
            <div class="map-pin-draft"><div class="map-pin-draft__inner"></div></div>
            <div class="map-pin-label map-pin-label--draft">
                <div class="draft-label-title">Не сохранено</div>
                <div class="draft-label-coords">${escapeHtml(lat.toFixed(4))}, ${escapeHtml(lng.toFixed(4))}</div>
            </div>
        </div>`,
        iconSize: [40, 40],
        iconAnchor: [20, 20],
    });
}

/**
 * Остановка дня на карте поездки: зелёный кружок с номером как в плане, выбранная — оранжевая.
 */
export function stopIcon(number, isActive = false) {
    return L.divIcon({
        className: `place-pin place-pin--trip ${isActive ? 'place-pin--active' : ''}`,
        html: `<div class="place-pin__point">${escapeHtml(number)}</div>`,
        iconSize: [30, 30],
        iconAnchor: [15, 15],
        popupAnchor: [0, -16],
    });
}

/**
 * Контурные иконки (в стиле Feather/Lucide, сетка 24×24) для маркеров: одинаково выглядят в любой системе,
 * в отличие от символов и эмодзи. Цвет берут от текста маркера (currentColor).
 */
const ICON_PATHS = {
    hotel: '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
    cafe: '<path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/>',
    restaurant: '<path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3zm0 0v7"/>',
    museum: '<path d="M3 22h18"/><path d="M6 18v-7"/><path d="M10 18v-7"/><path d="M14 18v-7"/><path d="M18 18v-7"/><polygon points="12 2 20 7 4 7"/>',
    park: '<path d="M8 19a4 4 0 0 1-2.24-7.32A3.5 3.5 0 0 1 9 6.03V6a3 3 0 1 1 6 0v.04a3.5 3.5 0 0 1 3.24 5.65A4 4 0 0 1 16 19z"/><path d="M12 19v3"/>',
    shop: '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>',
    attraction: '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
    viewpoint: '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
    other: '<circle cx="12" cy="12" r="4"/>',
};

/** Иконка категории svg-строкой для html маркера Leaflet; size — размер в пикселях. */
export function categorySvg(categorySlug, size) {
    const paths = ICON_PATHS[categorySlug] ?? ICON_PATHS.other;

    return `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths}</svg>`;
}

/**
 * Жильё на карте поездки: тёмный квадрат с домиком, чтобы не путать с местами.
 * isActive — к жилью перешли по «Показать на карте»: значок оранжевый и крупнее.
 */
export function lodgingIcon(isActive = false) {
    return L.divIcon({
        className: `place-pin place-pin--lodging ${isActive ? 'place-pin--active' : ''}`,
        html: `<div class="place-pin__point">${categorySvg('hotel', 15)}</div>`,
        iconSize: [30, 30],
        iconAnchor: [15, 15],
        popupAnchor: [0, -16],
    });
}

// Одинаковые значки создаём один раз: на карте бывает до 150 найденных мест.
const foundIcons = new Map();

/**
 * Место, найденное поиском по категории («отели»): маленький белый кружок с иконкой категории.
 * Меньше и светлее сохранённых мест — это пока только варианты. isSelected — по нему открыта форма.
 */
export function foundIcon(categorySlug, isSelected = false) {
    const key = `${categorySlug}:${isSelected}`;

    if (!foundIcons.has(key)) {
        foundIcons.set(key, L.divIcon({
            className: `place-pin place-pin--found ${isSelected ? 'place-pin--active' : ''}`,
            html: `<div class="place-pin__found">${categorySvg(categorySlug, 13)}</div>`,
            iconSize: [24, 24],
            iconAnchor: [12, 12],
        }));
    }

    return foundIcons.get(key);
}
