import React from 'react';
import { Link } from '@inertiajs/react';
import AppLayout from './AppLayout';
import { formatDateRange, formatDuration, plural } from '../utils/format';
import { STATUS_LABELS, coverFor } from '../utils/trips';

/**
 * Все вкладки страницы поездки: название и адрес (см. маршруты в routes/web.php).
 * «Подготовка» и «Сегодня» — это сама страница /my-trips/{id}, остальные — /my-trips/{id}/<path>.
 * КАКИЕ из них показать и можно ли на них что-то менять, решает сервер по этапу поездки —
 * Trip::tabs() в app/Models/Trip.php, сюда это приходит в trip.tabs.
 */
export const TRIP_TABS = [
    // «Сегодня» есть только у текущей поездки — вместо «Подготовки».
    { key: 'today', label: 'Сегодня', path: '' },
    { key: 'overview', label: 'Подготовка', path: '' },
    // «План» — список мест дня и карта этого дня на одной странице (отдельной вкладки «Карта» нет).
    { key: 'plan', label: 'План', path: '/plan' },
    { key: 'transport', label: 'Транспорт', path: '/transport' },
    { key: 'accommodation', label: 'Проживание', path: '/accommodation' },
    { key: 'packing', label: 'Список вещей', path: '/packing' },
    { key: 'advance', label: 'Заранее', path: '/advance' },
    // «Настройки» — это существующая форма редактирования поездки.
    { key: 'settings', label: 'Настройки', path: '/edit' },
];

/**
 * Можно ли менять данные на вкладке tabKey (по trip.tabs из Trip::tabs()).
 * Каждая страница вызывает это сама и не рендерит кнопки и формы изменения, если нельзя,
 * а галочки делает disabled. Кнопки просмотра (переключатель дней, фильтры, «Что рядом?») работают всегда.
 */
export const canEditTab = (trip, tabKey) => trip.tabs[tabKey] === true;

/**
 * Общий каркас страницы поездки: компактная шапка с данными поездки и вкладки.
 * `trip` — данные шапки, их отдаёт Trip::toHeader() на сервере.
 */
export default function TripLayout({ trip, activeTab, children }) {
    const visibleTabs = TRIP_TABS.filter((tab) => tab.key in trip.tabs);

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
                        {/* Цепочка городов (поездки, связанные переездом «Дальше»). */}
                        {(trip.previousTrip || trip.nextTrip) && (
                            <div className="trip-chain-nav">
                                {trip.previousTrip && <Link href={`/my-trips/${trip.previousTrip.id}`}>← {trip.previousTrip.destination}</Link>}
                                {trip.nextTrip && <Link href={`/my-trips/${trip.nextTrip.id}`}>{trip.nextTrip.destination} →</Link>}
                            </div>
                        )}
                    </div>
                </div>
            </section>

            <nav className="trip-tabs trip-tabs--white">
                <div className="container trip-tabs__inner">
                    {visibleTabs.map((tab) => (
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

            {trip.stage === 'completed' && (
                <div className="container">
                    <p className="trip-readonly-banner">
                        Поездка завершена — только просмотр. Если даты указаны неверно, их можно исправить в «Настройках».
                    </p>
                </div>
            )}

            {/* Что можно менять, решает каждая вкладка сама (canEditTab). Запрет на сервере — TripPolicy::update. */}
            {children}
        </AppLayout>
    );
}
