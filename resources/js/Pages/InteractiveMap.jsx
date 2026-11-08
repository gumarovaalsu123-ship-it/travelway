import React, { useCallback, useMemo, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { MapContainer, Marker, TileLayer } from 'react-leaflet';
import 'leaflet/dist/leaflet.css';

import AppLayout from '../Components/AppLayout';
import CustomZoomControl from '../Components/Map/UI/CustomZoomControl';
import MapLayerToggle from '../Components/Map/UI/MapLayerToggle';
import MapSearch from '../Components/Map/UI/MapSearch';
import PlaceFormOverlay from '../Components/Map/UI/PlaceFormOverlay';
import PlaceMarker from '../Components/Map/UI/PlaceMarker';
import FitToPlaces from '../Components/Map/Logic/FitToPlaces';
import MapClickHandler from '../Components/Map/Logic/MapClickHandler';
import UserLocation from '../Components/Map/Logic/UserLocation';
import { reverseGeocode } from '../utils/geocoding';
import { draftIcon } from '../utils/mapIcons';
import { flyToPointBesideForm, revealPointBesideForm } from '../utils/mapView';

const DEFAULT_CENTER = [55.7963, 49.1089]; // Казань

const layerFilters = {
    all: () => true,
    trips: (place) => place.visits.length > 0,
    favorites: (place) => place.isFavorite,
};

export default function InteractiveMap({ categories, trips, places, focusPlaceId = null }) {
    const [map, setMap] = useState(null);
    const focusPlace = useMemo(() => places.find((place) => place.id === focusPlaceId) ?? null, [places, focusPlaceId]);
    const [draft, setDraft] = useState(null);
    const [layer, setLayer] = useState('all');
    const geocodeRequest = useRef(null);

    const visiblePlaces = useMemo(() => places.filter(layerFilters[layer]), [places, layer]);

    const closeDraft = useCallback(() => {
        geocodeRequest.current?.abort();
        setDraft(null);
    }, []);

    /** Новая точка на карте: показываем маркер и форму сразу, название подтягиваем из OSM. */
    const selectPoint = useCallback(async ({ lat, lng }) => {
        map?.closePopup();
        geocodeRequest.current?.abort();
        geocodeRequest.current = new AbortController();

        const key = `${lat}:${lng}:${Date.now()}`;
        setDraft({ key, lat, lng, isLoading: true });
        if (map) {
            revealPointBesideForm(map, [lat, lng]);
        }

        try {
            const result = await reverseGeocode(lat, lng, geocodeRequest.current.signal);
            setDraft((current) => (current?.key === key ? { ...current, ...result, lat, lng, isLoading: false } : current));
        } catch (error) {
            if (error.name !== 'AbortError') {
                setDraft((current) => (current?.key === key ? { ...current, isLoading: false } : current));
            }
        }
    }, [map]);

    /** Результат поиска уже содержит название и адрес — геокодировать повторно не нужно. */
    const selectSearchResult = useCallback((result) => {
        geocodeRequest.current?.abort();
        setDraft({ ...result, key: `search:${result.id}`, isLoading: false });
        if (map) {
            flyToPointBesideForm(map, [result.lat, result.lng], 17);
        }
    }, [map]);

    const addExistingPlaceToTrip = useCallback((place) => {
        map?.closePopup();
        setDraft({ key: `place:${place.id}:${Date.now()}`, lat: place.lat, lng: place.lng, place, isLoading: false });
        if (map) {
            revealPointBesideForm(map, [place.lat, place.lng]);
        }
    }, [map]);

    return (
        <AppLayout title="Интерактивная карта">
            <main className="container interactive-map-main">
                <header className="section-header section-header--spaced">
                    <h1 className="section-title">Интерактивная карта</h1>
                    <Link href="/my-trips" className="btn btn--outline-dark">Перейти к путешествиям</Link>
                </header>

                <div className="full-map-area mt-16">
                    <MapContainer center={DEFAULT_CENTER} zoom={13} zoomControl={false} ref={setMap}>
                        <TileLayer
                            attribution='© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                            url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                        />

                        <FitToPlaces places={places} focusPlace={focusPlace} />
                        <MapClickHandler onSelect={selectPoint} />
                        <UserLocation />
                        <CustomZoomControl />

                        {visiblePlaces.map((place) => (
                            <PlaceMarker
                                key={place.id}
                                place={place}
                                isSelected={draft?.place?.id === place.id}
                                isFocused={place.id === focusPlace?.id}
                                onAddToTrip={addExistingPlaceToTrip}
                            />
                        ))}

                        {draft && !draft.place && (
                            <Marker position={[draft.lat, draft.lng]} icon={draftIcon(draft.lat, draft.lng)} interactive={false} />
                        )}
                    </MapContainer>

                    <div className="map-toolbar">
                        <MapSearch map={map} onSelect={selectSearchResult} />
                        <MapLayerToggle value={layer} onChange={setLayer} />
                    </div>

                    {places.length === 0 && !draft && (
                        <div className="map-empty-hint">Нажмите на карту или найдите место, чтобы сохранить его</div>
                    )}

                    {draft && (
                        <PlaceFormOverlay
                            draft={draft}
                            categories={categories}
                            trips={trips}
                            onClose={closeDraft}
                            onSaved={closeDraft}
                        />
                    )}
                </div>
            </main>
        </AppLayout>
    );
}
