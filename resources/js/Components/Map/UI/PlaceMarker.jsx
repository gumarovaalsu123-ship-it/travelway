import React, { useMemo } from 'react';
import { router } from '@inertiajs/react';
import { Marker, Popup } from 'react-leaflet';
import { placeIcon } from '../../../utils/mapIcons';
import { formatDayLabel } from '../../../utils/format';

const requestOptions = { preserveScroll: true, preserveState: true };

export default function PlaceMarker({ place, isSelected, onAddToTrip }) {
    const icon = useMemo(() => placeIcon(place, isSelected), [place, isSelected]);

    const toggleFavorite = () => {
        if (place.isFavorite) {
            router.delete(`/places/${place.id}/favorite`, requestOptions);
        } else {
            router.post(`/places/${place.id}/favorite`, {}, requestOptions);
        }
    };

    const removeVisit = (visit) => {
        if (window.confirm(`Убрать «${place.name}» из плана «${visit.tripTitle}»?`)) {
            router.delete(`/trip-places/${visit.id}`, requestOptions);
        }
    };

    return (
        <Marker position={[place.lat, place.lng]} icon={icon} title={place.name}>
            <Popup className="place-popup" closeButton>
                <div className="place-popup__body">
                    {place.category && <span className="place-popup__category">{place.category.name}</span>}
                    <h3 className="place-popup__title">{place.name}</h3>
                    {place.address && place.address !== place.name && <p className="place-popup__address">{place.address}</p>}

                    {place.visits.length > 0 && (
                        <ul className="place-popup__visits">
                            {place.visits.map((visit) => (
                                <li key={visit.id} className="place-popup__visit">
                                    <span>
                                        <strong>{visit.tripTitle}</strong>
                                        <br />
                                        {formatDayLabel(visit.dayNumber, visit.date)}
                                        {visit.startTime && ` · ${visit.startTime}`}
                                    </span>
                                    <button
                                        type="button"
                                        className="place-popup__visit-remove"
                                        aria-label="Убрать из плана"
                                        title="Убрать из плана"
                                        onClick={() => removeVisit(visit)}
                                    >
                                        ×
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}

                    <div className="place-popup__actions">
                        <button type="button" className="btn btn--outline-dark btn--sm" onClick={toggleFavorite}>
                            {place.isFavorite ? '♥ В избранном' : '♡ В избранное'}
                        </button>
                        <button type="button" className="btn btn--solid-dark btn--sm" onClick={() => onAddToTrip(place)}>
                            В поездку
                        </button>
                    </div>
                </div>
            </Popup>
        </Marker>
    );
}
