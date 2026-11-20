import React from 'react';
import MapMenuButton from './MapMenuButton';

export const MAP_LAYERS = [
    { value: 'all', label: 'Все места' },
    { value: 'trips', label: 'В поездках' },
    { value: 'favorites', label: 'Хочу посетить' },
    { value: 'hidden', label: 'Скрыть мои места' },
];

/**
 * Какие сохранённые места показывать: кнопка с булавкой в правом верхнем углу карты.
 * Пока выбрано не «Все места», на кнопке горит точка — видно, что часть мест скрыта.
 */
export default function MapLayerToggle({ value, onChange }) {
    const current = MAP_LAYERS.find((layer) => layer.value === value) ?? MAP_LAYERS[0];

    return (
        <MapMenuButton
            className="map-layer-menu"
            label={`Мои места на карте: ${current.label}`}
            title="Мои места на карте"
            isMarked={value !== 'all'}
            items={MAP_LAYERS}
            selected={value}
            onSelect={onChange}
            icon={(
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                    <circle cx="12" cy="10" r="3" />
                </svg>
            )}
        />
    );
}
