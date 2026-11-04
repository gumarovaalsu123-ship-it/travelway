import React, { useState } from 'react';

function EyeIcon({ crossed }) {
    return (
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
            <circle cx="12" cy="12" r="3" />
            {crossed && <line x1="3" y1="3" x2="21" y2="21" />}
        </svg>
    );
}

/**
 * Поле формы с подписью и текстом ошибки. Для type="password" добавляет кнопку «показать пароль».
 */
export default function FormField({ id, label, type = 'text', error, value, onChange, ...inputProps }) {
    const [isPasswordVisible, setIsPasswordVisible] = useState(false);
    const isPassword = type === 'password';

    const input = (
        <input
            id={id}
            name={id}
            type={isPassword && isPasswordVisible ? 'text' : type}
            className="form-input"
            value={value}
            onChange={(event) => onChange(event.target.value)}
            aria-invalid={Boolean(error)}
            {...inputProps}
        />
    );

    return (
        <div className={`form-group ${error ? 'has-error' : ''}`}>
            <label htmlFor={id} className="form-label">{label}</label>
            {isPassword ? (
                <div className="form-input-inner">
                    {input}
                    <button
                        type="button"
                        className="form-icon-btn"
                        aria-label={isPasswordVisible ? 'Скрыть пароль' : 'Показать пароль'}
                        onClick={() => setIsPasswordVisible((visible) => !visible)}
                    >
                        <EyeIcon crossed={isPasswordVisible} />
                    </button>
                </div>
            ) : input}
            {error && <span className="form-error">{error}</span>}
        </div>
    );
}
