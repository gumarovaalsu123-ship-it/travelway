import { useEffect, useRef } from 'react';
import { useMap } from 'react-leaflet';

/**
 * При первой загрузке вписывает карту так, чтобы были видны все сохранённые места.
 */
export default function FitToPlaces({ places }) {
    const map = useMap();
    const hasFitted = useRef(false);

    useEffect(() => {
        if (hasFitted.current || places.length === 0) {
            return;
        }

        hasFitted.current = true;

        if (places.length === 1) {
            map.setView([places[0].lat, places[0].lng], 15);
        } else {
            map.fitBounds(places.map((place) => [place.lat, place.lng]), { padding: [60, 60], maxZoom: 15 });
        }
    }, [map, places]);

    return null;
}
