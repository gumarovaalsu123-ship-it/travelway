
import React, { useState } from 'react';
import { useMap } from 'react-leaflet';

export default function CustomZoomControl() {
    const map = useMap();
    const [loading, setLoading] = useState(false);

    const handleLocate = () => {
        if (!navigator.geolocation) {
            alert('Ваш браузер не поддерживает геолокацию');
            return;
        }

        setLoading(true);

        navigator.geolocation.getCurrentPosition(
            ({ coords }) => {
                map.flyTo(
                    [coords.latitude, coords.longitude],
                    16,
                    { duration: 1 }
                );
                setLoading(false);
            },
            (error) => {
                console.error('Ошибка геолокации:', error);
                alert('Не удалось определить местоположение');
                setLoading(false);
            },
            {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 0,
            }
        );
    };

    return (
        <div className="map-zoom-controls">
            <button
                type="button"
                className="zoom-btn"
                onClick={() => map.zoomIn()}
                title="Увеличить"
            >
                +
            </button>

            <button
                type="button"
                className="zoom-btn"
                onClick={() => map.zoomOut()}
                title="Уменьшить"
            >
                −
            </button>

            <button
                type="button"
                className="zoom-btn"
                onClick={handleLocate}
                disabled={loading}
                title="Моё местоположение"
            >
                {loading ? '…' : (
                    <svg width="18" height="18" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor" strokeWidth="2"
                        strokeLinecap="round" strokeLinejoin="round">
                        <circle cx="12" cy="12" r="10" />
                        <circle cx="12" cy="12" r="3" />
                        <line x1="12" y1="2" x2="12" y2="4" />
                        <line x1="12" y1="20" x2="12" y2="22" />
                        <line x1="20" y1="12" x2="22" y2="12" />
                        <line x1="2" y1="12" x2="4" y2="12" />
                    </svg>
                )}
            </button>
        </div>
    );
}
