import React from 'react';
import { createRoot } from 'react-dom/client';
import ConfirmDialog from '../Components/ConfirmDialog';

/**
 * Показывает окно подтверждения и ждёт ответа — замена window.confirm.
 * Возвращает Promise: true — пользователь подтвердил, false — отменил (кнопкой, крестиком, Esc или кликом по фону).
 *
 * Пример: if (await confirmDialog({ title: 'Удалить поездку?', confirmLabel: 'Удалить', danger: true })) { ... }
 *
 * Окно рисуется в отдельном div в конце <body>, поэтому его можно вызвать с любой страницы,
 * не добавляя компонент в каждый layout. После ответа div удаляется.
 *
 * @param {{ title: string, message?: string, confirmLabel?: string, cancelLabel?: string, danger?: boolean }} options
 * @returns {Promise<boolean>}
 */
export function confirmDialog(options) {
    return new Promise((resolve) => {
        const container = document.createElement('div');
        document.body.appendChild(container);
        const root = createRoot(container);

        const close = (answer) => {
            root.unmount();
            container.remove();
            resolve(answer);
        };

        root.render(<ConfirmDialog {...options} onConfirm={() => close(true)} onCancel={() => close(false)} />);
    });
}
