import React from 'react';

export const MAP_LAYERS = [
    { value: 'all', label: 'Все места' },
    { value: 'trips', label: 'В поездках' },
    { value: 'favorites', label: 'Избранное' },
];

export default function MapLayerToggle({ value, onChange }) {
    return (
        <div className="map-layer-toggle" role="group" aria-label="Какие места показывать">
            {MAP_LAYERS.map((layer) => (
                <button
                    key={layer.value}
                    type="button"
                    className={`layer-btn ${value === layer.value ? 'active' : 'inactive'}`}
                    aria-pressed={value === layer.value}
                    onClick={() => onChange(layer.value)}
                >
                    {layer.label}
                </button>
            ))}
        </div>
    );
}
