import React from 'react';
import { Head, Link } from '@inertiajs/react';

export default function AuthLayout({ title, heroImage, quote, quoteAuthor, children }) {
    return (
        <div className="auth-layout">
            <Head title={title} />

            <div className="auth-layout__left" style={{ backgroundImage: `url('${heroImage}')` }}>
                <div className="auth-quote">
                    <p className="auth-quote__text">{quote}</p>
                    {quoteAuthor && <p className="auth-quote__author">{quoteAuthor}</p>}
                </div>
            </div>

            <div className="auth-layout__right">
                <header className="auth-header">
                    <Link href="/" className="logo-group">
                        <div className="logo-symbol"><img src="/images/logo.png" alt="" /></div>
                        <div className="logo-text-group">
                            <span className="brand-name">тревелвей</span>
                            <span className="brand-tagline">маршруты</span>
                        </div>
                    </Link>
                    <Link href="/" className="auth-back-link">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M19 12H5M12 19l-7-7 7-7" />
                        </svg>
                        Назад на главную
                    </Link>
                </header>

                <main className="auth-content">
                    <div className="auth-form-wrapper">{children}</div>
                </main>
            </div>
        </div>
    );
}
