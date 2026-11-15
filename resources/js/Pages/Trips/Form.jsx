import React, { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import AppLayout from '../../Components/AppLayout';
import { plural } from '../../utils/format';

const MAX_DAYS = 60;
const DAY_OPTIONS = Array.from({ length: MAX_DAYS }, (_, index) => index + 1);

function daysBetween(startDate, endDate) {
    if (!startDate || !endDate || endDate < startDate) {
        return null;
    }

    return Math.round((new Date(endDate) - new Date(startDate)) / 86_400_000) + 1;
}

/**
 * Дата окончания поездки длиной daysCount дней, начиная со startDate (формат «2026-06-15»).
 */
function endDateFor(startDate, daysCount) {
    const endDate = new Date(`${startDate}T00:00:00Z`);
    endDate.setUTCDate(endDate.getUTCDate() + Number(daysCount) - 1);

    return endDate.toISOString().slice(0, 10);
}

/**
 * Поле формы с подсказкой: при ошибке подсказка заменяется текстом ошибки (как в макете).
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
 * «Доступ по ссылке» в «Настройках» (только у созданной поездки). Ссылка вида /t/<секретный токен>
 * открывается без входа (PublicTripController). Закрыть доступ — токен стирается и старая ссылка перестаёт работать.
 * Это отдельные кнопки, а не поле формы: доступ меняется сразу, без «Сохранить изменения».
 */
function ShareLink({ trip }) {
    const [isCopied, setIsCopied] = useState(false);
    const options = { preserveScroll: true };

    // navigator.clipboard есть только на https и localhost. На локальном домене OSPanel (http) его нет —
    // тогда выделяем текст в поле и копируем старым способом execCommand('copy').
    const copyLink = async (event) => {
        if (navigator.clipboard) {
            await navigator.clipboard.writeText(trip.publicUrl);
        } else {
            event.currentTarget.parentElement.querySelector('input').select();
            document.execCommand('copy');
        }

        setIsCopied(true);
    };

    const closeAccess = () => {
        if (window.confirm('Закрыть доступ? Старая ссылка перестанет открываться у всех, кому вы её отправили.')) {
            router.delete(`/my-trips/${trip.id}/share`, options);
        }
    };

    return (
        <div className="form-group share-link-box">
            <span className="form-label">Доступ по ссылке</span>

            {trip.publicUrl ? (
                <>
                    <span className="form-hint form-hint--top">
                        Маршрут могут посмотреть все, у кого есть ссылка, — без входа и без возможности что-то изменить.
                        Заметки, брони и адрес жилья им не видны.
                    </span>
                    <div className="share-link-box__row">
                        <input
                            className="form-input form-input--filled"
                            value={trip.publicUrl}
                            readOnly
                            onFocus={(event) => event.target.select()}
                            aria-label="Публичная ссылка"
                        />
                        <button type="button" className="btn btn--solid-dark" onClick={copyLink}>
                            {isCopied ? 'Скопировано' : 'Скопировать ссылку'}
                        </button>
                        <button type="button" className="btn btn--outline-dark" onClick={closeAccess}>Закрыть доступ</button>
                    </div>
                </>
            ) : (
                <>
                    <span className="form-hint form-hint--top">
                        Сейчас путешествие видите только вы. Откройте доступ, чтобы отправить маршрут друзьям.
                    </span>
                    <div className="share-link-box__row">
                        <button type="button" className="btn btn--outline-dark" onClick={() => router.post(`/my-trips/${trip.id}/share`, {}, options)}>
                            Открыть доступ по ссылке
                        </button>
                    </div>
                </>
            )}
        </div>
    );
}

/**
 * Форма поездки. previousTrip — когда создаём следующий город цепочки (после переезда «Дальше»):
 * город и дата начала уже подставлены из этого переезда.
 */
export default function TripForm({ trip, previousTrip = null }) {
    const isEditing = Boolean(trip);
    const chainStartDate = previousTrip?.startDate ?? '';

    const { data, setData, post, put, processing, errors } = useForm({
        title: trip?.title ?? previousTrip?.destination ?? '',
        destination: trip?.destination ?? previousTrip?.destination ?? '',
        description: trip?.description ?? '',
        start_date: trip?.startDate ?? chainStartDate,
        end_date: trip?.endDate ?? (chainStartDate ? endDateFor(chainStartDate, 3) : ''),
        days_count: trip?.daysCount ?? 3,
        previous_trip_id: previousTrip?.id ?? null,
    });

    const hasDates = Boolean(data.start_date || data.end_date);
    const daysFromDates = daysBetween(data.start_date, data.end_date);
    const resultingDays = hasDates ? daysFromDates : Number(data.days_count);
    const removesPlannedDays = isEditing && resultingDays && resultingDays < trip.lastPlannedDay;

    const submit = (event) => {
        event.preventDefault();

        if (removesPlannedDays && !window.confirm(
            `В поездке станет ${resultingDays} ${plural(resultingDays, ['день', 'дня', 'дней'])}. `
            + 'Места, запланированные на последующие дни, будут удалены из плана. Продолжить?',
        )) {
            return;
        }

        if (isEditing) {
            put(`/my-trips/${trip.id}`);
        } else {
            post('/my-trips');
        }
    };

    /**
     * Удалить поездку. Кнопка здесь, в «Настройках», — карточка в «Моих путешествиях» целиком ведёт на поездку.
     */
    const removeTrip = () => {
        const warning = trip.placesCount > 0
            ? ` Вместе с ним из плана удалятся ${trip.placesCount} ${plural(trip.placesCount, ['место', 'места', 'мест'])}.`
            : '';

        if (window.confirm(`Удалить путешествие «${trip.title}»?${warning}`)) {
            router.delete(`/my-trips/${trip.id}`);
        }
    };

    /**
     * Дата окончания подстраивается, если её ещё нет или она оказалась раньше начала:
     * длина поездки (выбранное число дней) при этом сохраняется.
     */
    const changeStartDate = (value) => {
        setData((current) => {
            if (!value || (current.end_date && current.end_date >= value)) {
                return { ...current, start_date: value };
            }

            return { ...current, start_date: value, end_date: endDateFor(value, current.days_count) };
        });
    };

    return (
        <AppLayout title={isEditing ? 'Настройки путешествия' : 'Создать путешествие'}>
            <main className="create-trip-main">
                <div className="create-trip-wrapper">
                    <Link href="/my-trips" className="back-link">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M19 12H5M12 19l-7-7 7-7" />
                        </svg>
                        Вернуться к моим путешествиям
                    </Link>

                    <section className="form-card">
                        <header className="form-card__header">
                            <h1 className="form-card__title">{isEditing ? 'Настройки путешествия' : 'Создать путешествие'}</h1>
                            <p className="form-card__subtitle">
                                {isEditing
                                    ? 'Измените данные поездки. Дни поездки пересчитаются автоматически.'
                                    : 'Заполните обязательные поля — после создания вы сможете добавить места и детали поездки'}
                            </p>
                        </header>

                        {trip?.isCompleted && (
                            <div className="draft-status-box">
                                <span className="draft-status__title">Поездка завершена</span>
                                <p className="draft-status__desc">
                                    Её план и вещи доступны только для просмотра. Здесь можно исправить ошибочные даты или удалить поездку.
                                </p>
                            </div>
                        )}

                        {previousTrip && (
                            <div className="draft-status-box">
                                <span className="draft-status__title">Следующий город после «{previousTrip.title}»</span>
                                <p className="draft-status__desc">
                                    Поездки свяжутся в цепочку: переезд «Дальше» из предыдущей поездки станет дорогой туда.
                                </p>
                            </div>
                        )}
                        {errors.previous_trip_id && (
                            <div className="form-group has-error">
                                <span className="form-hint">{errors.previous_trip_id}</span>
                            </div>
                        )}

                        <form className="create-trip-form" onSubmit={submit} noValidate>
                            <Field id="title" label="Название путешествия" required hint="Название должно быть не менее 3 символов" error={errors.title}>
                                <input
                                    id="title"
                                    type="text"
                                    className="form-input form-input--filled"
                                    placeholder="Майские каникулы в горах Грузии"
                                    value={data.title}
                                    onChange={(event) => setData('title', event.target.value)}
                                    maxLength={120}
                                />
                            </Field>

                            <Field id="destination" label="Город или направление" required error={errors.destination}>
                                <input
                                    id="destination"
                                    type="text"
                                    className="form-input form-input--filled"
                                    placeholder="Грузия, Тбилиси"
                                    value={data.destination}
                                    onChange={(event) => setData('destination', event.target.value)}
                                    maxLength={120}
                                />
                            </Field>

                            <Field id="description" label="Описание" error={errors.description}>
                                <textarea
                                    id="description"
                                    className="form-input form-input--filled"
                                    rows={3}
                                    placeholder="Пара слов о поездке (необязательно)"
                                    value={data.description}
                                    onChange={(event) => setData('description', event.target.value)}
                                    maxLength={2000}
                                />
                            </Field>

                            <div className="form-row">
                                <Field id="start_date" label="Дата начала" error={errors.start_date}>
                                    <input
                                        id="start_date"
                                        type="date"
                                        className="form-input form-input--filled"
                                        value={data.start_date}
                                        onChange={(event) => changeStartDate(event.target.value)}
                                    />
                                </Field>
                                <Field id="end_date" label="Дата окончания" error={errors.end_date}>
                                    <input
                                        id="end_date"
                                        type="date"
                                        className="form-input form-input--filled"
                                        min={data.start_date || undefined}
                                        value={data.end_date}
                                        onChange={(event) => setData('end_date', event.target.value)}
                                    />
                                </Field>
                            </div>

                            {hasDates && (
                                <p className="form-hint form-hint--top">
                                    {daysFromDates
                                        ? `В поездке ${daysFromDates} ${plural(daysFromDates, ['день', 'дня', 'дней'])}. `
                                        : ''}
                                    <button type="button" className="link-button" onClick={() => setData((current) => ({ ...current, start_date: '', end_date: '' }))}>
                                        Даты ещё не известны
                                    </button>
                                </p>
                            )}

                            {!hasDates && (
                                <>
                                    <div className="form-divider">или</div>

                                    <div className={`form-group ${errors.days_count ? 'has-error' : ''}`}>
                                        <label htmlFor="days_count" className="form-label">Если даты ещё не известны</label>
                                        <span className="form-hint form-hint--top">
                                            Вы можете выбрать количество дней в поездке, а позже настроить даты путешествия
                                        </span>
                                        <div className="form-input-inner">
                                            <select
                                                id="days_count"
                                                className="form-input form-input--filled form-select"
                                                value={data.days_count}
                                                onChange={(event) => setData('days_count', Number(event.target.value))}
                                            >
                                                {DAY_OPTIONS.map((count) => (
                                                    <option key={count} value={count}>{count} {plural(count, ['день', 'дня', 'дней'])}</option>
                                                ))}
                                            </select>
                                            <span className="form-icon-suffix">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                                    <polyline points="6 9 12 15 18 9" />
                                                </svg>
                                            </span>
                                        </div>
                                        {errors.days_count && <span className="form-hint">{errors.days_count}</span>}
                                    </div>
                                </>
                            )}

                            {removesPlannedDays && (
                                <div className="draft-status-box">
                                    <span className="draft-status__title">Часть плана будет удалена</span>
                                    <p className="draft-status__desc">
                                        Места запланированы до {trip.lastPlannedDay}-го дня, а в поездке останется {resultingDays} {plural(resultingDays, ['день', 'дня', 'дней'])}.
                                    </p>
                                </div>
                            )}

                            <div className="form-actions">
                                <Link href="/my-trips" className="btn btn--outline-dark">Отмена без сохранения</Link>
                                <button type="submit" className="btn btn--solid-dark" disabled={processing}>
                                    {isEditing ? 'Сохранить изменения' : 'Создать и добавить места'}
                                </button>
                            </div>
                        </form>

                        {isEditing && <ShareLink trip={trip} />}

                        {isEditing && (
                            <div className="form-danger-zone">
                                <button type="button" className="btn btn--danger-outline" onClick={removeTrip}>Удалить путешествие</button>
                            </div>
                        )}
                    </section>
                </div>
            </main>
        </AppLayout>
    );
}
