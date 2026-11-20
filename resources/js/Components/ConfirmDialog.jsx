import React, { useEffect, useRef } from 'react';
import Modal from './Modal';

/**
 * Окно «Вы уверены?» вместо системного window.confirm: заголовок, пояснение и две кнопки.
 * Само по себе не открывается — его показывает функция confirmDialog() из utils/confirm.jsx.
 *
 * danger — действие необратимое (удаление), тогда кнопка подтверждения оранжевая.
 */
export default function ConfirmDialog({ title, message, confirmLabel = 'Да', cancelLabel = 'Отмена', danger = false, onConfirm, onCancel }) {
    const confirmButton = useRef(null);

    // Фокус сразу на кнопке подтверждения: Enter подтверждает, Esc (в Modal) — отменяет.
    useEffect(() => {
        confirmButton.current?.focus();
    }, []);

    return (
        <Modal title={title} onClose={onCancel}>
            {message && <p className="confirm-dialog__message">{message}</p>}
            <div className="confirm-dialog__actions">
                <button type="button" className="btn--outline-dark" onClick={onCancel}>{cancelLabel}</button>
                <button
                    type="button"
                    ref={confirmButton}
                    className={danger ? 'btn--danger-solid' : 'btn--solid-dark'}
                    onClick={onConfirm}
                >
                    {confirmLabel}
                </button>
            </div>
        </Modal>
    );
}
