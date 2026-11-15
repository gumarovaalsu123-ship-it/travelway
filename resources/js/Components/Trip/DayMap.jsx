import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { MapContainer, Marker, Polyline, Popup, TileLayer } from 'react-leaflet';
import 'leaflet/dist/leaflet.css';

import CustomZoomControl from '../Map/UI/CustomZoomControl';
import FitToPlaces from '../Map/Logic/FitToPlaces';
import Loader from '../Loader';
import useRouteLines from '../Map/Logic/useRouteLines';
import { lodgingIcon, stopIcon } from '../../utils/mapIcons';
import { dayLodgings, dayRoute } from '../../utils/dayRoute';
import { timeLabel } from '../../utils/travel';

/**
 * Карта одного дня на вкладке «План»: нумерованные маркеры мест (номера — как в списке слева),
 * жильё и линия маршрута по дорогам (OSRM, см. useRouteLines).
 *
 * selectedPlaceId — место, выбранное в списке: его маркер подсвечен, карта сдвигается к нему.
 * onMarkerClick(id) — нажали на маркер: страница подсвечивает место в списке и прокручивает к нему.
 *
 * Страница создаёт компонент заново при смене дня (key), поэтому масштаб карты сбрасывается сам.
 * После изменений плана (↑↓, перенос, добавление, расчёт) сервер присылает новые места — номера и линия
 * пересчитываются из них же.
 */
export default function DayMap({ tripId, day, focusLodging, selectedPlaceId, onMarkerClick }) {
    const [map, setMap] = useState(null);
    const markerRefs = useRef({});
    const focusMarkerRef = useRef(null);

    const { points, profiles } = useMemo(() => dayRoute(day), [day]);
    const { lines, isLoading } = useRouteLines(points, profiles);
    const hasStraightLines = !isLoading && lines.some((line) => line.isStraight);
    const lodgings = dayLodgings(day, focusLodging);

    // В дне может не быть ни мест, ни жилья — тогда карту строим вокруг одного жилья из «Показать на карте».
    const mapPoints = points.length > 0 ? points : lodgings;

    // Выбрали место в списке (или нажали на маркер) — показываем его на карте и открываем подпись.
    useEffect(() => {
        const place = day.places.find((item) => item.id === selectedPlaceId);

        if (map && place) {
            map.panTo([place.lat, place.lng]);
            markerRefs.current[place.id]?.openPopup();
        }
    }, [map, selectedPlaceId]);

    // Перешли к жилью из «Проживания» — сразу открываем его подпись (маркер к этому моменту уже создан).
    useEffect(() => {
        if (map && focusLodging) {
            focusMarkerRef.current?.openPopup();
        }
    }, [map, focusLodging]);

    if (mapPoints.length === 0) {
        return (
            <div className="day-map day-map--empty">
                <p className="form-hint">Добавьте места в этот день — здесь появится маршрут на карте.</p>
            </div>
        );
    }

    return (
        <div className="day-map map-interactive-area">
            <MapContainer center={[mapPoints[0].lat, mapPoints[0].lng]} zoom={13} zoomControl={false} ref={setMap}>
                <TileLayer
                    attribution='© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                    url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                />

                {/* Вписываем карту во все точки дня, при переходе к жилью — центрируем на нём.
                    key: добавили или убрали место — вписываем заново, чтобы новое место было видно. */}
                <FitToPlaces key={mapPoints.length} places={mapPoints} focusPlace={focusLodging} />
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
                    <Marker
                        key={`lodging-${lodging.id}`}
                        ref={lodging.id === focusLodging?.id ? focusMarkerRef : undefined}
                        position={[lodging.lat, lodging.lng]}
                        icon={lodgingIcon(lodging.id === focusLodging?.id)}
                        title={lodging.name}
                        zIndexOffset={lodging.id === focusLodging?.id ? 1000 : 0}
                    >
                        <Popup>
                            <div className="map-stop-popup-body">
                                <h4>⌂ {lodging.name}</h4>
                                {lodging.address && <p>{lodging.address}</p>}
                                <Link href={`/my-trips/${tripId}/accommodation`}>Открыть жильё</Link>
                            </div>
                        </Popup>
                    </Marker>
                ))}

                {day.places.map((place, index) => (
                    <Marker
                        key={place.id}
                        ref={(marker) => { markerRefs.current[place.id] = marker; }}
                        position={[place.lat, place.lng]}
                        icon={stopIcon(index + 1, place.id === selectedPlaceId)}
                        title={place.name}
                        zIndexOffset={place.id === selectedPlaceId ? 1000 : 0}
                        eventHandlers={{ click: () => onMarkerClick(place.id) }}
                    >
                        <Popup>
                            <div className="map-stop-popup-body">
                                <h4>{index + 1} · {place.name}</h4>
                                {timeLabel(place) && <p>{timeLabel(place)}</p>}
                                {place.address && place.address !== place.name && <p>{place.address}</p>}
                            </div>
                        </Popup>
                    </Marker>
                ))}
            </MapContainer>

            {isLoading && points.length > 1 && <div className="map-route-note"><Loader text="Строим маршрут по дорогам…" /></div>}
            {hasStraightLines && (
                <div className="map-route-note">Сервис маршрутов не ответил — линия показана по прямой.</div>
            )}
        </div>
    );
}
