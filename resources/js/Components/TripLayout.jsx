import React from 'react';
import { Link } from '@inertiajs/react';
import AppLayout from './AppLayout';
import { formatDateRange, formatDuration, plural } from '../utils/format';
import { STATUS_LABELS, coverFor } from '../utils/trips';

/**
 * Вкладки страницы поездки. У каждой вкладки свой адрес (см. маршруты в routes/web.php):
 * «Обзор» — /my-trips/{id}, остальные — /my-trips/{id}/<key>.
 * Новая вкладка уже есть в этом списке: достаточно сделать для неё маршрут и страницу,
 * которая оборачивает содержимое в <TripLayout trip={trip} activeTab="<key>">.
 */
export const TRIP_TABS = [
    { key: 'overview', label: 'Обзор', path: '' },
    { key: 'plan', label: 'План по дням', path: '/plan' },
    { key: 'map', label: 'Карта', path: '/map' },
    { key: 'transport', label: 'Транспорт', path: '/transport' },
    { key: 'accommodation', label: 'Проживание', path: '/accommodation' },
    { key: 'packing', label: 'Список вещей', path: '/packing' },
    // «Настройки» — это существующая форма редактирования поездки.
    { key: 'settings', label: 'Настройки', path: '/edit' },
];

/**
 * Общий каркас страницы поездки: компактная шапка с данными поездки и вкладки.
 * `trip` — данные шапки, их отдаёт Trip::toHeader() на сервере.
 */
export default function TripLayout({ trip, activeTab, children }) {
    return (
        <AppLayout title={trip.title}>
            <section
                className="trip-hero trip-hero--compact"
                style={{ backgroundImage: `linear-gradient(0deg, rgba(30, 38, 32, 0.6), rgba(30, 38, 32, 0.6)), url('${coverFor(trip)}')` }}
            >
                <div className="container trip-hero__inner">
                    <div className="trip-hero__info">
                        <Link href="/my-trips" className="trip-hero__back">← Мои путешествия</Link>
                        <span className={`trip-hero__badge trip-hero__badge--${trip.status}`}>{STATUS_LABELS[trip.status]}</span>
                        <h1 className="trip-hero__title">{trip.title} · {formatDuration(trip.daysCount)}</h1>
                        <div className="trip-hero__meta">
                            <span className="meta-item">{trip.destination}</span>
                            <span className="meta-item">{formatDateRange(trip.startDate, trip.endDate)}</span>
                            <span className="meta-item">{trip.placesCount} {plural(trip.placesCount, ['место', 'места', 'мест'])}</span>
                        </div>
                    </div>
                </div>
            </section>

            <nav className="trip-tabs trip-tabs--white">
                <div className="container trip-tabs__inner">
                    {TRIP_TABS.map((tab) => (
                        <Link
                            key={tab.key}
                            href={`/my-trips/${trip.id}${tab.path}`}
                            className={`tab-btn ${tab.key === activeTab ? 'active' : ''}`}
                        >
                            {tab.label}
                        </Link>
                    ))}
                </div>
            </nav>

            {children}
        </AppLayout>
    );
}
