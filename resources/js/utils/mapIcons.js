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
