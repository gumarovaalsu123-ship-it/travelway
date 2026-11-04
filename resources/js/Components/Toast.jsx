import React, { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';

/**
 * Показывает короткое уведомление из flash-данных сервера (Inertia::flash('toast', ...)).
 */
export default function Toast() {
    const [message, setMessage] = useState(null);

    useEffect(() => router.on('flash', (event) => {
        if (event.detail.flash?.toast) {
            setMessage(event.detail.flash.toast);
        }
    }), []);

    useEffect(() => {
        if (!message) {
            return undefined;
        }

        const timer = setTimeout(() => setMessage(null), 3000);

        return () => clearTimeout(timer);
    }, [message]);

    return message ? <div className="toast" role="status">{message}</div> : null;
}
