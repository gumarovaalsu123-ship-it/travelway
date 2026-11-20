import React from 'react';
import { Link } from '@inertiajs/react';

/**
 * Переключатель страниц в таблицах админки. paginator — то, что отдаёт Laravel-пагинатор: current_page, last_page,
 * prev_page_url, next_page_url. Адреса уже содержат ?search=..., потому что на сервере withQueryString().
 */
export default function AdminPagination({ paginator }) {
    if (paginator.last_page <= 1) {
        return null;
    }

    return (
        <nav className="admin-pagination">
            {paginator.prev_page_url
                ? <Link href={paginator.prev_page_url} className="admin-action" preserveScroll>← Назад</Link>
                : <span className="admin-action admin-action--disabled">← Назад</span>}
            <span className="admin-pagination__info">Страница {paginator.current_page} из {paginator.last_page}</span>
            {paginator.next_page_url
                ? <Link href={paginator.next_page_url} className="admin-action" preserveScroll>Вперёд →</Link>
                : <span className="admin-action admin-action--disabled">Вперёд →</span>}
        </nav>
    );
}
