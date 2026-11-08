import React from 'react';
import { Link } from '@inertiajs/react';
import TripLayout from '../../Components/TripLayout';
import { formatDateRange, formatDayLabel } from '../../utils/format';
import { PlacePhoto, WikipediaCredit } from '../../Components/PlaceInfo';

/**
 * Соседние пустые дни склеиваем в одну строку («Дни 4–6»), чтобы длинная поездка не превращалась в простыню.
 * Возвращает список блоков: { type: 'day', day } или { type: 'empty', days: [...] }.
 */
function groupDays(days) {
    return days.reduce((blocks, day) => {
        const last = blocks[blocks.length - 1];

        if (day.places.length > 0) {
            blocks.push({ type: 'day', day });
        } else if (last?.type === 'empty') {
            last.days.push(day);
        } else {
            blocks.push({ type: 'empty', days: [day] });
        }

        return blocks;
    }, []);
}

function emptyDaysLabel(days) {
    const first = days[0];
    const last = days[days.length - 1];

    if (days.length === 1) {
        return formatDayLabel(first.number, first.date);
    }

    const numbers = `Дни ${first.number}–${last.number}`;

    return first.date ? `${numbers} · ${formatDateRange(first.date, last.date)}` : numbers;
}

function PlannedDay({ day }) {
    return (
        <div className="short-plan__day">
            <div className="day-meta">
                <span className="day-badge">{formatDayLabel(day.number, day.date)}</span>
            </div>
            {day.title && <h4 className="day-title">{day.title}</h4>}
            <ul className="stop-list">
                {day.places.map((place) => (
                    <li key={place.id} className="stop-item">
                        <div className="stop-time">{place.startTime ?? '—'}</div>
                        <PlacePhoto image={place.image} categorySlug={place.categorySlug} alt={place.name} size="thumb" />
                        <div className="stop-info">
                            <div className="stop-name">{place.name}</div>
                            <div className="stop-desc">{[place.category, place.address].filter(Boolean).join(' · ')}</div>
                            {place.image && <WikipediaCredit url={place.wikiUrl} />}
                        </div>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * Страница поездки, вкладка «Обзор»: все дни поездки и места, запланированные на каждый день.
 */
export default function TripShow({ trip, description, days }) {
    return (
        <TripLayout trip={trip} activeTab="overview">
            <main className="container trip-content">
                <header className="section-header">
                    <h2 className="section-title">Обзор путешествия</h2>
                    <p className="section-subtitle">Дни поездки и места, которые вы на них запланировали.</p>
                </header>

                <div className="trip-overview-grid">
                    <div className="short-plan-card">
                        <div className="short-plan__header">
                            <h3 className="short-plan__title">План по дням</h3>
                            <p className="short-plan__subtitle">Места добавляются с интерактивной карты</p>
                        </div>

                        {groupDays(days).map((block) => (block.type === 'day' ? (
                            <PlannedDay key={block.day.id} day={block.day} />
                        ) : (
                            <div key={block.days[0].id} className="empty-day-row">
                                <div className="empty-day-info">
                                    <span className="empty-day-name">{emptyDaysLabel(block.days)}</span>
                                    <span className="empty-day-desc">Места ещё не добавлены</span>
                                </div>
                            </div>
                        )))}
                    </div>

                    <div className="overview-map-col">
                        <Link href="/interactive-map" className="btn btn--solid btn--full">Добавить места на карте</Link>
                        <Link href={`/my-trips/${trip.id}/edit`} className="btn btn--outline-dark btn--full">Настройки путешествия</Link>
                        {description && (
                            <div className="short-plan-card">
                                <h3 className="short-plan__title">О поездке</h3>
                                <p className="trip-overview__description">{description}</p>
                            </div>
                        )}
                    </div>
                </div>
            </main>
        </TripLayout>
    );
}
