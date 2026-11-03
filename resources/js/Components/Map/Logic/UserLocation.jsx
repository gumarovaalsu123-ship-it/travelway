
import React, { useState, useEffect } from 'react';
import { Marker, Popup, useMap } from 'react-leaflet';
import L from 'leaflet';

const userLocationIcon = L.divIcon({
    className: 'custom-user-location',
    html: `
        <div class="user-loc-dot"></div>
        <div class="user-loc-pulse"></div>
    `,
    iconSize: [20, 20],
    iconAnchor: [10, 10],
});

export default function UserLocation() {
    const [position, setPosition] = useState(null);
    const map = useMap();

    useEffect(() => {
        const onLocationFound = (e) => {
            console.log('Координаты получены Leaflet:', e.latlng);
            setPosition(e.latlng);
        };

        const onLocationError = (e) => {
            console.warn('Ошибка геолокации:', e.message);
        };

        // Сначала подписываемся на события
        map.on('locationfound', onLocationFound);
        map.on('locationerror', onLocationError);

        // Только после этого запускаем геолокацию
        map.locate({
            watch: true,
            enableHighAccuracy: true,
            setView: false,
        });

        return () => {
            map.stopLocate();
            map.off('locationfound', onLocationFound);
            map.off('locationerror', onLocationError);
        };
    }, [map]);

    if (!position) return null;

    return (
        <Marker position={position} icon={userLocationIcon}>
            <Popup>Вы находитесь здесь!</Popup>
        </Marker>
    );
}
