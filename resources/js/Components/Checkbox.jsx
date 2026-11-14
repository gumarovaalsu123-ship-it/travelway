import React from 'react';

/**
 * Галочка в стиле сайта (классы custom-checkbox из app.css) с подписью справа.
 */
export default function Checkbox({ id, label, checked, onChange }) {
    return (
        <label className="custom-checkbox" htmlFor={id}>
            <input
                id={id}
                type="checkbox"
                className="custom-checkbox__input"
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
            />
            <span className="custom-checkbox__box">
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">
                    <polyline points="20 6 9 17 4 12" />
                </svg>
            </span>
            <span className="custom-checkbox__label">{label}</span>
        </label>
    );
}
