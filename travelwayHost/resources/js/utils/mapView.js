import L from 'leaflet';

const FORM_WIDTH = 392;
const GAP = 32;
const MOBILE_BREAKPOINT = 768;
const DRAFT_LABEL_WIDTH = 120;

/**
 * Сколько места справа (или снизу на телефоне) занимает форма «Добавить место».
 */
function formPadding(map) {
    const { x: width, y: height } = map.getSize();

    if (width < MOBILE_BREAKPOINT) {
        return L.point(0, Math.round(height * 0.7) + GAP);
    }

    return L.point(FORM_WIDTH + GAP, 0);
}

/**
 * Сдвигает карту, если выбранная точка оказалась под формой.
 */
export function revealPointBesideForm(map, latlng) {
    const size = map.getSize();
    const padding = formPadding(map).add([GAP + DRAFT_LABEL_WIDTH, GAP]);

    // На узкой карте оставляем точке хотя бы немного места слева от формы.
    map.panInside(latlng, {
        paddingTopLeft: L.point(GAP, GAP),
        paddingBottomRight: L.point(Math.min(padding.x, size.x - 2 * GAP - 40), Math.min(padding.y, size.y - 2 * GAP - 40)),
    });
}

/**
 * Перелёт к точке так, чтобы она оказалась в центре видимой (не закрытой формой) части карты.
 */
export function flyToPointBesideForm(map, latlng, zoom) {
    const offset = formPadding(map).divideBy(2);
    const target = map.unproject(map.project(latlng, zoom).add(offset), zoom);

    map.flyTo(target, zoom, { duration: 0.8 });
}
