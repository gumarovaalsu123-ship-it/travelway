import React, { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import Checkbox from '../../Components/Checkbox';
import Modal from '../../Components/Modal';
import StationInput from '../../Components/StationInput';
import TripLayout, { canEditTab } from '../../Components/TripLayout';

/**
 * Подписи и иконки видов транспорта. Ключи совпадают с Transport::Types на сервере.
 */
const TYPE_LABELS = {
    plane: 'Самолёт',
    train: 'Поезд',
    bus: 'Автобус',
    own_car: 'Своё авто',
    car: 'Такси или аренда авто',
    ferry: 'Паром',
    other: 'Другое',
};

/**
 * Направление переезда. Ключи совпадают с Transport::Directions на сервере.
 */
const DIRECTION_LABELS = {
    to: 'Туда',
    back: 'Обратно',
    onward: 'Дальше',
};

const CAR_ICON = (
    <>
        <path d="M5 17H3v-5l2-5h14l2 5v5h-2" />
        <circle cx="7.5" cy="17" r="2" />
        <circle cx="16.5" cy="17" r="2" />
        <path d="M9.5 17h5M3 12h18" />
    </>
);

const ICON_PATHS = {
    plane: <path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.2-1.1.7l-1.2 3.6 7.6 4.3-3.6 3.6-3.8-.9c-.4-.1-.8.1-1 .5l-1 2.2 4.8 1.4 1.4 4.8 2.2-1c.4-.2.6-.6.5-1l-.9-3.8 3.6-3.6 4.3 7.6c.5-.2.8-.6.7-1.1z" />,
    train: (
        <>
            <rect x="4" y="3" width="16" height="16" rx="2" />
            <path d="M4 11h16M12 3v8M8 19l-2 3M18 22l-2-3M8 15h.01M16 15h.01" />
        </>
    ),
    bus: (
        <>
            <rect x="3" y="4" width="18" height="13" rx="2" />
            <path d="M3 11h18M7 17v3M17 17v3M7 14h.01M17 14h.01" />
        </>
    ),
    own_car: CAR_ICON,
    car: CAR_ICON,
    ferry: <path d="M2 20c2 1 4 1 6 0s4-1 6 0 4 1 6 0M4 16l-1-5h18l-1 5M6 11V6h12v5M12 6V3" />,
    other: <path d="M5 12h14M12 5l7 7-7 7" />,
};

/**
 * Подписи формы по виду транспорта: у самолёта — аэропорты и вылет/прилёт, у поезда — вокзалы,
 * у автобуса — автовокзалы, у своего авто — просто города и выезд/приезд.
 * search — подпись кнопки поиска станций (нет — станций у этого вида нет, поле обычное).
 * Номер рейса или поезда пишут в заметку — отдельного поля нет, подсказка в placeholder.
 */
const GENERIC_FORM = {
    from: 'Откуда', to: 'Куда', fromPlaceholder: 'Город или адрес', toPlaceholder: 'Город или адрес',
    departure: 'Отправление', arrival: 'Прибытие', ticket: 'Билет куплен', note: 'Что важно не забыть в дороге',
};

const TYPE_FORM = {
    plane: {
        ...GENERIC_FORM, from: 'Аэропорт вылета', to: 'Аэропорт прилёта', fromPlaceholder: 'Например, Шереметьево',
        toPlaceholder: 'Например, Казань', departure: 'Вылет', arrival: 'Прилёт', search: 'Найти аэропорт',
        note: 'Рейс SU 1234, багаж 23 кг, регистрация онлайн',
    },
    train: {
        ...GENERIC_FORM, from: 'Вокзал отправления', to: 'Вокзал прибытия', fromPlaceholder: 'Например, Казанский вокзал',
        toPlaceholder: 'Например, Казань', search: 'Найти вокзал', note: 'Поезд 002А, вагон 5, место 12',
    },
    bus: {
        ...GENERIC_FORM, from: 'Автовокзал отправления', to: 'Автовокзал прибытия', fromPlaceholder: 'Например, автовокзал Щёлковский',
        toPlaceholder: 'Например, Владимир', search: 'Найти автовокзал', note: 'Перевозчик, место, багаж',
    },
    ferry: {
        ...GENERIC_FORM, from: 'Порт отправления', to: 'Порт прибытия', fromPlaceholder: 'Например, Морской вокзал',
        toPlaceholder: 'Например, Калининград', search: 'Найти порт',
    },
    own_car: {
        ...GENERIC_FORM, from: 'Откуда (город)', to: 'Куда (город)', departure: 'Выезд', arrival: 'Приезд',
        note: 'Маршрут, остановки в пути, платные дороги',
    },
    car: {
        ...GENERIC_FORM, departure: 'Подача машины', arrival: 'Приезд', ticket: 'Заказ оформлен',
        note: 'Служба такси или прокат, телефон',
    },
    other: GENERIC_FORM,
};

/**
 * Направление для новой записи: если дорога туда уже есть, а обратно — нет, скорее всего добавляют обратную.
 */
function suggestDirection(transports) {
    const hasWayThere = transports.some((transport) => transport.direction === 'to');
    const hasWayBack = transports.some((transport) => ['back', 'onward'].includes(transport.direction));

    return hasWayThere && !hasWayBack ? 'back' : 'to';
}

const dateFormatter = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' });

/**
 * Время приходит строкой «2026-06-15T13:15»: часы берём прямо из строки, дату показываем словами.
 */
const formatTime = (dateTime) => (dateTime ? dateTime.slice(11, 16) : '—');
const formatDate = (dateTime) => (dateTime ? dateFormatter.format(new Date(dateTime)) : 'Время не указано');

function TransportIcon({ type }) {
    return (
        <div className="transport-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                {ICON_PATHS[type] ?? ICON_PATHS.other}
            </svg>
        </div>
    );
}

/**
 * Метка «Билет куплен» / «Билет не куплен». Для своего авто билет не нужен.
 */
function TicketStatus({ transport }) {
    if (!transport.needsTicket) {
        return <span className="booking-status booking-status--none">Билет не нужен</span>;
    }

    return transport.isBooked
        ? <span className="booking-status booking-status--done">Билет куплен</span>
        : <span className="booking-status booking-status--todo">Билет не куплен</span>;
}

/**
 * Карточка переезда. canEdit — можно ли менять (Trip::tabs()); без него кнопок «Изменить/Удалить» нет.
 */
function TransportCard({ transport, canEdit, onEdit, onDelete }) {
    return (
        <article className="transport-card">
            <header className="transport-card__header">
                <div className="transport-card__title-group">
                    <TransportIcon type={transport.type} />
                    <div className="transport-card__headings">
                        <h3 className="transport-title">{transport.departurePlace} → {transport.arrivalPlace}</h3>
                        <p className="transport-subtitle">{formatDate(transport.departureAt)}</p>
                    </div>
                </div>
                <div className="transport-card__badges">
                    <span className="day-badge">{DIRECTION_LABELS[transport.direction] ?? 'Направление не указано'}</span>
                    <span className="day-badge">{TYPE_LABELS[transport.type]}</span>
                </div>
            </header>

            <div className="transport-card__body">
                <div className="time-block">
                    <div className="time-block__time">{formatTime(transport.departureAt)}</div>
                    <div className="time-block__place">{transport.departurePlace}</div>
                </div>
                <div className="time-block__arrow">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <path d="M5 12h14M12 5l7 7-7 7" />
                    </svg>
                </div>
                <div className="time-block">
                    <div className="time-block__time">{formatTime(transport.arrivalAt)}</div>
                    <div className="time-block__place">{transport.arrivalPlace}</div>
                </div>
            </div>

            <footer className="transport-card__footer">
                <TicketStatus transport={transport} />
                <p className="transport-card__meta">{transport.note || 'Без заметок'}</p>
                {canEdit && (
                    <div className="transport-card__actions">
                        <button type="button" className="btn btn--outline-dark btn--sm" onClick={() => onEdit(transport)}>Изменить</button>
                        <button type="button" className="btn btn--danger-outline btn--sm" onClick={() => onDelete(transport)}>Удалить</button>
                    </div>
                )}
            </footer>
        </article>
    );
}

/**
 * Поле формы: при ошибке под полем показывается её текст.
 */
function Field({ id, label, required = false, hint, error, children }) {
    return (
        <div className={`form-group ${error ? 'has-error' : ''}`}>
            <label htmlFor={id} className="form-label">
                {label} {required && <span className="required">*</span>}
            </label>
            {children}
            {(error || hint) && <span className="form-hint">{error ?? hint}</span>}
        </div>
    );
}

/**
 * Цепочка городов: после записи «Дальше» предлагаем создать поездку в следующий город.
 * Город и дату начала форма создания возьмёт из этого переезда (TripController@create, ?previous=).
 */
function NextTripOffer({ trip, city }) {
    return (
        <section className="chain-offer">
            <p className="chain-offer__text">Вы едете дальше — в {city}. Создать следующую поездку?</p>
            <Link href={`/my-trips/create?previous=${trip.id}`} className="btn btn--solid-dark btn--sm">Создать поездку</Link>
        </section>
    );
}

/**
 * Форма добавления (transport = null) или редактирования записи — внутри модального окна.
 *
 * Даты подставляются сами (границы и время приходят с сервера в dateLimits):
 * — «Туда» — утро первого дня поездки, «Обратно» и «Дальше» — вечер последнего дня;
 * — выбрали отправление, а прибытие пустое или раньше — прибытие становится равным отправлению.
 * Подсказки min/max в полях только помогают выбрать дату; настоящая проверка — на сервере (TransportRequest).
 */
function TransportForm({ trip, transport, transports, types, directions, typesWithoutTicket, dateLimits, onClose }) {
    const isEditing = Boolean(transport);
    const initialDirection = transport ? (transport.direction ?? '') : suggestDirection(transports);

    const { data, setData, post, put, processing, errors } = useForm({
        type: transport?.type ?? 'plane',
        // У старой записи направления может не быть — тогда в списке выбрано «Выберите…».
        direction: initialDirection,
        departure_place: transport?.departurePlace ?? '',
        arrival_place: transport?.arrivalPlace ?? '',
        departure_at: transport ? (transport.departureAt ?? '') : (dateLimits?.[initialDirection]?.departure ?? ''),
        arrival_at: transport?.arrivalAt ?? '',
        is_booked: transport?.isBooked ?? false,
        note: transport?.note ?? '',
    });

    const labels = TYPE_FORM[data.type] ?? GENERIC_FORM;
    const limits = dateLimits?.[data.direction];
    // В новой записи время отправления подставлено формой (совпадает с одним из предложенных) — подскажем его уточнить.
    const isDepartureSuggested = !isEditing && Boolean(dateLimits)
        && Object.values(dateLimits).some((limit) => limit.departure === data.departure_at);

    // Своё авто: билет не покупают, поэтому галочку «Билет куплен» не показываем.
    const needsTicket = !typesWithoutTicket.includes(data.type);

    const changeDirection = (direction) => setData((current) => {
        // Свою дату человека не меняем; подставленную (или пустую) — меняем на дату нового направления.
        if (!isDepartureSuggested && current.departure_at !== '') {
            return { ...current, direction };
        }

        return { ...current, direction, departure_at: dateLimits?.[direction]?.departure ?? '', arrival_at: '' };
    });

    const changeDeparture = (departureAt) => setData((current) => ({
        ...current,
        departure_at: departureAt,
        // Строки «2026-06-15T13:15» можно сравнивать как даты обычным сравнением строк.
        arrival_at: departureAt && (current.arrival_at === '' || current.arrival_at < departureAt) ? departureAt : current.arrival_at,
    }));

    const submit = (event) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: onClose };

        if (isEditing) {
            put(`/my-trips/${trip.id}/transport/${transport.id}`, options);
        } else {
            post(`/my-trips/${trip.id}/transport`, options);
        }
    };

    return (
        <Modal title={isEditing ? 'Изменить запись' : 'Новая запись'} onClose={onClose}>
            <form onSubmit={submit} noValidate>
                <div className="form-row">
                    <Field id="type" label="Вид транспорта" required error={errors.type}>
                        <select
                            id="type"
                            className="form-input form-input--filled form-select"
                            value={data.type}
                            onChange={(event) => setData('type', event.target.value)}
                            autoFocus
                        >
                            {types.map((type) => (
                                <option key={type} value={type}>{TYPE_LABELS[type]}</option>
                            ))}
                        </select>
                    </Field>

                    <Field id="direction" label="Направление" required error={errors.direction}>
                        <select
                            id="direction"
                            className="form-input form-input--filled form-select"
                            value={data.direction}
                            onChange={(event) => changeDirection(event.target.value)}
                        >
                            {data.direction === '' && <option value="" disabled>Выберите…</option>}
                            {directions.map((direction) => (
                                <option key={direction} value={direction}>{DIRECTION_LABELS[direction]}</option>
                            ))}
                        </select>
                    </Field>
                </div>

                <Field id="departure_place" label={labels.from} required error={errors.departure_place}>
                    <StationInput
                        id="departure_place"
                        value={data.departure_place}
                        onChange={(value) => setData('departure_place', value)}
                        transportType={data.type}
                        placeholder={labels.fromPlaceholder}
                        searchLabel={labels.search}
                    />
                </Field>

                <Field id="arrival_place" label={labels.to} required error={errors.arrival_place}>
                    <StationInput
                        id="arrival_place"
                        value={data.arrival_place}
                        onChange={(value) => setData('arrival_place', value)}
                        transportType={data.type}
                        placeholder={labels.toPlaceholder}
                        searchLabel={labels.search}
                    />
                </Field>

                <div className="form-row">
                    <Field
                        id="departure_at"
                        label={labels.departure}
                        error={errors.departure_at}
                        hint={isDepartureSuggested ? 'Дата подставлена по датам поездки — уточните время.' : null}
                    >
                        <input
                            id="departure_at"
                            type="datetime-local"
                            className="form-input form-input--filled"
                            min={limits?.min}
                            max={limits?.max}
                            value={data.departure_at}
                            onChange={(event) => changeDeparture(event.target.value)}
                        />
                    </Field>

                    <Field id="arrival_at" label={labels.arrival} error={errors.arrival_at}>
                        <input
                            id="arrival_at"
                            type="datetime-local"
                            className="form-input form-input--filled"
                            min={data.departure_at || limits?.min}
                            max={limits?.max}
                            value={data.arrival_at}
                            onChange={(event) => setData('arrival_at', event.target.value)}
                        />
                    </Field>
                </div>

                {needsTicket && (
                    <div className="form-group">
                        <Checkbox
                            id="is_booked"
                            label={labels.ticket}
                            checked={data.is_booked}
                            onChange={(checked) => setData('is_booked', checked)}
                        />
                    </div>
                )}

                <Field id="note" label="Заметка" error={errors.note}>
                    <textarea
                        id="note"
                        className="form-input form-input--filled"
                        rows={3}
                        placeholder={labels.note}
                        value={data.note}
                        onChange={(event) => setData('note', event.target.value)}
                        maxLength={2000}
                    />
                </Field>

                <div className="form-actions-row">
                    <button type="button" className="btn btn--outline-dark" onClick={onClose}>Отмена</button>
                    <button type="submit" className="btn btn--solid-dark" disabled={processing}>
                        {isEditing ? 'Сохранить' : 'Добавить'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

/**
 * Страница поездки, вкладка «Транспорт»: переезды по времени; добавление и редактирование — в модальном окне.
 */
export default function TripTransport({ trip, transports, types, directions, typesWithoutTicket, dateLimits, onwardCity }) {
    // Какая форма открыта: null — никакая, 'new' — добавление, объект — редактируемая запись.
    const [openForm, setOpenForm] = useState(null);
    // Только просмотр (завершённая поездка): ни добавить, ни изменить запись нельзя.
    const canEdit = canEditTab(trip, 'transport');

    const destroy = (transport) => {
        if (window.confirm(`Удалить запись «${transport.departurePlace} → ${transport.arrivalPlace}»?`)) {
            router.delete(`/my-trips/${trip.id}/transport/${transport.id}`, { preserveScroll: true });
        }
    };

    return (
        <TripLayout trip={trip} activeTab="transport">
            <main className="container trip-transport-main trip-content">
                <header className="section-header section-header--spaced">
                    <div className="section-header__text">
                        <h2 className="section-title">Транспорт и билеты</h2>
                        <p className="section-subtitle section-subtitle--light">Перелёты, поезда и другие переезды поездки — в одном месте.</p>
                    </div>
                    {canEdit && <button type="button" className="btn btn--solid-dark" onClick={() => setOpenForm('new')}>+ Добавить запись</button>}
                </header>

                {onwardCity && <NextTripOffer trip={trip} city={onwardCity} />}

                <div className="transport-list">
                    {transports.length === 0 ? (
                        <section className="trips-empty">
                            <h3 className="trips-empty__title">Переездов пока нет</h3>
                            {canEdit && (
                                <>
                                    <p className="trips-empty__text">Добавьте перелёт, поезд или трансфер — записи выстроятся по времени отправления.</p>
                                    <button type="button" className="btn btn--solid" onClick={() => setOpenForm('new')}>+ Добавить запись</button>
                                </>
                            )}
                        </section>
                    ) : transports.map((transport) => (
                        <TransportCard key={transport.id} transport={transport} canEdit={canEdit} onEdit={setOpenForm} onDelete={destroy} />
                    ))}
                </div>

                {canEdit && openForm && (
                    <TransportForm
                        trip={trip}
                        transport={openForm === 'new' ? null : openForm}
                        transports={transports}
                        types={types}
                        directions={directions}
                        typesWithoutTicket={typesWithoutTicket}
                        dateLimits={dateLimits}
                        onClose={() => setOpenForm(null)}
                    />
                )}
            </main>
        </TripLayout>
    );
}
