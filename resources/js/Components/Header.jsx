import React, { useEffect, useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import Avatar from './Profile/Avatar';

const navItems = [
    { href: '/interactive-map', label: 'Интерактивная карта' },
    { href: '/my-trips', label: 'Мои путешествия' },
    { href: '/favorites', label: 'Избранное' },
];

/**
 * Меню пользователя справа в шапке: аватар с именем, по клику — Профиль, Админ-панель (только админу) и Выход.
 * Закрывается кликом мимо меню и клавишей Esc.
 * На планшете и телефоне пункты показываются сразу списком внутри панели бургера (см. app.css «МЕНЮ ПОЛЬЗОВАТЕЛЯ»).
 */
function UserMenu({ user, isAdmin }) {
    const [isOpen, setIsOpen] = useState(false);
    const menuRef = useRef(null);

    useEffect(() => {
        if (!isOpen) {
            return undefined;
        }

        const closeOnOutsideClick = (event) => {
            if (menuRef.current && !menuRef.current.contains(event.target)) {
                setIsOpen(false);
            }
        };
        const closeOnEscape = (event) => {
            if (event.key === 'Escape') {
                setIsOpen(false);
            }
        };

        document.addEventListener('mousedown', closeOnOutsideClick);
        document.addEventListener('keydown', closeOnEscape);

        // Снимаем обработчики, когда меню закрылось, — иначе они копились бы при каждом открытии.
        return () => {
            document.removeEventListener('mousedown', closeOnOutsideClick);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, [isOpen]);

    return (
        <div className="user-menu" ref={menuRef}>
            <button
                type="button"
                className="user-menu__toggle"
                aria-haspopup="true"
                aria-expanded={isOpen}
                onClick={() => setIsOpen((open) => !open)}
            >
                <Avatar avatarUrl={user.avatarUrl} initials={user.initials} className="user-menu__avatar" />
                <span className="user-menu__name">{user.name}</span>
                <svg className="user-menu__caret" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                    <polyline points="6 9 12 15 18 9" />
                </svg>
            </button>

            <div className={`user-menu__dropdown ${isOpen ? 'is-open' : ''}`}>
                <div className="user-menu__info">
                    <Avatar avatarUrl={user.avatarUrl} initials={user.initials} className="user-menu__avatar" />
                    <div className="user-menu__info-text">
                        <span className="user-menu__info-name">{user.name}</span>
                        <span className="user-menu__info-email">{user.email}</span>
                    </div>
                </div>
                <Link href="/profile" className="user-menu__item">Профиль</Link>
                {/* Ссылка только для администратора; сам вход в /admin всё равно проверяет сервер. */}
                {isAdmin && (
                    <Link href="/admin" className="user-menu__item user-menu__item--admin">
                        Админ-панель
                        <span className="badge--light">админ</span>
                    </Link>
                )}
                <Link href="/logout" method="post" as="button" className="user-menu__item user-menu__item--logout">
                    Выйти
                </Link>
            </div>
        </div>
    );
}

/**
 * Шапка сервиса: на всех внутренних страницах и на главной, если пользователь вошёл.
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
                        </nav>
                    )}

                    {auth.user ? (
                        <UserMenu user={auth.user} isAdmin={auth.isAdmin} />
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
