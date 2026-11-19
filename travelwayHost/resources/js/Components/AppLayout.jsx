import React from 'react';
import { Head } from '@inertiajs/react';
import Header from './Header';
import Footer from './Footer';
import Toast from './Toast';

/**
 * Общий каркас страниц личного кабинета: шапка, содержимое, подвал и уведомления.
 */
export default function AppLayout({ title, children }) {
    return (
        <div className="page-bg-light">
            <Head title={`${title} | Тревелвей`} />
            <Header />
            {children}
            <Footer />
            <Toast />
        </div>
    );
}
