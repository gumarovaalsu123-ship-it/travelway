import React, { useMemo, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '../../Components/AppLayout';
import { formatDateRange, formatDuration, plural } from '../../utils/format';

const STATUS_LABELS = {
    current: 'Текущая',
    planned: 'Запланировано',
    completed: 'Завершено',
    draft: 'Черновик',
};

const FILTERS = [
    { value: 'all', label: 'Все' },
    { value: 'current', label: 'Текущие' },
    { value: 'planned', label: 'Запланированные' },
    { value: 'completed', label: 'Завершённые' },
    { value: 'draft', label: 'Черновики' },
];

const COVERS = ['/images/trips-first.png', '/images/trips-second.png', '/images/card1.png', '/images/card2.png', '/images/card3.png'];

const coverFor = (trip) => COVERS[trip.id % COVERS.length];

function FeaturedTrip({ trip, featured }) {
    const places = featured.placeNames;

    return (
        <section className="featured-trip">
            <div className="featured-trip__image" style={{ backgroundImage: `url('${coverFor(trip)}')` }} />
            <div className="featured-trip__content">
                <div className="featured-trip__badge">{featured.isCurrent ? 'Текущая поездка' : 'Ближайшая поездка'}</div>
                <h2 className="featured-trip__title">{trip.title} · {formatDuration(trip.daysCount)}</h2>
                <p className="featured-trip__meta">
                    {formatDateRange(trip.startDate, trip.endDate)} · {trip.destination} · {trip.placesCount} {plural(trip.placesCount, ['место', 'места', 'мест'])}
                </p>
                <h3 className="featured-trip__day">
                    {featured.isCurrent ? 'Сегодня, ' : ''}День {featured.dayNumber}{featured.dayTitle ? ` — ${featured.dayTitle}` : ''}
                </h3>
                <p className="featured-trip__desc">
                    {places.length > 0 ? places.join(', ') : 'На этот день пока ничего не запланировано.'}
                </p>
                <div className="featured-trip__action">
                    <Link href="/interactive-map" className="btn btn--outline">Добавить места на карте</Link>
                </div>
            </div>
        </section>
    );
}

function TripCard({ trip }) {
    const remove = () => {
        const warning = trip.placesCount > 0
            ? ` Вместе с ним из плана удалятся ${trip.placesCount} ${plural(trip.placesCount, ['место', 'места', 'мест'])}.`
            : '';

        if (window.confirm(`Удалить путешествие «${trip.title}»?${warning}`)) {
            router.delete(`/my-trips/${trip.id}`, { preserveScroll: true });
        }
    };

    return (
        <article className="trip-card">
            <div className="trip-card__image" style={{ backgroundImage: `url('${coverFor(trip)}')` }}>
                <span className="trip-card__location">{trip.destination}</span>
            </div>
            <div className="trip-card__body">
                <div className="trip-card__header">
                    <span className="trip-card__date">
                        {trip.startDate ? formatDateRange(trip.startDate, trip.endDate) : formatDuration(trip.daysCount)}
                    </span>
                    <span className={`trip-card__status trip-card__status--${trip.status}`}>{STATUS_LABELS[trip.status]}</span>
                </div>
                <h3 className="trip-card__title">{trip.title}</h3>
                <div className="trip-card__progress">
                    <div className="progress-info">
                        <span className="progress-label">Дни с планом</span>
                        <span className="progress-value">{trip.progress}%</span>
                    </div>
                    <div className="progress-track">
                        <div className="progress-fill" style={{ width: `${trip.progress}%` }} />
                    </div>
                </div>
                <div className="trip-card__actions">
                    <Link href={`/my-trips/${trip.id}/edit`} className="btn btn--outline btn--sm">Настройки</Link>
                    <button type="button" className="btn btn--outline btn--sm" onClick={remove}>Удалить</button>
                </div>
            </div>
        </article>
    );
}

export default function TripsIndex({ trips, featured }) {
    const [filter, setFilter] = useState('all');

    const counts = useMemo(() => trips.reduce(
        (result, trip) => ({ ...result, [trip.status]: (result[trip.status] ?? 0) + 1 }),
        { all: trips.length },
    ), [trips]);

    const visibleTrips = filter === 'all' ? trips : trips.filter((trip) => trip.status === filter);
    const featuredTrip = featured && trips.find((trip) => trip.id === featured.id);

    return (
        <AppLayout title="Мои путешествия">
            <main className="container trips-main">
                <section className="page-header">
                    <div className="page-header__text">
                        <h1 className="page-title">Мои путешествия</h1>
                        <p className="page-subtitle">Ваши активные поездки, запланированные маршруты, завершённые и черновики</p>
                    </div>
                    <Link href="/my-trips/create" className="btn btn--solid">Создать путешествие</Link>
                </section>

                {trips.length === 0 ? (
                    <section className="trips-empty">
                        <h2 className="trips-empty__title">Здесь пока пусто</h2>
                        <p className="trips-empty__text">Создайте первое путешествие, а потом добавляйте в него места прямо с интерактивной карты.</p>
                        <Link href="/my-trips/create" className="btn btn--solid">Создать путешествие</Link>
                    </section>
                ) : (
                    <>
                        {featuredTrip && <FeaturedTrip trip={featuredTrip} featured={featured} />}

                        <section className="trips-filters">
                            {FILTERS.filter((item) => item.value === 'all' || counts[item.value]).map((item) => (
                                <button
                                    key={item.value}
                                    type="button"
                                    className={`filter-btn ${filter === item.value ? 'active' : ''}`}
                                    onClick={() => setFilter(item.value)}
                                >
                                    {item.label} {counts[item.value]}
                                </button>
                            ))}
                        </section>

                        <section className="trips-grid">
                            {visibleTrips.map((trip) => <TripCard key={trip.id} trip={trip} />)}
                        </section>
                    </>
                )}
            </main>
        </AppLayout>
    );
}
