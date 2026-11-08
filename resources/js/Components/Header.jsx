import React from 'react';
import { Link, usePage } from '@inertiajs/react';

const navItems = [
    { href: '/interactive-map', label: 'Интерактивная карта' },
    { href: '/my-trips', label: 'Мои путешествия' },
    { href: '/favorites', label: 'Избранное' },
];

export default function Header() {
    const { props, url } = usePage();
    const auth = props.auth ?? {};

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
                    </nav>
                )}

                {auth.user ? (
                    <div className="navbar__user">
                        <span className="navbar__user-name">{auth.user.name}</span>
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
        </header>
    );
}
