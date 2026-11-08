import React, { useMemo, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '../../Components/AppLayout';
import { plural } from '../../utils/format';

const WITHOUT_CATEGORY = 'none';

function FavoriteCard({ place }) {
    const remove = () => {
        if (window.confirm(`Убрать «${place.name}» из избранного?`)) {
            router.delete(`/places/${place.id}/favorite`, { preserveScroll: true });
        }
    };

    return (
        <article className="favorite-card">
            <span className="favorite-card__category">{place.category?.name ?? 'Без категории'}</span>
            <h3 className="favorite-card__title">{place.name}</h3>
            {place.address && place.address !== place.name && (
                <p className="favorite-card__address">{place.address}</p>
            )}
            <div className="favorite-card__actions">
                <Link href={`/interactive-map?place=${place.id}`} className="btn btn--solid btn--sm">
                    Показать на карте
                </Link>
                <button type="button" className="btn btn--outline btn--sm" onClick={remove}>
                    Удалить
                </button>
            </div>
        </article>
    );
}

export default function FavoritesIndex({ places }) {
    const [filter, setFilter] = useState('all');

    /** Кнопки фильтра строим только по тем категориям, которые реально есть в избранном. */
    const filters = useMemo(() => {
        const byCategory = new Map();

        places.forEach((place) => {
            const value = place.category?.slug ?? WITHOUT_CATEGORY;
            const label = place.category?.name ?? 'Без категории';
            const current = byCategory.get(value) ?? { value, label, count: 0 };
            byCategory.set(value, { ...current, count: current.count + 1 });
        });

        return [{ value: 'all', label: 'Все', count: places.length }, ...byCategory.values()];
    }, [places]);

    // Если удалили последнее место категории — фильтр сбрасываем на «Все».
    const activeFilter = filters.some((item) => item.value === filter) ? filter : 'all';
    const visiblePlaces = activeFilter === 'all'
        ? places
        : places.filter((place) => (place.category?.slug ?? WITHOUT_CATEGORY) === activeFilter);

    return (
        <AppLayout title="Избранное">
            <main className="container trips-main">
                <section className="page-header">
                    <div className="page-header__text">
                        <h1 className="page-title">Избранное</h1>
                        <p className="page-subtitle">
                            {places.length > 0
                                ? `${places.length} ${plural(places.length, ['место', 'места', 'мест'])}, которые вы хотите посетить`
                                : 'Места, которые вы хотите посетить'}
                        </p>
                    </div>
                    <Link href="/interactive-map" className="btn btn--solid">Найти места на карте</Link>
                </section>

                {places.length === 0 ? (
                    <section className="trips-empty">
                        <h2 className="trips-empty__title">Здесь пока пусто</h2>
                        <p className="trips-empty__text">
                            Откройте интерактивную карту, выберите точку и сохраните её в избранное — она появится здесь.
                        </p>
                        <Link href="/interactive-map" className="btn btn--solid">Открыть карту</Link>
                    </section>
                ) : (
                    <>
                        {filters.length > 2 && (
                            <section className="trips-filters">
                                {filters.map((item) => (
                                    <button
                                        key={item.value}
                                        type="button"
                                        className={`filter-btn ${activeFilter === item.value ? 'active' : ''}`}
                                        onClick={() => setFilter(item.value)}
                                    >
                                        {item.label} {item.count}
                                    </button>
                                ))}
                            </section>
                        )}

                        <section className="trips-grid">
                            {visiblePlaces.map((place) => <FavoriteCard key={place.id} place={place} />)}
                        </section>
                    </>
                )}
            </main>
        </AppLayout>
    );
}
