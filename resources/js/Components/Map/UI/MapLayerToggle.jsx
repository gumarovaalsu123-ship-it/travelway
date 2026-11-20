import React, { useEffect, useRef, useState } from 'react';

export const MAP_LAYERS = [
    { value: 'all', label: 'Все места' },
    { value: 'trips', label: 'В поездках' },
    { value: 'favorites', label: 'Избранное' },
    { value: 'hidden', label: 'Скрыть мои места' },
];

/**
 * Какие сохранённые места показывать на карте: компактная кнопка-воронка рядом с поиском, по клику — список.
 * Пока выбран не «Все места», на кнопке горит точка, чтобы было видно, что часть мест скрыта.
 * Закрывается выбором пункта, кликом мимо и клавишей Esc (как меню пользователя в шапке).
 */
export default function MapLayerToggle({ value, onChange }) {
    const [isOpen, setIsOpen] = useState(false);
    const menuRef = useRef(null);
    const current = MAP_LAYERS.find((layer) => layer.value === value) ?? MAP_LAYERS[0];

    useEffect(() => {
        if (!isOpen) {
            return undefined;
        }

        const closeOnOutsideClick = (event) => {
            if (menuRef.current && !menuRef.current.contains(event.target)) {
                setIsOpen(false);
            }
        };
        const closeOnEscape = (event) => {
            if (event.key === 'Escape') {
                setIsOpen(false);
            }
        };

        document.addEventListener('mousedown', closeOnOutsideClick);
        document.addEventListener('keydown', closeOnEscape);

        return () => {
            document.removeEventListener('mousedown', closeOnOutsideClick);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, [isOpen]);

    const choose = (layerValue) => {
        onChange(layerValue);
        setIsOpen(false);
    };

    return (
        <div className="map-layer-menu" ref={menuRef}>
            <button
                type="button"
                className={`map-layer-menu__toggle ${value !== 'all' ? 'map-layer-menu__toggle--filtered' : ''}`}
                aria-haspopup="true"
                aria-expanded={isOpen}
                aria-label={`Какие места показывать: ${current.label}`}
                title={current.label}
                onClick={() => setIsOpen((open) => !open)}
            >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3" />
                </svg>
            </button>

            {isOpen && (
                <div className="map-layer-menu__dropdown" role="menu">
                    <div className="map-layer-menu__title">Мои места на карте</div>
                    {MAP_LAYERS.map((layer) => (
                        <button
                            key={layer.value}
                            type="button"
                            role="menuitemradio"
                            aria-checked={value === layer.value}
                            className={`map-layer-menu__item ${value === layer.value ? 'is-active' : ''}`}
                            onClick={() => choose(layer.value)}
                        >
                            {layer.label}
                            {value === layer.value && <span aria-hidden="true">✓</span>}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
