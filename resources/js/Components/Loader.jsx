import React from 'react';

/**
 * Крутящийся кружок — сам по себе, например внутри кнопки на время запроса.
 * aria-hidden: для экранного диктора состояние сообщает текст рядом или атрибут disabled у кнопки.
 */
export function Spinner() {
    return <span className="spinner" aria-hidden="true" />;
}

/**
 * Единый индикатор загрузки для запросов к внешним сервисам (OpenStreetMap, Википедия, построение маршрута).
 *
 * variant="inline" — кружок и текст в одну строку (в списке результатов поиска, в подсказке над формой);
 * variant="block"  — кружок с текстом и серые «заготовки» строк (скелетон): видно, что сейчас здесь появится список.
 * rows — сколько строк-заготовок показать в блочном варианте.
 * role="status" — экранный диктор зачитает текст, когда индикатор появится.
 */
export default function Loader({ text = 'Загружаем…', variant = 'inline', rows = 3 }) {
    if (variant === 'block') {
        return (
            <div className="loader loader--block" role="status">
                <div className="loader__head">
                    <Spinner />
                    <span className="loader__text">{text}</span>
                </div>
                {Array.from({ length: rows }, (_, index) => (
                    <div key={index} className="loader__skeleton">
                        <span className="loader__line loader__line--wide" />
                        <span className="loader__line" />
                    </div>
                ))}
            </div>
        );
    }

    return (
        <span className="loader loader--inline" role="status">
            <Spinner />
            <span className="loader__text">{text}</span>
        </span>
    );
}
