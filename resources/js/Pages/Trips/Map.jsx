import React, { useMemo, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { MapContainer, Marker, Polyline, Popup, TileLayer } from 'react-leaflet';
import 'leaflet/dist/leaflet.css';

import TripLayout from '../../Components/TripLayout';
import CustomZoomControl from '../../Components/Map/UI/CustomZoomControl';
import FitToPlaces from '../../Components/Map/Logic/FitToPlaces';
import useRouteLines, { profileFor } from '../../Components/Map/Logic/useRouteLines';
import { lodgingIcon, stopIcon } from '../../utils/mapIcons';
import { plural } from '../../utils/format';
import { TRAVEL_MODES, formatMinutes, timeLabel } from '../../utils/travel';

const dayMonthFormatter = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long' });
const toDate = (isoDate) => new Date(`${isoDate}T00:00:00`);

const stopsLabel = (count) => `${count} ${plural(count, ['остановка', 'остановки', 'остановок'])}`;

/**
 * Как добрались до места: «🚶 12 мин»; если время ещё не рассчитано — только значок способа.
 */
function segmentLabel(place) {
    const mode = TRAVEL_MODES[place.mode];

    if (!mode) {
        return '';
    }

    return place.travelMinutes ? `${mode.icon} ${formatMinutes(place.travelMinutes)}` : `${mode.icon} ${mode.label}`;
}

/**
 * Переключатель дней — как на вкладке «План по дням», номер дня в адресе (?day=N).
 */
function DaysNav({ tripId, days, selectedDayId }) {
    return (
        <div className="days-calendar__list mt-16">
            {days.map((day) => (
                <Link
                    key={day.id}
                    href={`/my-trips/${tripId}/map?day=${day.number}`}
                    preserveScroll
                    className={`day-card ${day.id === selectedDayId ? 'active' : ''}`}
                >
                    <span className="day-card__name">День {day.number}</span>
                    {day.date && <span className="day-card__date">{dayMonthFormatter.format(toDate(day.date))}</span>}
                    <span className="day-card__date">{day.placesCount > 0 ? stopsLabel(day.placesCount) : 'Пусто'}</span>
                </Link>
            ))}
        </div>
    );
}

/**
 * Жильё в списке остановок: без номера, просто начало или конец дня.
 */
function LodgingCard({ lodging, label }) {
    return (
        <div className="map-stop-card map-stop-card--lodging">
            <div className="stop-number-badge stop-number-badge--lodging">⌂</div>
            <div className="map-stop-card__info">
                <h4 className="map-stop-card__title">{lodging.name}</h4>
                <p className="map-stop-card__meta">{label}</p>
                {lodging.address && <p className="map-stop-card__address">{lodging.address}</p>}
            </div>
        </div>
    );
}

/**
 * Один день на карте: список остановок слева и карта справа.
 * Компонент создаётся заново при смене дня (key в TripMap), поэтому выбор и масштаб карты сбрасываются сами.
 */
function DayMap({ trip, day }) {
    const [map, setMap] = useState(null);
    const [selectedId, setSelectedId] = useState(null);
    const markerRefs = useRef({});
    const cardRefs = useRef({});

    const { morningLodging, eveningLodging, places } = day;

    // Все точки дня по порядку, как в расписании: [жильё утром] → места → [жильё вечером].
    // Профиль отрезка берём по способу, которым добираемся до следующей точки.
    const { points, profiles } = useMemo(() => {
        const chain = [
            ...(morningLodging ? [{ ...morningLodging, mode: null }] : []),
            ...places,
            ...(eveningLodging ? [{ ...eveningLodging, mode: day.returnMode }] : []),
        ];

        return {
            points: chain.map((point) => ({ lat: point.lat, lng: point.lng })),
            profiles: chain.slice(1).map((point) => profileFor(point.mode)),
        };
    }, [morningLodging, eveningLodging, places, day.returnMode]);

    const { lines, isLoading } = useRouteLines(points, profiles);
    const hasStraightLines = !isLoading && lines.some((line) => line.isStraight);

    // Утром и вечером обычно одно и то же жильё — показываем его одним значком.
    const lodgings = [morningLodging, eveningLodging]
        .filter(Boolean)
        .filter((lodging, index, all) => all.findIndex((other) => other.id === lodging.id) === index);

    /** Клик по остановке в списке: подсвечиваем маркер, показываем его и открываем попап. */
    function selectFromList(place) {
        setSelectedId(place.id);
        map?.panTo([place.lat, place.lng]);
        markerRefs.current[place.id]?.openPopup();
    }

    /** Клик по маркеру: подсвечиваем карточку в списке и прокручиваем к ней. */
    function selectFromMap(place) {
        setSelectedId(place.id);
        cardRefs.current[place.id]?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    const subtitle = [
        day.date && dayMonthFormatter.format(toDate(day.date)),
        stopsLabel(places.length),
        day.isCalculated && (day.endTime ? `${day.startTime}–${day.endTime}` : `с ${day.startTime}`),
    ].filter(Boolean).join(' · ');

    const usedModes = [...new Set(places.map((place) => place.mode).filter((mode) => TRAVEL_MODES[mode]))];

    return (
        <div className="trip-map-grid mt-16">
            <aside className="map-sidebar">
                <div className="map-sidebar__header">
                    <h3 className="map-sidebar__title">День {day.number}{day.title ? ` — ${day.title}` : ''}</h3>
                    <p className="map-sidebar__subtitle">{subtitle}</p>
                </div>

                <div className="map-stop-list">
                    {morningLodging && <LodgingCard lodging={morningLodging} label={`Начало дня · ${day.startTime}`} />}

                    {places.map((place) => (
                        <button
                            key={place.id}
                            type="button"
                            ref={(element) => { cardRefs.current[place.id] = element; }}
                            className={`map-stop-card ${place.id === selectedId ? 'active' : ''}`}
                            onClick={() => selectFromList(place)}
                        >
                            <div className={`stop-number-badge ${place.id === selectedId ? 'stop-number-badge--active' : ''}`}>
                                {place.number}
                            </div>
                            <div className="map-stop-card__info">
                                <h4 className="map-stop-card__title">{place.name}</h4>
                                <p className="map-stop-card__meta">
                                    {[timeLabel(place), place.category, segmentLabel(place)].filter(Boolean).join(' · ')}
                                </p>
                                {place.address && place.address !== place.name && (
                                    <p className="map-stop-card__address">{place.address}</p>
                                )}
                            </div>
                        </button>
                    ))}

                    {eveningLodging && (
                        <LodgingCard lodging={eveningLodging} label={day.endTime ? `Вернётесь ~${day.endTime}` : 'Конец дня'} />
                    )}
                </div>

                <div className="map-route-summary">
                    <div className="map-summary-badges">
                        {day.isCalculated && <span className="summary-badge">{formatMinutes(day.travelMinutes)} в пути</span>}
                        {usedModes.map((mode) => (
                            <span key={mode} className="summary-badge">{TRAVEL_MODES[mode].icon} {TRAVEL_MODES[mode].label}</span>
                        ))}
                    </div>
                    <Link href={`/my-trips/${trip.id}/plan?day=${day.number}`} className="btn btn--outline-dark btn--full">
                        Изменить в плане дня
                    </Link>
                </div>
            </aside>

            <section className="map-interactive-area">
                <MapContainer center={[points[0].lat, points[0].lng]} zoom={13} zoomControl={false} ref={setMap}>
                    <TileLayer
                        attribution='© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                        url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                    />

                    {/* Вписываем карту во все точки дня, включая жильё. */}
                    <FitToPlaces places={points} />
                    <CustomZoomControl />

                    {/* Линия по дорогам — сплошная; прямая (пока ждём ответ или если сервис не ответил) — пунктир. */}
                    {lines.map((line, index) => (
                        <Polyline
                            key={`${index}-${line.isStraight}`}
                            positions={line.positions}
                            pathOptions={{ color: '#3D5A3E', weight: 4, opacity: 0.85, dashArray: line.isStraight ? '8 8' : null }}
                        />
                    ))}

                    {lodgings.map((lodging) => (
                        <Marker key={`lodging-${lodging.id}`} position={[lodging.lat, lodging.lng]} icon={lodgingIcon()} title={lodging.name}>
                            <Popup>
                                <div className="map-stop-popup-body">
                                    <h4>⌂ {lodging.name}</h4>
                                    {lodging.address && <p>{lodging.address}</p>}
                                    <Link href={`/my-trips/${trip.id}/accommodation`}>Открыть жильё</Link>
                                </div>
                            </Popup>
                        </Marker>
                    ))}

                    {places.map((place) => (
                        <Marker
                            key={place.id}
                            ref={(marker) => { markerRefs.current[place.id] = marker; }}
                            position={[place.lat, place.lng]}
                            icon={stopIcon(place.number, place.id === selectedId)}
                            title={place.name}
                            zIndexOffset={place.id === selectedId ? 1000 : 0}
                            eventHandlers={{ click: () => selectFromMap(place) }}
                        >
                            <Popup>
                                <div className="map-stop-popup-body">
                                    <h4>{place.number} · {place.name}</h4>
                                    {timeLabel(place) && <p>{timeLabel(place)}</p>}
                                    {place.address && place.address !== place.name && <p>{place.address}</p>}
                                    <Link href={`/my-trips/${trip.id}/plan?day=${day.number}`}>Открыть в плане</Link>
                                </div>
                            </Popup>
                        </Marker>
                    ))}
                </MapContainer>

                {isLoading && points.length > 1 && <div className="map-route-note">Строим маршрут…</div>}
                {hasStraightLines && (
                    <div className="map-route-note">Сервис маршрутов не ответил — линия показана по прямой.</div>
                )}
            </section>
        </div>
    );
}

/**
 * Пустой день: на карте показывать нечего, подсказываем, где добавить места.
 */
function EmptyDay({ trip, day }) {
    return (
        <section className="trips-empty mt-16">
            <h2 className="trips-empty__title">{day ? `В дне ${day.number} пока нет мест` : 'В поездке пока нет дней'}</h2>
            <p className="trips-empty__text">
                Добавьте места в план дня или найдите их на интерактивной карте — здесь появится маршрут.
            </p>
            <div className="map-summary-badges">
                {day && <Link href={`/my-trips/${trip.id}/plan?day=${day.number}`} className="btn btn--solid">К плану дня</Link>}
                <Link href="/interactive-map" className="btn btn--outline-dark">Интерактивная карта</Link>
            </div>
        </section>
    );
}

/**
 * Вкладка «Карта» страницы поездки: маршрут выбранного дня. Номера мест — как в «Плане по дням» (visit_order).
 */
export default function TripMap({ trip, days, selectedDay }) {
    return (
        <TripLayout trip={trip} activeTab="map">
            <main className="container trip-map-main">
                <header className="section-header section-header--spaced">
                    <div className="section-header__text">
                        <h2 className="section-title">Карта поездки</h2>
                        <p className="section-subtitle section-subtitle--light">Все остановки связаны с планом дня.</p>
                    </div>
                    <Link href="/interactive-map" className="btn btn--outline-dark">Добавить место</Link>
                </header>

                <DaysNav tripId={trip.id} days={days} selectedDayId={selectedDay?.id} />

                {selectedDay && selectedDay.places.length > 0
                    ? <DayMap key={selectedDay.id} trip={trip} day={selectedDay} />
                    : <EmptyDay trip={trip} day={selectedDay} />}
            </main>
        </TripLayout>
    );
}
