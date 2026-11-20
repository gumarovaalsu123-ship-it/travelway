import React, { useRef, useState } from 'react';
import { router } from '@inertiajs/react';

/**
 * Поиск в шапке страниц админки («Пользователи», «Публичные маршруты»): ищет по Enter.
 *
 * url — адрес страницы списка, search — текущий запрос с сервера (?search=…), placeholder — подсказка в поле.
 * Крестик справа — свой, вместо встроенного в браузер: стирает текст, а если поиск уже применён,
 * сразу показывает полный список.
 */
export default function AdminSearch({ url, search, placeholder }) {
    const [query, setQuery] = useState(search ?? '');
    const inputRef = useRef(null);

    // Новый поиск — снова с первой страницы. replace: не засоряем историю браузера каждым поиском.
    const load = (text) => router.get(url, text ? { search: text } : {}, { preserveState: true, replace: true });

    const submit = (event) => {
        event.preventDefault();
        load(query.trim());
    };

    const clear = () => {
        setQuery('');
        inputRef.current?.focus();

        if (search) {
            load('');
        }
    };

    return (
        <form className="admin-search-wrapper" onSubmit={submit} role="search">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                <circle cx="11" cy="11" r="8" />
                <line x1="21" y1="21" x2="16.65" y2="16.65" />
            </svg>
            <input
                ref={inputRef}
                type="search"
                className="admin-search-input"
                placeholder={placeholder}
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                aria-label={placeholder}
            />
            {query && (
                <button type="button" className="admin-search-clear" onClick={clear} aria-label="Очистить поиск" title="Очистить">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                </button>
            )}
        </form>
    );
}
