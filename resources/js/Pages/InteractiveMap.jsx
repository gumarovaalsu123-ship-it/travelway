import React, { useState, useEffect } from 'react';
import { Head, Link } from '@inertiajs/react';
import { MapContainer, TileLayer, Marker, useMapEvents } from 'react-leaflet';
import 'leaflet/dist/leaflet.css';
import L from 'leaflet';

import Header from '../Components/Header';
import Footer from '../Components/Footer';
import CustomZoomControl from '../Components/Map/UI/CustomZoomControl';
import PlaceFormOverlay from '../Components/Map/UI/PlaceFormOverlay';
import MapClickHandler from '../Components/Map/Logic/MapClickHandler';
import UserLocation from '../Components/Map/Logic/UserLocation';
import { fixLeafletIcons } from '../utils/leafletIconFix';


export default function InteractiveMap() {
    // 1. Инициализируем фикс иконок при первой загрузке
    useEffect(() => {
        fixLeafletIcons();
    }, []);
    // 2. Вся логика состояния страницы (стейт)
    const [selectedPosition, setSelectedPosition] = useState(null);
    // 3. Константы
    const defaultCenter = [55.7963, 49.1089];
    // 4. Чистый рендер
    return (
        <div className="page-bg-light">
            <Head title="Интерактивная карта | Тревелвей" />

            <Header />

            <main className="container interactive-map-main">
                <header className="section-header section-header--spaced">
                    <h1 className="section-title">Интерактивная карта</h1>
                    <Link href="/my-trips" className="btn btn--outline-dark">Перейти к путешествиям</Link>
                </header>

                <div className="full-map-area mt-16" style={{ position: 'relative', height: '600px', borderRadius: '16px', overflow: 'hidden' }}>
                    <MapContainer
                        center={defaultCenter}
                        zoom={14}
                        zoomControl={false}
                        style={{ width: '100%', height: '100%' }}
                    >
                        <TileLayer
                            attribution='© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                            url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                        />

                        <MapClickHandler setPosition={setSelectedPosition} />

                        <UserLocation />

                        {selectedPosition && <Marker position={selectedPosition} />}
                    </MapContainer>

                    <CustomZoomControl />

                    <PlaceFormOverlay
                        position={selectedPosition}
                        onClose={() => setSelectedPosition(null)}
                    />

                </div>
            </main>

            <Footer />

        </div>
    );
}
