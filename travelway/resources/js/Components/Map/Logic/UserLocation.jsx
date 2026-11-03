import React, { useState, useEffect } from 'react';
import { Marker, Popup, useMap } from 'react-leaflet';
import L from 'leaflet';

// Создаем HTML-маркер (без картинок, только CSS)
const userLocationIcon = L.divIcon({
    className: 'custom-user-location',
    html: `
        <div class="user-loc-dot"></div>
        <div class="user-loc-pulse"></div>
    `,
    iconSize: [20, 20],
    iconAnchor: [10, 10], // Центрируем ровно по координатам
});

export default function UserLocation() {
    const [position, setPosition] = useState(null);
    const map = useMap();

    useEffect(() => {
        // Запускаем отслеживание геопозиции в реальном времени
        map.locate({
            watch: true, // Постоянно следить за изменениями
            enableHighAccuracy: true, // Использовать GPS устройства, если доступно
            setView: false // Не будем принудительно двигать камеру при каждом шаге, чтобы не бесить пользователя
        });

        // Слушатель: когда координаты найдены или обновились
        const onLocationFound = (e) => {
            setPosition(e.latlng);
        };

        // Слушатель: если пользователь запретил доступ к геоданным
        const onLocationError = (e) => {
            console.warn("Доступ к геолокации запрещен или недоступен:", e.message);
        };

        map.on('locationfound', onLocationFound);
        map.on('locationerror', onLocationError);

        // Очистка при размонтировании компонента
        return () => {
            map.stopLocate();
            map.off('locationfound', onLocationFound);
            map.off('locationerror', onLocationError);
        };
    }, [map]);

    // Если координаты пока не получены — ничего не рендерим
    if (!position) return null;

    
    return (
        // Подключаем нашу новую иконку вот тут -> icon={userLocationIcon}
        <Marker position={position} icon={userLocationIcon}>
            <Popup>Вы находитесь здесь!</Popup>
        </Marker>
    );
}
