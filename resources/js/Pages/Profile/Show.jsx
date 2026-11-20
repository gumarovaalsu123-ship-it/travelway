import React from 'react';
import { Link } from '@inertiajs/react';
import AppLayout from '../../Components/AppLayout';
import Avatar from '../../Components/Profile/Avatar';
import { formatDateRange, plural } from '../../utils/format';

// Месяцы в родительном падеже: «На тревелвей с ноября 2026».
const MONTHS_GENITIVE = [
    'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
    'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря',
];

function formatMemberSince(isoDate) {
    if (!isoDate) {
        return null;
    }

    const date = new Date(`${isoDate}T00:00:00`);

    return `На тревелвей с ${MONTHS_GENITIVE[date.getMonth()]} ${date.getFullYear()}`;
}

function StatCard({ label, value, hint }) {
    return (
        <div className="profile-stat">
            <span className="profile-stat__label">{label}</span>
            <span className="profile-stat__value">{value}</span>
            {hint && <span className="profile-stat__hint">{hint}</span>}
        </div>
    );
}

function NextTrip({ trip }) {
    if (!trip) {
        return (
            <section className="profile-card profile-next-trip">
                <h2 className="profile-section-title">Ближайшая поездка</h2>
                <p className="profile-muted">Запланированных поездок пока нет.</p>
                <div>
                    <Link href="/my-trips/create" className="btn btn--solid-dark">Спланировать поездку</Link>
                </div>
            </section>
        );
    }

    const when = trip.status === 'current'
        ? 'Вы сейчас в поездке'
        : `Через ${trip.daysLeft} ${plural(trip.daysLeft, ['день', 'дня', 'дней'])}`;

    return (
        <section className="profile-card profile-next-trip">
            <h2 className="profile-section-title">{trip.status === 'current' ? 'Текущая поездка' : 'Ближайшая поездка'}</h2>
            <div className="profile-next-trip__row">
                <div className="profile-user-details">
                    <span className="profile-next-trip__title">{trip.title}</span>
                    <span className="profile-muted">{trip.destination} · {formatDateRange(trip.startDate, trip.endDate)}</span>
                    <span className="profile-next-trip__when">{when}</span>
                </div>
                <Link href={`/my-trips/${trip.id}`} className="btn btn--outline-dark">Открыть поездку</Link>
            </div>
        </section>
    );
}

export default function ProfileShow({ profile, stats }) {
    return (
        <AppLayout title="Профиль">
            <main className="container profile-main mt-32 mb-40">
                <section className="profile-card">
                    <div className="profile-card__top">
                        <Avatar avatarUrl={profile.avatarUrl} initials={profile.initials} className="profile-avatar-large" />
                        <div className="profile-user-details">
                            <h1 className="profile-user-name">{profile.name}</h1>
                            <div className="profile-user-location">{profile.email}</div>
                            <div className="profile-user-date">{formatMemberSince(profile.memberSince)}</div>
                        </div>
                    </div>
                    <hr className="profile-divider" />
                    <div className="profile-card__actions">
                        <Link href="/my-trips" className="btn btn--solid-dark">Мои путешествия</Link>
                        <Link href="/profile/edit" className="btn btn--outline-dark">Настройки профиля</Link>
                    </div>
                </section>

                <section className="profile-stats">
                    <StatCard
                        label="Поездок"
                        value={stats.trips.total}
                        hint={`завершено ${stats.trips.completed} · запланировано ${stats.trips.planned}`
                            + (stats.trips.current ? ` · сейчас ${stats.trips.current}` : '')}
                    />
                    <StatCard
                        label="Дней в пути"
                        value={stats.daysTraveled}
                        hint="в завершённых и текущей поездках"
                    />
                    <StatCard
                        label="Мест посещено"
                        value={stats.places.visited}
                        hint={`ещё ${stats.places.planned} в планах`}
                    />
                    <StatCard
                        label="Городов"
                        value={stats.cities}
                        hint={plural(stats.cities, ['город посещён', 'города посещено', 'городов посещено'])}
                    />
                    <StatCard
                        label="Хочу посетить"
                        value={stats.favorites}
                        hint={<Link href="/favorites">открыть список</Link>}
                    />
                </section>

                <NextTrip trip={stats.nextTrip} />
            </main>
        </AppLayout>
    );
}
