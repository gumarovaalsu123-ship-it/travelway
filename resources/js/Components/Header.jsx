import React from 'react';
import { Link } from '@inertiajs/react';

export default function Header() {
    return (
        <header className="navbar">
            <div className="container navbar__inner">
                <Link href="/" className="logo-group">
                    <img src="/images/logo.png" alt="Тревелвей" className="logo-image" width="51" height="51" />
                    <div className="logo-text-group">
                        <span className="brand-name">тревелвей</span>
                        <span className="brand-tagline">маршруты</span>
                    </div>
                </Link>
                <nav className="navbar__nav">
                    <Link href="/interactive-map" className="nav-link active">Интерактивная карта</Link>
                    <Link href="/my-trips" className="nav-link">Мои путешествия</Link>
                    <Link href="#" className="nav-link">Избранное</Link>
                    <Link href="#" className="nav-link">Профиль</Link>
                </nav>
            </div>
        </header >
    );
}
