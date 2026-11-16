import React from 'react';
import { Link } from '@inertiajs/react';
import TripLayout from '../../Components/TripLayout';
import { formatDayLabel } from '../../utils/format';

function timeLabel(place) {
    if (!place.startTime) {
        return 'Время не задано';
    }

    return place.endTime ? `${place.startTime}–${place.endTime}` : place.startTime;
}

/**
 * Вкладка «Сегодня» текущей поездки (TripController::today): что запланировано на сегодня,
 * какое место следующее и куда возвращаться вечером. Менять план — на вкладке «План» (там же карта дня).
 */
export default function TripToday({ trip, day, places, nextPlaceId, lodging }) {
    const nextIndex = places.findIndex((place) => place.id === nextPlaceId);
    const nextPlace = nextIndex >= 0 ? places[nextIndex] : null;

    if (!day) {
        return (
            <TripLayout trip={trip} activeTab="today">
                <main className="container trip-content">
                    <p className="prep-empty">У поездки пока нет дней.</p>
                </main>
            </TripLayout>
        );
    }

    return (
        <TripLayout trip={trip} activeTab="today">
            <main className="container trip-content">
                <header className="section-header">
                    <h2 className="section-title">Сегодня · {formatDayLabel(day.number, day.date)}</h2>
                    {day.title && <p className="section-subtitle">{day.title}</p>}
                </header>

                <div className="today-grid">
                    <div className="today-actions">
                        {nextPlace ? (
                            <div className="short-plan-card today-next">
                                <span className="today-next__label">Дальше по плану</span>
                                <h3 className="today-next__name">{nextPlace.name}</h3>
                                <p className="today-place__desc">
                                    {timeLabel(nextPlace)}{nextPlace.address ? ` · ${nextPlace.address}` : ''}
                                </p>
                                {nextPlace.note && <p className="today-place__desc">{nextPlace.note}</p>}
                            </div>
                        ) : (
                            <div className="short-plan-card">
                                <p className="prep-empty">
                                    {places.length > 0 ? 'Все места на сегодня позади — хорошего вечера!' : 'На сегодня ничего не запланировано.'}
                                </p>
                            </div>
                        )}

                        {places.length > 0 && (
                            <div className="short-plan-card">
                                <h3 className="short-plan__title">План на сегодня</h3>
                                <ol className="today-places">
                                    {places.map((place, index) => {
                                        // Всё, что в плане до следующего места, уже позади (если следующего нет — позади всё).
                                        const isPast = nextIndex === -1 || index < nextIndex;

                                        return (
                                            <li
                                                key={place.id}
                                                className={`today-place ${isPast ? 'today-place--past' : ''} ${place.id === nextPlaceId ? 'today-place--next' : ''}`}
                                            >
                                                <span className="today-place__time">{timeLabel(place)}</span>
                                                <div>
                                                    <div className="today-place__name">{place.name}</div>
                                                    {place.category && <div className="today-place__desc">{place.category}</div>}
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ol>
                            </div>
                        )}
                    </div>

                    <div className="today-actions">
                        {/* План и карта сегодняшнего дня — вкладка «План»: там же места добавляются по пути. */}
                        <Link href={`/my-trips/${trip.id}/plan?day=${day.number}`} className="btn btn--solid btn--full">Добавить место по пути</Link>
                        {lodging && (
                            <div className="short-plan-card">
                                <h3 className="short-plan__title">Вечером возвращаемся</h3>
                                <div className="today-place__name">{lodging.name}</div>
                                {lodging.address && <div className="today-place__desc">{lodging.address}</div>}
                            </div>
                        )}
                    </div>
                </div>
            </main>
        </TripLayout>
    );
}
