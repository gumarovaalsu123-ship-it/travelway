import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import DayMap from '../../Components/Trip/DayMap';
import Footer from '../../Components/Footer';
import { formatDateRange, formatDayLabel, formatDuration, plural } from '../../utils/format';
import { timeLabel } from '../../utils/travel';

/**
 * Названия типов — как на вкладках «Транспорт» и «Проживание» (ключи совпадают с Transport::Types и Accommodation::Types).
 */
const TRANSPORT_LABELS = {
    plane: 'Самолёт',
    train: 'Поезд',
    bus: 'Автобус',
    own_car: 'Своё авто',
    car: 'Такси или аренда авто',
    ferry: 'Паром',
    other: 'Другое',
};

const DIRECTION_LABELS = { to: 'Туда', back: 'Обратно', onward: 'Дальше' };

const LODGING_LABELS = {
    hotel: 'Отель',
    apartment: 'Квартира',
    hostel: 'Хостел',
    friends: 'У знакомых',
    other: 'Другое',
};

const dateTimeFormatter = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });
const dateFormatter = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long' });

const formatDateTime = (value) => (value ? dateTimeFormatter.format(new Date(value)) : null);
const formatDate = (value) => (value ? dateFormatter.format(new Date(`${value}T00:00:00`)) : null);

const stopsLabel = (count) => `${count} ${plural(count, ['остановка', 'остановки', 'остановок'])}`;

/**
 * Публичная страница поездки по секретной ссылке /t/{token} (PublicTripController): только просмотр, без входа.
 * Гость и вошедший пользователь видят одно и то же, поэтому шапка своя, а не общая Header с личным меню.
 * Слева — все дни подряд, справа — карта выбранного дня (тот же компонент DayMap, что на вкладке «План»).
 */
export default function PublicTrip({ trip, days, transports, accommodations }) {
    const [selectedNumber, setSelectedNumber] = useState(days[0]?.number);
    const selectedDay = days.find((day) => day.number === selectedNumber);
    const cover = trip.coverImage ?? '/images/trips-first.png';

    // Выбрали день сверху — показываем его на карте и прокручиваем ленту к нему.
    const selectDay = (number) => {
        setSelectedNumber(number);
        document.getElementById(`day-${number}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    return (
        <div className="page-bg-light">
            <Head title={`${trip.destination} — маршрут | Тревелвей`}>
                {/* Секретную ссылку не нужно находить через поисковик. Сервер дублирует это заголовком X-Robots-Tag. */}
                <meta name="robots" content="noindex, nofollow" />
            </Head>

            <header className="navbar">
                <div className="container navbar__inner">
                    <Link href="/" className="logo-group">
                        <img src="/images/logo.png" alt="Тревелвей" className="logo-image" width="51" height="51" />
                        <div className="logo-text-group">
                            <span className="brand-name">тревелвей</span>
                            <span className="brand-tagline">маршруты</span>
                        </div>
                    </Link>
                    <div className="navbar__actions">
                        <Link href="/" className="btn btn--outline-dark btn--sm">Создать свой маршрут</Link>
                    </div>
                </div>
            </header>

            <section
                className="trip-hero"
                style={{ backgroundImage: `linear-gradient(0deg, rgba(30, 38, 32, 0.55), rgba(30, 38, 32, 0.55)), url('${cover}')` }}
            >
                <div className="container trip-hero__inner">
                    <div className="trip-hero__info">
                        <span className="trip-hero__badge trip-hero__badge--public">Публичный маршрут · только просмотр</span>
                        <h1 className="trip-hero__title">{trip.destination} · {formatDuration(trip.daysCount)}</h1>
                        <div className="trip-hero__meta">
                            <span className="meta-item">{formatDateRange(trip.startDate, trip.endDate)}</span>
                            <span className="meta-item">{formatDuration(trip.daysCount)}</span>
                        </div>
                    </div>
                </div>
            </section>

            <main className="container public-trip-main mt-32 mb-40">
                <div className="public-header-row mb-24">
                    <h2 className="section-title">План по дням</h2>
                    <span className="text-muted">
                        Всего в путешествии {trip.placesCount} {plural(trip.placesCount, ['место', 'места', 'мест'])}
                    </span>
                </div>

                <div className="days-calendar__list mb-32">
                    {days.map((day) => (
                        <button
                            key={day.number}
                            type="button"
                            className={`day-card ${day.number === selectedNumber ? 'active' : ''}`}
                            onClick={() => selectDay(day.number)}
                        >
                            <span className="day-card__name">День {day.number}</span>
                            {day.date && <span className="day-card__date">{formatDate(day.date)}</span>}
                        </button>
                    ))}
                </div>

                <div className="trip-plan-grid">
                    <div className="public-timeline-col">
                        {days.map((day) => (
                            <section key={day.number} id={`day-${day.number}`} className="public-day">
                                <div className="public-day-header">
                                    <span className="public-day-meta">{formatDayLabel(day.number, day.date)}</span>
                                    <h3 className="public-day-title">{day.title || `День ${day.number}`}</h3>
                                </div>

                                {day.places.length === 0 ? (
                                    <p className="text-muted">В этот день мест пока нет.</p>
                                ) : (
                                    <div className="public-places-list">
                                        {day.places.map((place, index) => (
                                            <article key={place.id} className="public-place-card">
                                                {timeLabel(place) && <div className="public-place__time">{timeLabel(place)}</div>}
                                                <h4 className="public-place__title">{index + 1} · {place.name}</h4>
                                                {place.address && place.address !== place.name && (
                                                    <p className="public-place__address">{place.address}</p>
                                                )}
                                                {place.category && (
                                                    <div className="public-place__tags">
                                                        <span className="place-tag">{place.category}</span>
                                                    </div>
                                                )}
                                            </article>
                                        ))}
                                    </div>
                                )}
                            </section>
                        ))}
                    </div>

                    {selectedDay && (
                        <div className="public-map-col">
                            <div className="public-map-header mb-16">
                                <h3 className="section-title public-map-title">Маршрут на карте</h3>
                                <p className="text-muted public-map-subtitle">
                                    {formatDayLabel(selectedDay.number, selectedDay.date)} · {stopsLabel(selectedDay.places.length)}
                                </p>
                            </div>

                            {/* key: другой день — новая карта, масштаб вписывается заново. tripId не передаём — ссылок во вкладки владельца нет. */}
                            <DayMap key={selectedDay.number} day={selectedDay} />

                            {selectedDay.places.length > 0 && (
                                <div className="map-legend-card mt-16">
                                    <h4 className="map-legend__title mb-16">Номера остановок совпадают с планом дня</h4>
                                    <ul className="legend-list">
                                        {selectedDay.places.map((place, index) => (
                                            <li key={place.id} className="legend-item">
                                                <span className="stop-number-badge">{index + 1}</span>
                                                <span className="legend-name">{place.name}</span>
                                                <span className="legend-time">{place.startTime}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </div>
                    )}
                </div>

                {(transports.length > 0 || accommodations.length > 0) && (
                    <div className="public-logistics mt-32">
                        {transports.length > 0 && (
                            <section>
                                <h2 className="section-title mb-16">Транспорт</h2>
                                <div className="public-places-list">
                                    {transports.map((ride, index) => (
                                        <article key={index} className="public-place-card">
                                            <div className="public-place__time">
                                                {[DIRECTION_LABELS[ride.direction], TRANSPORT_LABELS[ride.type]].filter(Boolean).join(' · ')}
                                            </div>
                                            <h4 className="public-place__title">
                                                {ride.departurePlace || '…'} → {ride.arrivalPlace || '…'}
                                            </h4>
                                            {ride.departureAt && (
                                                <p className="public-place__address">
                                                    {formatDateTime(ride.departureAt)}
                                                    {ride.arrivalAt && ` — ${formatDateTime(ride.arrivalAt)}`}
                                                </p>
                                            )}
                                        </article>
                                    ))}
                                </div>
                            </section>
                        )}

                        {accommodations.length > 0 && (
                            <section>
                                <h2 className="section-title mb-16">Проживание</h2>
                                <div className="public-places-list">
                                    {accommodations.map((lodging, index) => (
                                        <article key={index} className="public-place-card">
                                            <div className="public-place__time">{LODGING_LABELS[lodging.type] ?? 'Жильё'}</div>
                                            {lodging.name && <h4 className="public-place__title">{lodging.name}</h4>}
                                            {lodging.checkIn && (
                                                <p className="public-place__address">
                                                    {formatDate(lodging.checkIn)}
                                                    {lodging.checkOut && ` — ${formatDate(lodging.checkOut)}`}
                                                </p>
                                            )}
                                        </article>
                                    ))}
                                </div>
                            </section>
                        )}
                    </div>
                )}
            </main>

            <Footer />
        </div>
    );
}
