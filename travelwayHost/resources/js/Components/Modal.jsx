import React, { useEffect } from 'react';

/**
 * Модальное окно поверх страницы: затемнённый фон, заголовок и крестик.
 * Закрывается по Esc, по клику на фон и по крестику — всё это вызывает onClose.
 * Пока окно открыто, страница под ним не прокручивается.
 */
export default function Modal({ title, onClose, children, wide = false }) {
    useEffect(() => {
        const closeOnEscape = (event) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };

        document.addEventListener('keydown', closeOnEscape);
        document.body.style.overflow = 'hidden';

        // Окно закрыли — убираем обработчик и возвращаем прокрутку страницы.
        return () => {
            document.removeEventListener('keydown', closeOnEscape);
            document.body.style.overflow = '';
        };
    }, [onClose]);

    return (
        // Закрываем, только если нажали именно на фон (target — сам фон), а не внутри окна.
        // mousedown, а не click: иначе окно закрылось бы, когда выделяешь текст в поле и отпускаешь мышь над фоном.
        <div className="modal-backdrop" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <div className={`modal ${wide ? 'modal--wide' : ''}`} role="dialog" aria-modal="true" aria-label={title}>
                <header className="modal__header">
                    <h3 className="modal__title">{title}</h3>
                    <button type="button" className="btn-close" aria-label="Закрыть" onClick={onClose}>×</button>
                </header>
                {children}
            </div>
        </div>
    );
}
