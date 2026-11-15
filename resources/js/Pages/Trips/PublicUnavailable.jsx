import React from 'react';
import { Head, Link } from '@inertiajs/react';

/**
 * «Маршрут недоступен» (HTTP 404): ссылка неверная или владелец закрыл доступ (PublicTripController).
 * Причину нарочно не уточняем — по странице нельзя понять, существовала ли поездка.
 */
export default function PublicUnavailable() {
    return (
        <div className="page-bg-light">
            <Head title="Маршрут недоступен | Тревелвей">
                <meta name="robots" content="noindex, nofollow" />
            </Head>

            {/* Упрощённая шапка без навигации, как в макете. */}
            <header className="navbar">
                <div className="container navbar__inner">
                    <Link href="/" className="logo-group">
                        <img src="/images/logo.png" alt="Тревелвей" className="logo-image" width="51" height="51" />
                        <div className="logo-text-group">
                            <span className="brand-name">тревелвей</span>
                            <span className="brand-tagline">маршруты</span>
                        </div>
                    </Link>
                </div>
            </header>

            <main className="container error-page-main">
                <div className="error-card">
                    <div className="error-card__header">
                        <span className="badge badge--light">Публичный маршрут</span>
                        <h1 className="error-card__title">Маршрут недоступен</h1>
                        <p className="error-card__desc">Владелец отключил публичный доступ или ссылка недействительна.</p>
                    </div>

                    <hr className="form-divider-line" style={{ margin: 0, width: '100%' }} />

                    <div className="recommendations">
                        <h3 className="recommendations__title">Что можно сделать</h3>

                        <div className="recommendations-grid">
                            <div className="recommendation-card">
                                <div className="recommendation-card__header">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
                                        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
                                    </svg>
                                    Проверьте ссылку
                                </div>
                                <p className="recommendation-card__desc">Убедитесь, что ссылка скопирована полностью, без пропущенных символов.</p>
                            </div>

                            <div className="recommendation-card">
                                <div className="recommendation-card__header">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                        <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" />
                                    </svg>
                                    Уточните у владельца
                                </div>
                                <p className="recommendation-card__desc">Попросите владельца прислать актуальную ссылку на маршрут.</p>
                            </div>
                        </div>
                    </div>

                    <div className="error-card__actions">
                        <Link href="/" className="btn btn--solid-dark">На главную</Link>
                        <p className="error-card__hint">Для просмотра по публичной ссылке вход не нужен.</p>
                    </div>
                </div>
            </main>

            <footer className="footer footer--minimal">
                <div className="container footer__minimal-inner">
                    <div className="footer__minimal-brand">
                        <span className="brand-name" style={{ color: 'white', fontSize: 22 }}>тревелвей</span>
                        <span className="footer__minimal-tag">Гостевой просмотр</span>
                    </div>
                    <div className="footer__minimal-copyright">© 2026 Тревелвей</div>
                </div>
            </footer>
        </div>
    );
}
