import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { AttributionControl, MapContainer, Marker, Polyline, Popup, TileLayer } from 'react-leaflet';
import 'leaflet/dist/leaflet.css';

import AddPlaceOnMap from './AddPlaceOnMap';
import CustomZoomControl from '../Map/UI/CustomZoomControl';
import FitToPlaces from '../Map/Logic/FitToPlaces';
import Loader from '../Loader';
import useRouteLines from '../Map/Logic/useRouteLines';
import { lodgingIcon, stopIcon } from '../../utils/mapIcons';
import { dayLodgings, dayRoute } from '../../utils/dayRoute';
import { findArea } from '../../utils/geocoding';
import { timeLabel } from '../../utils/travel';

/**
 * Карта одного дня на вкладке «План»: нумерованные маркеры мест (номера — как в списке слева),
 * жильё и линия маршрута по дорогам (OSRM, см. useRouteLines).
 *
 * selectedPlaceId — место, выбранное в списке: его маркер подсвечен, карта сдвигается к нему.
 * onMarkerClick(id) — нажали на маркер: страница подсвечивает место в списке и прокручивает к нему.
 * categories — передаёт только вкладка «План» владельца: тогда кликом по карте можно добавить место (AddPlaceOnMap).
 * canEdit — можно ли менять план (Trip::tabs()); у завершённой поездки false — карта только для просмотра.
 * emptyCenter / destination — куда смотреть карте, если в дне пока ничего нет: точка поездки с сервера,
 * а если её нет — город поездки, найденный по названию в Nominatim.
 *
 * Страница создаёт компонент заново при смене дня (key), поэтому масштаб карты сбрасывается сам.
 * После изменений плана (↑↓, перенос, добавление, расчёт) сервер присылает новые места — номера и линия
 * пересчитываются из них же.
 */
export default function DayMap({ tripId, day, focusLodging, selectedPlaceId, onMarkerClick, categories, canEdit = true, emptyCenter, destination }) {
    const [map, setMap] = useState(null);
    const markerRefs = useRef({});
    const focusMarkerRef = useRef(null);

    const { points, profiles } = useMemo(() => dayRoute(day), [day]);
    const { lines, isLoading } = useRouteLines(points, profiles);
    const hasStraightLines = !isLoading && lines.some((line) => line.isStraight);
    const lodgings = dayLodgings(day, focusLodging);

    // В дне может не быть ни мест, ни жилья — тогда карту строим вокруг одного жилья из «Показать на карте».
    const mapPoints = points.length > 0 ? points : lodgings;
    // Карта владельца (вкладка «План»), а не публичная страница: пустой день центрируем по городу.
    const isOwnerMap = Boolean(tripId && categories);
    const canAddPlaces = isOwnerMap && canEdit;

    // Пустой день у владельца: карту всё равно показываем — на ней добавляют первое место.
    const [foundCenter, setFoundCenter] = useState(null);
    const needsCityCenter = isOwnerMap && mapPoints.length === 0 && !emptyCenter && Boolean(destination);

    useEffect(() => {
        if (!needsCityCenter) {
            return undefined;
        }

        // Тот же запрос делает список избранного в Plan.jsx — findArea отдаст обоим один ответ.
        let isActive = true;
        findArea(destination)
            .then((city) => isActive && city && setFoundCenter({ lat: city.lat, lng: city.lng }))
            .catch(() => {}); // Не нашли город — останется подсказка вместо карты.

        return () => {
            isActive = false;
        };
    }, [needsCityCenter, destination]);

    const center = mapPoints[0] ?? (isOwnerMap ? emptyCenter ?? foundCenter : null);

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

    if (!center) {
        return (
            <div className="day-map day-map--empty">
                <p className="form-hint">
                    {needsCityCenter ? 'Ищем город поездки на карте…' : 'Добавьте места в этот день — здесь появится маршрут на карте.'}
                </p>
            </div>
        );
    }

    return (
        <div className="day-map map-interactive-area">
            <MapContainer center={[center.lat, center.lng]} zoom={13} zoomControl={false} attributionControl={false} ref={setMap}>
                {/* Подпись OSM без префикса «Leaflet |» */}
                <AttributionControl prefix={false} />
                <TileLayer
                    attribution='© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                    url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                />
                {canAddPlaces && <AddPlaceOnMap tripId={tripId} day={day} categories={categories} />}

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
                                {/* На публичной странице (Trips/Public.jsx) tripId нет — ссылки во вкладки владельца не нужны. */}
                                {tripId && <Link href={`/my-trips/${tripId}/accommodation`}>Открыть жильё</Link>}
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
                        eventHandlers={{ click: () => onMarkerClick?.(place.id) }}
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
