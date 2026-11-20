import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import DayMap from '../../Components/Trip/DayMap';
import Footer from '../../Components/Footer';
import Avatar from '../../Components/Profile/Avatar';
import { DaySwitcher, PlacePhotoBox, SegmentRow } from './Plan';
import {
    DIRECTION_LABELS,
    GENERIC_FORM,
    TYPE_FORM,
    TYPE_LABELS as TRANSPORT_LABELS,
    TransportIcon,
    formatDate,
    formatTime,
} from './Transport';
import { CheckBlock, TYPE_LABELS as LODGING_LABELS, nightsLabel, timePart } from './Accommodation';
import { formatDateRange, formatDuration, plural } from '../../utils/format';
import { formatMinutes, timeLabel } from '../../utils/travel';

/**
 * Вкладки публичной страницы — те же, что у автора (TRIP_TABS в TripLayout.jsx), но только для просмотра маршрута.
 * «Подготовка», «Список вещей», «Заранее» и «Настройки» — личные, их тут нет.
 * Все данные приходят сразу, поэтому вкладка переключается на месте, без перехода по адресу.
 */
const PUBLIC_TABS = [
    { key: 'plan', label: 'План' },
    { key: 'transport', label: 'Транспорт' },
    { key: 'accommodation', label: 'Проживание' },
];

/**
 * Место в плане дня — как PlaceRow на вкладке «План», но без стрелок порядка, меню «⋮» и личной заметки.
 * Клик выбирает место: его маркер подсвечивается на карте.
 */
function PublicPlaceRow({ place, number, isSelected, onSelect }) {
    const badgeClass = `stop-number-badge ${isSelected ? 'stop-number-badge--active' : ''}`;

    return (
        <li id={`plan-place-${place.id}`} className={`stop-item plan-stop ${isSelected ? 'plan-stop--selected' : ''}`} onClick={() => onSelect(place.id)}>
            <PlacePhotoBox image={place.image} alt={place.name} number={number} badgeClass={badgeClass} />

            <div className="stop-info">
                {place.startTime && <span className="stop-time plan-stop__time">{timeLabel(place)}</span>}
                <span className="stop-name">{place.name}</span>
                {(place.address || place.category) && <span className="stop-desc">{place.address || place.category}</span>}
            </div>

            <span className="plan-stop__stay" title={`На месте ${formatMinutes(place.stayMinutes)}`}>
                {formatMinutes(place.stayMinutes)}
                <span className="plan-stop__stay-label">на месте</span>
            </span>
        </li>
    );
}

/**
 * Вкладка «План»: переключатель дней, список мест дня и карта этого дня — та же сетка, что у автора.
 */
function PublicPlan({ trip, days }) {
    const [selectedNumber, setSelectedNumber] = useState(days[0]?.number);
    const [selectedPlaceId, setSelectedPlaceId] = useState(null);

    if (days.length === 0) {
        return <p className="form-hint">В маршруте пока нет дней.</p>;
    }

    // DaySwitcher ждёт у дня id, число мест и их названия — собираем из того, что уже пришло.
    const switcherDays = days.map((day) => ({
        id: day.number,
        number: day.number,
        date: day.date,
        placesCount: day.places.length,
        placeNames: day.places.map((place) => place.name),
    }));
    const day = days.find((item) => item.number === selectedNumber) ?? days[0];
    const selectedDay = { ...day, id: day.number };

    function selectDay(switcherDay) {
        setSelectedNumber(switcherDay.number);
        setSelectedPlaceId(null);
    }

    /** Клик по маркеру: подсвечиваем место в списке и прокручиваем к нему (как в Plan.jsx). */
    function selectFromMap(placeId) {
        setSelectedPlaceId(placeId);
        document.getElementById(`plan-place-${placeId}`)?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }

    return (
        <div className="trip-plan-grid">
            <DaySwitcher trip={trip} days={switcherDays} selectedDay={selectedDay} onSelectDay={selectDay} />

            <div className="trip-plan__main-col">
                <div className="day-itinerary-card">
                    <div className="itinerary-block">
                        {selectedDay.places.length === 0 ? (
                            <p className="form-hint">В этот день мест в плане нет.</p>
                        ) : (
                            <ul className="stop-list">
                                {selectedDay.places.map((place, index) => (
                                    <React.Fragment key={place.id}>
                                        {index > 0 && <SegmentRow segment={place.segment} onShowAlong={null} />}
                                        <PublicPlaceRow
                                            place={place}
                                            number={index + 1}
                                            isSelected={place.id === selectedPlaceId}
                                            onSelect={setSelectedPlaceId}
                                        />
                                    </React.Fragment>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            </div>

            {/* key: другой день — новая карта, масштаб вписывается заново. tripId не передаём — ссылок во вкладки автора нет. */}
            <div className="trip-plan__map-col">
                <DayMap
                    key={selectedDay.id}
                    day={selectedDay}
                    selectedPlaceId={selectedPlaceId}
                    onMarkerClick={selectFromMap}
                    canEdit={false}
                    destination={trip.destination}
                />
            </div>
        </div>
    );
}

/**
 * Карточка переезда — как TransportCard у автора, но без отметки о билете, заметки и кнопок «Изменить/Удалить».
 */
function PublicTransportCard({ transport }) {
    const labels = TYPE_FORM[transport.type] ?? GENERIC_FORM;

    return (
        <article className="transport-card">
            <header className="transport-card__header">
                <div className="transport-card__title-group">
                    <TransportIcon type={transport.type} />
                    <div className="transport-card__headings">
                        <h3 className="transport-title">{transport.departurePlace || '…'} → {transport.arrivalPlace || '…'}</h3>
                        <p className="transport-subtitle">{formatDate(transport.departureAt)}</p>
                    </div>
                </div>
                <div className="transport-card__badges">
                    {DIRECTION_LABELS[transport.direction] && <span className="day-badge">{DIRECTION_LABELS[transport.direction]}</span>}
                    <span className="day-badge">{TRANSPORT_LABELS[transport.type]}</span>
                </div>
            </header>

            <div className="transport-card__body">
                <div className="time-block">
                    <div className="time-block__time">{formatTime(transport.departureAt)}</div>
                    <div className="time-block__place">{labels.departure}</div>
                </div>
                <div className="time-block__arrow">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <path d="M5 12h14M12 5l7 7-7 7" />
                    </svg>
                </div>
                <div className="time-block">
                    <div className="time-block__time">{formatTime(transport.arrivalAt)}</div>
                    <div className="time-block__place">{labels.arrival}</div>
                </div>
            </div>
        </article>
    );
}

/**
 * Карточка жилья — как AccommodationCard у автора, но без адреса, брони, заметки и кнопок.
 * У «знакомых» имени нет (сервер его не отдаёт) — вместо него пишем тип жилья.
 */
function PublicAccommodationCard({ accommodation }) {
    const { checkIn, checkOut, nights } = accommodation;
    const checkOutHint = [checkOut && `До ${timePart(checkOut)}`, nights !== null && nightsLabel(nights)].filter(Boolean).join(' · ');

    return (
        <article className="acc-card">
            <div className="acc-card__body">
                <header className="acc-header">
                    <div className="acc-title-group">
                        <h3 className="acc-title">{accommodation.name ?? LODGING_LABELS[accommodation.type]}</h3>
                    </div>
                    {nights !== null && <span className="acc-badge">{nightsLabel(nights)}</span>}
                </header>

                <div className="acc-card__statuses">
                    <span className="day-badge">{LODGING_LABELS[accommodation.type]}</span>
                </div>

                <div className="check-blocks-row">
                    <CheckBlock label="Заезд" dateTime={checkIn} hint={checkIn && `С ${timePart(checkIn)}`} />
                    <CheckBlock label="Выезд" dateTime={checkOut} hint={checkOutHint} />
                </div>
            </div>
        </article>
    );
}

/**
 * Публичная страница поездки по секретной ссылке /t/{token} (PublicTripController): только просмотр, без входа.
 * Выглядит как страница поездки у автора (обложка + вкладки), но гость и вошедший видят одно и то же,
 * поэтому шапка своя, а не общая Header с личным меню. Кнопок изменения нет совсем.
 */
export default function PublicTrip({ author, trip, days, transports, accommodations }) {
    const [activeTab, setActiveTab] = useState('plan');
    const cover = trip.coverImage ?? '/images/trips-first.png';

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
                className="trip-hero trip-hero--compact"
                style={{ backgroundImage: `linear-gradient(0deg, rgba(30, 38, 32, 0.6), rgba(30, 38, 32, 0.6)), url('${cover}')` }}
            >
                <div className="container trip-hero__inner">
                    <div className="trip-hero__info">
                        <span className="trip-hero__badge trip-hero__badge--public">Публичный маршрут · только просмотр</span>
                        <h1 className="trip-hero__title">{trip.destination} · {formatDuration(trip.daysCount)}</h1>
                        <div className="trip-hero__meta">
                            {trip.startDate && <span className="meta-item">{formatDateRange(trip.startDate, trip.endDate)}</span>}
                            <span className="meta-item">{trip.placesCount} {plural(trip.placesCount, ['место', 'места', 'мест'])}</span>
                        </div>
                        <div className="public-author">
                            <Avatar avatarUrl={author.avatarUrl} initials={author.initials} className="public-author__avatar" />
                            <span className="public-author__text">
                                Автор маршрута
                                <span className="public-author__name">{author.name}</span>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <nav className="trip-tabs trip-tabs--white">
                <div className="container trip-tabs__inner">
                    {PUBLIC_TABS.map((tab) => (
                        <button
                            key={tab.key}
                            type="button"
                            className={`tab-btn ${tab.key === activeTab ? 'active' : ''}`}
                            onClick={() => setActiveTab(tab.key)}
                        >
                            {tab.label}
                        </button>
                    ))}
                </div>
            </nav>

            {activeTab === 'plan' && (
                <main className="container trip-plan-main">
                    <PublicPlan trip={trip} days={days} />
                </main>
            )}

            {activeTab === 'transport' && (
                <main className="container trip-transport-main trip-content">
                    <header className="section-header section-header--spaced">
                        <div className="section-header__text">
                            <h2 className="section-title">Транспорт</h2>
                            <p className="section-subtitle section-subtitle--light">Как автор добирался туда, обратно и между городами.</p>
                        </div>
                    </header>

                    <div className="transport-list">
                        {transports.length === 0 ? (
                            <section className="trips-empty">
                                <h3 className="trips-empty__title">Переездов в маршруте нет</h3>
                            </section>
                        ) : transports.map((transport, index) => (
                            <PublicTransportCard key={index} transport={transport} />
                        ))}
                    </div>
                </main>
            )}

            {activeTab === 'accommodation' && (
                <main className="container trip-accommodation-main mb-40">
                    <header className="section-header section-header--spaced">
                        <div className="section-header__text">
                            <h2 className="section-title">Проживание</h2>
                            <p className="section-subtitle section-subtitle--light">Где автор жил во время поездки.</p>
                        </div>
                    </header>

                    <div className="accommodation-list mt-16">
                        {accommodations.length === 0 ? (
                            <section className="trips-empty">
                                <h3 className="trips-empty__title">Жильё в маршруте не указано</h3>
                            </section>
                        ) : accommodations.map((accommodation, index) => (
                            <PublicAccommodationCard key={index} accommodation={accommodation} />
                        ))}
                    </div>
                </main>
            )}

            <Footer />
        </div>
    );
}
