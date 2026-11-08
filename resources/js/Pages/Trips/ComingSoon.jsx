import React from 'react';
import { Link } from '@inertiajs/react';
import TripLayout, { TRIP_TABS } from '../../Components/TripLayout';

/**
 * Заглушка для вкладки поездки, которая ещё не готова.
 */
export default function TripComingSoon({ trip, tab }) {
    const label = TRIP_TABS.find((item) => item.key === tab)?.label;

    return (
        <TripLayout trip={trip} activeTab={tab}>
            <main className="container trip-content">
                <section className="trips-empty">
                    <h2 className="trips-empty__title">{label}: скоро появится</h2>
                    <p className="trips-empty__text">Этот раздел ещё в работе. Пока можно посмотреть обзор поездки.</p>
                    <Link href={`/my-trips/${trip.id}`} className="btn btn--solid">К обзору</Link>
                </section>
            </main>
        </TripLayout>
    );
}
