import React, { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';

// Сколько уведомление висит на экране и сколько длится анимация ухода (как у .toast--leaving в app.css).
const SHOW_MS = 3500;
const LEAVE_MS = 300;

/**
 * Показывает короткое уведомление из flash-данных сервера (Inertia::flash('toast', ...)).
 * Срабатывает и после перехода на другую страницу, и после запроса на той же странице.
 *
 * Анимация: при появлении — класс .toast (выплывает снизу справа), за LEAVE_MS до скрытия добавляется
 * .toast--leaving (уезжает и тает), после чего уведомление убирается со страницы.
 */
export default function Toast() {
    const { flash } = usePage();
    // id нужен, чтобы новое уведомление с тем же текстом всё равно перезапустило таймеры и анимацию.
    const [message, setMessage] = useState(null);
    const [isLeaving, setLeaving] = useState(false);

    useEffect(() => {
        if (flash?.toast) {
            setMessage({ text: flash.toast, id: Date.now() });
            setLeaving(false);
        }
    }, [flash]);

    useEffect(() => {
        if (!message) {
            return undefined;
        }

        const leaveTimer = setTimeout(() => setLeaving(true), SHOW_MS);
        const hideTimer = setTimeout(() => setMessage(null), SHOW_MS + LEAVE_MS);

        return () => {
            clearTimeout(leaveTimer);
            clearTimeout(hideTimer);
        };
    }, [message]);

    if (!message) {
        return null;
    }

    // key: новое уведомление — новый элемент, поэтому анимация появления проигрывается заново.
    return (
        <div key={message.id} className={`toast ${isLeaving ? 'toast--leaving' : ''}`} role="status">
            {message.text}
        </div>
    );
}
