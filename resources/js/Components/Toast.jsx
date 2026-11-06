import React, { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';

/**
 * Показывает короткое уведомление из flash-данных сервера (Inertia::flash('toast', ...)).
 * Срабатывает и после перехода на другую страницу, и после запроса на той же странице.
 */
export default function Toast() {
    const { flash } = usePage();
    const [message, setMessage] = useState(null);

    useEffect(() => {
        if (flash?.toast) {
            setMessage(flash.toast);
        }
    }, [flash]);

    useEffect(() => {
        if (!message) {
            return undefined;
        }

        const timer = setTimeout(() => setMessage(null), 3500);

        return () => clearTimeout(timer);
    }, [message]);

    return message ? <div className="toast" role="status">{message}</div> : null;
}
