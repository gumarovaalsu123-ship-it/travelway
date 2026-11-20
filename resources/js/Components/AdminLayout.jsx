import React from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import Toast from './Toast';

const navItems = [
    {
        href: '/admin',
        label: 'Главная',
        // Главная активна только на самом /admin, а не на /admin/users.
        isActive: (url) => url === '/admin',
        icon: (
            <>
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                <polyline points="9 22 9 12 15 12 15 22" />
            </>
        ),
    },
    {
        href: '/admin/users',
        label: 'Пользователи',
        isActive: (url) => url.startsWith('/admin/users'),
        icon: (
            <>
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                <circle cx="9" cy="7" r="4" />
                <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                <path d="M16 3.13a4 4 0 0 1 0 7.75" />
            </>
        ),
    },
    {
        href: '/admin/trips',
        label: 'Публичные маршруты',
        isActive: (url) => url.startsWith('/admin/trips'),
        icon: (
            <>
                <circle cx="12" cy="12" r="10" />
                <line x1="2" y1="12" x2="22" y2="12" />
                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" />
            </>
        ),
    },
    {
        href: '/my-trips',
        label: 'На сайт',
        isActive: () => false,
        icon: <polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6" />,
    },
];

/**
 * Каркас админ-панели по макету admin.html: тёмный сайдбар слева, справа — заголовок и содержимое.
 * `actions` — то, что стоит в шапке справа от заголовка (например, поиск).
 */
export default function AdminLayout({ title, subtitle, actions, children }) {
    const { props, url } = usePage();
    const path = url.split('?')[0];

    return (
        <div className="admin-layout">
            <Head title={`${title} | Админ-панель`} />

            <aside className="admin-sidebar">
                <div className="sidebar-brand">
                    <span className="sidebar-brand-name">тревелвей</span>
                    <span className="sidebar-brand-subtitle">Панель управления</span>
                </div>

                <nav className="sidebar-nav">
                    {navItems.map((item) => (
                        <Link
                            key={item.href}
                            href={item.href}
                            className={`sidebar-link ${item.isActive(path) ? 'active' : ''}`}
                        >
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                {item.icon}
                            </svg>
                            <span>{item.label}</span>
                        </Link>
                    ))}
                </nav>

                <div className="sidebar-spacer" />

                <div className="sidebar-user">
                    <div className="sidebar-user-info">
                        <span className="sidebar-user-name">{props.auth.user.name}</span>
                        <Link href="/logout" method="post" as="button" className="sidebar-logout admin-logout">
                            Выйти
                        </Link>
                    </div>
                </div>
            </aside>

            <main className="admin-main">
                <header className="admin-header">
                    <div className="admin-header__titles">
                        <h1 className="admin-title">{title}</h1>
                        {subtitle && <p className="admin-subtitle">{subtitle}</p>}
                    </div>
                    {actions}
                </header>

                {children}
            </main>

            <Toast />
        </div>
    );
}
