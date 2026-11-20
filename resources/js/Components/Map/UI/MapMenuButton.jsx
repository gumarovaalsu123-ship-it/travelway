import React, { useEffect, useRef, useState } from 'react';

/**
 * Квадратная кнопка-иконка на карте с выпадающим меню (категории поиска, фильтр «Мои места»).
 *
 * icon — svg на кнопке, label — подпись для экранного диктора и всплывающая подсказка,
 * title — заголовок над пунктами, isMarked — оранжевая точка на кнопке (что-то выбрано или скрыто),
 * align — к какому краю кнопки прижать меню: 'right' (по умолчанию) или 'left'.
 * items — [{ value, label }], selected — выбранный value (или null), onSelect(value) — нажали на пункт.
 * Закрывается выбором пункта, кликом мимо и клавишей Esc (как меню пользователя в шапке).
 */
export default function MapMenuButton({ icon, label, title, isMarked = false, align = 'right', className = '', items, selected, onSelect }) {
    const [isOpen, setIsOpen] = useState(false);
    const menuRef = useRef(null);

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

    const choose = (value) => {
        onSelect(value);
        setIsOpen(false);
    };

    return (
        <div className={`map-menu ${className}`} ref={menuRef}>
            <button
                type="button"
                className={`map-menu__toggle ${isMarked ? 'map-menu__toggle--marked' : ''}`}
                aria-haspopup="true"
                aria-expanded={isOpen}
                aria-label={label}
                title={label}
                onClick={() => setIsOpen((open) => !open)}
            >
                {icon}
            </button>

            {isOpen && (
                <div className={`map-menu__dropdown map-menu__dropdown--${align}`} role="menu">
                    {title && <div className="map-menu__title">{title}</div>}
                    {items.map((item) => (
                        <button
                            key={item.value}
                            type="button"
                            role="menuitemradio"
                            aria-checked={selected === item.value}
                            className={`map-menu__item ${selected === item.value ? 'is-active' : ''}`}
                            onClick={() => choose(item.value)}
                        >
                            {item.label}
                            {selected === item.value && <span aria-hidden="true">✓</span>}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
