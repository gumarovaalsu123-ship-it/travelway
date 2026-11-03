import React from 'react';
import { Link } from '@inertiajs/react';

export default function Footer() {
    return (
        <footer className = "footer">
            <div className="container">
                <div className="footer__top">
                    <Link href="/" className="logo-group">
                        <img src="/images/logo.png" alt="Тревелвей" className="logo-image" width="51" height="51" />
                        <div className="logo-text-group">
                            <span className="brand-name" style={{ color: 'white' }}>тревелвей</span>
                            <span className="brand-tagline">маршруты</span>
                        </div>
                    </Link>
                    <div className="footer__nav">
                        <Link href="/interactive-map">Интерактивная карта</Link>
                        <Link href="/my-trips">Мои путешествия</Link>
                        <Link href="#">Избранное</Link>
                        <Link href="#">Аккаунт</Link>
                    </div>
                    <div className="footer__desc">
                        Современный сервис для организации идеального отдыха. Прорабатывайте каждую деталь своего приключения без стресса и лишних вкладок.
                    </div>
                </div>
                <div className="footer__bottom">
                    <p className="copyright">© 2026 Тревелвей.</p>
                    <div className="legal-links">
                        <Link href="#">Политика конфиденциальности</Link>
                        <Link href="#">Условия использования</Link>
                    </div>
                </div>
            </div>
        </footer >
    );
}
