import React, { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';

const navItems = [
    { href: '/interactive-map', label: 'Интерактивная карта' },
    { href: '/my-trips', label: 'Мои путешествия' },
    { href: '/favorites', label: 'Избранное' },
];

/**
 * Шапка внутренних страниц.
 * На широком экране меню стоит в строку. На планшете и телефоне (до 1024px, см. app.css «АДАПТИВ: ШАПКА»)
 * меню прячется за кнопку-бургер и выпадает панелью под шапкой.
 * Закрывать меню после перехода не нужно: каждая страница рендерит шапку заново, и состояние сбрасывается.
 */
export default function Header() {
    const { props, url } = usePage();
    const auth = props.auth ?? {};
    const [isMenuOpen, setIsMenuOpen] = useState(false);

    return (
        <header className="navbar">
            <div className="container navbar__inner">
                <Link href="/" className="logo-group">
                    <img src="/images/logo.png" alt="Тревелвей" className="logo-image" width="51" height="51" />
                    <div className="logo-text-group">
                        <span className="brand-name">тревелвей</span>
                        <span className="brand-tagline">планировщик</span>
                    </div>
                </Link>

                <button
                    type="button"
                    className={`burger-btn ${isMenuOpen ? 'is-open' : ''}`}
                    aria-label={isMenuOpen ? 'Закрыть меню' : 'Открыть меню'}
                    aria-expanded={isMenuOpen}
                    onClick={() => setIsMenuOpen((open) => !open)}
                >
                    <span />
                    <span />
                    <span />
                </button>

                {/* На компьютере эта обёртка «прозрачна» (display: contents), на мобильном — выпадающая панель. */}
                <div className={`navbar__menu ${isMenuOpen ? 'is-open' : ''}`}>
                    {auth.user && (
                        <nav className="navbar__nav">
                            {navItems.map((item) => (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    className={`nav-link ${url.startsWith(item.href) ? 'active' : ''}`}
                                >
                                    {item.label}
                                </Link>
                            ))}
                            {/* Ссылка только для администратора; сам вход в /admin всё равно проверяет сервер. */}
                            {auth.isAdmin && (
                                <Link href="/admin" className="nav-link">Админ-панель</Link>
                            )}
                        </nav>
                    )}

                    {auth.user ? (
                        <div className="navbar__user">
                            <Link href="/profile" className="navbar__user-name" title="Профиль">{auth.user.name}</Link>
                            <Link href="/logout" method="post" as="button" className="nav-link nav-logout">
                                Выйти
                            </Link>
                        </div>
                    ) : (
                        <div className="auth-actions">
                            <Link href="/login" className="btn btn--outline">Войти</Link>
                            <Link href="/register" className="btn btn--solid">Регистрация</Link>
                        </div>
                    )}
                </div>
            </div>
        </header>
    );
}
