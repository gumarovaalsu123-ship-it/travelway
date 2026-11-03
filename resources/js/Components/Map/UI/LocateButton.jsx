
import React, { useState } from 'react';
import { useMap } from 'react-leaflet';

export default function LocateButton() {
    const map = useMap();
    const [loading, setLoading] = useState(false);

    const handleLocate = () => {
        if (!navigator.geolocation) {
            alert('Ваш браузер не поддерживает геолокацию');
            return;
        }

        setLoading(true);

        navigator.geolocation.getCurrentPosition(
            (position) => {
                const { latitude, longitude } = position.coords;

                map.flyTo(
                    [latitude, longitude],
                    16,
                    { duration: 1 }
                );

                setLoading(false);
            },
            (error) => {
                console.error('Ошибка геолокации:', error);
                setLoading(false);

                alert('Не удалось определить ваше местоположение');
            },
            {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 0,
            }
        );
    };

    return (
        <button
            type="button"
            onClick={handleLocate}
            disabled={loading}
            title="Моё местоположение"
            aria-label="Перейти к моему местоположению"
            style={{
                position: 'absolute',
                top: '15px',
                right: '15px',
                zIndex: 1000,
                width: '42px',
                height: '42px',
                border: 'none',
                borderRadius: '10px',
                background: '#fff',
                boxShadow: '0 2px 8px rgba(0,0,0,0.2)',
                cursor: loading ? 'wait' : 'pointer',
                fontSize: '21px',
            }}
        >
            {loading ? '…' : '◎'}
        </button>
    );
}
