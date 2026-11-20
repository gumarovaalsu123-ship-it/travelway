import React from 'react';
import { router, usePage } from '@inertiajs/react';
import AdminLayout from '../../Components/AdminLayout';
import AdminPagination from '../../Components/AdminPagination';
import AdminSearch from '../../Components/AdminSearch';
import { formatDateRange, plural } from '../../utils/format';
import { confirmDialog } from '../../utils/confirm';

const requestOptions = { preserveScroll: true };

function TripRow({ trip, canFeatureMore }) {
    const toggleFeatured = () => {
        router.patch(`/admin/trips/${trip.id}/${trip.isFeatured ? 'unfeature' : 'feature'}`, {}, requestOptions);
    };

    const closeAccess = async () => {
        if (await confirmDialog({
            title: 'Закрыть доступ по ссылке?',
            message: `Ссылка на «${trip.title}» перестанет открываться у всех. Сама поездка останется у автора (${trip.author}).`,
            confirmLabel: 'Закрыть доступ',
            danger: true,
        })) {
            router.delete(`/admin/trips/${trip.id}/share`, requestOptions);
        }
    };

    return (
        <tr>
            <td>
                <div className="admin-table__name">
                    {trip.title}
                    {trip.isFeatured && <span className="badge--light">на главной</span>}
                </div>
                <div className="admin-table__email">{trip.destination}</div>
            </td>
            <td data-label="Автор">{trip.author}</td>
            <td data-label="Даты">{trip.startDate ? formatDateRange(trip.startDate, trip.endDate) : `${trip.daysCount} ${plural(trip.daysCount, ['день', 'дня', 'дней'])}`}</td>
            <td data-label="Мест">{trip.placesCount}</td>
            <td>
                <div className="admin-table__actions">
                    <a href={trip.publicUrl} target="_blank" rel="noreferrer" className="admin-action">Открыть</a>
                    {/* Когда на главной уже три примера, добавить нельзя — сначала убрать один. */}
                    {(trip.isFeatured || canFeatureMore) && (
                        <button type="button" className="admin-action" onClick={toggleFeatured}>
                            {trip.isFeatured ? 'Убрать с главной' : 'На главную'}
                        </button>
                    )}
                    <button type="button" className="admin-action admin-action--danger" onClick={closeAccess}>
                        Закрыть доступ
                    </button>
                </div>
            </td>
        </tr>
    );
}

/**
 * Все поездки, открытые по ссылке. Отсюда администратор выбирает «Примеры маршрутов» для главной
 * и может закрыть ссылку, если с поездкой что-то не так (модерация).
 */
export default function AdminPublicTrips({ trips, search, featuredCount, featuredLimit }) {
    const { errors } = usePage().props;
    const canFeatureMore = featuredCount < featuredLimit;

    const searchForm = <AdminSearch url="/admin/trips" search={search} placeholder="Название, город или автор" />;

    return (
        <AdminLayout
            title="Публичные маршруты"
            subtitle={`На главной ${featuredCount} из ${featuredLimit} примеров · всего открыто по ссылке: ${trips.total}`}
            actions={searchForm}
        >
            {errors.trip && <div className="admin-alert" role="alert">{errors.trip}</div>}

            <section className="admin-table-card">
                {trips.data.length === 0 ? (
                    <p className="admin-empty">{search ? 'Ничего не нашли.' : 'Пока никто не открыл доступ к своей поездке.'}</p>
                ) : (
                    <table className="admin-table">
                        <thead>
                            <tr>
                                <th>Поездка</th>
                                <th>Автор</th>
                                <th>Даты</th>
                                <th>Мест</th>
                                <th aria-label="Действия" />
                            </tr>
                        </thead>
                        <tbody>
                            {trips.data.map((trip) => <TripRow key={trip.id} trip={trip} canFeatureMore={canFeatureMore} />)}
                        </tbody>
                    </table>
                )}
            </section>

            <AdminPagination paginator={trips} />
        </AdminLayout>
    );
}
