import { useEffect, useRef } from 'react';
import { useMap } from 'react-leaflet';

/**
 * При первой загрузке вписывает карту так, чтобы были видны все сохранённые места.
 * Если передано focusPlace (переход «Показать на карте»), центрирует карту на нём.
 */
export default function FitToPlaces({ places, focusPlace = null }) {
    const map = useMap();
    const hasFitted = useRef(false);

    useEffect(() => {
        if (hasFitted.current || places.length === 0) {
            return;
        }

        hasFitted.current = true;

        if (focusPlace) {
            map.setView([focusPlace.lat, focusPlace.lng], 16);
        } else if (places.length === 1) {
            map.setView([places[0].lat, places[0].lng], 15);
        } else {
            map.fitBounds(places.map((place) => [place.lat, place.lng]), { padding: [60, 60], maxZoom: 15 });
        }
    }, [map, places]);

    return null;
}
