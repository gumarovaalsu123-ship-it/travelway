import React from 'react';
import { Link } from '@inertiajs/react';
import TripLayout, { TRIP_TABS } from '../../Components/TripLayout';

/**
 * Адрес вкладки поездки по её ключу (ключи — из TRIP_TABS). null — у шага пока нет своей вкладки.
 */
function tabUrl(tripId, tabKey) {
    const tab = TRIP_TABS.find((item) => item.key === tabKey);

    return tab ? `/my-trips/${tripId}${tab.path}` : null;
}

function StepCard({ step, number, tripId }) {
    const url = tabUrl(tripId, step.tab);

    return (
        <li className={`prep-step ${step.ready ? 'prep-step--ready' : ''}`}>
            <span className="prep-step__icon" aria-hidden="true">{step.ready ? '✓' : '!'}</span>
            <div className="prep-step__info">
                <div className="prep-step__title">{number}. {step.title}</div>
                <div className="prep-step__summary">{step.summary}</div>
            </div>
            <span className={`prep-step__status ${step.ready ? 'prep-step__status--ready' : ''}`}>
                {step.ready ? 'Готово' : 'Не хватает'}
            </span>
            {url && <Link href={url} className="btn btn--outline btn--sm">Открыть</Link>}
        </li>
    );
}

function HintList({ hints, tripId, warning = false }) {
    return (
        <ul className="prep-hints">
            {hints.map((hint, index) => {
                const url = tabUrl(tripId, hint.tab);

                return (
                    <li key={index} className={`prep-hint ${warning ? 'prep-hint--warning' : ''}`}>
                        <span>{hint.text}</span>
                        {url && <Link href={url} className="prep-hint__link">Исправить →</Link>}
                    </li>
                );
            })}
        </ul>
    );
}

/**
 * Страница поездки, вкладка «Подготовка»: 5 шагов со статусами, общая готовность
 * и подсказки «чего не хватает». Всё считает сервер (app/Services/TripReadiness.php).
 */
export default function TripShow({ trip, description, readiness }) {
    // Подсказки всех шагов одним списком, в порядке шагов.
    const hints = readiness.steps.flatMap((step) => step.hints);
    const warnings = readiness.steps.flatMap((step) => step.warnings);

    return (
        <TripLayout trip={trip} activeTab="overview">
            <main className="container trip-content">
                <header className="section-header">
                    <h2 className="section-title">Подготовка к поездке</h2>
                    <p className="section-subtitle">Пять шагов: как добраться, где жить, что взять, что сделать заранее и план по дням.</p>
                </header>

                <div className="trip-overview-grid">
                    <div className="short-plan-card">
                        <div className="prep-progress">
                            <div className="progress-info">
                                <span className="progress-label">Готовность</span>
                                <span className="progress-value">{readiness.percent}%</span>
                            </div>
                            <div className="progress-track">
                                <div className="progress-fill" style={{ width: `${readiness.percent}%` }} />
                            </div>
                        </div>

                        <ol className="prep-steps">
                            {readiness.steps.map((step, index) => (
                                <StepCard key={step.key} step={step} number={index + 1} tripId={trip.id} />
                            ))}
                        </ol>
                    </div>

                    <div className="overview-map-col">
                        <div className="short-plan-card">
                            <h3 className="short-plan__title">Чего не хватает</h3>
                            {hints.length > 0
                                ? <HintList hints={hints} tripId={trip.id} />
                                : <p className="prep-empty">Всё готово — хорошей поездки!</p>}

                            {warnings.length > 0 && (
                                <>
                                    <h3 className="short-plan__title">Обратите внимание</h3>
                                    <HintList hints={warnings} tripId={trip.id} warning />
                                </>
                            )}
                        </div>

                        <Link href="/interactive-map" className="btn btn--solid btn--full">Добавить места на карте</Link>
                        <Link href={`/my-trips/${trip.id}/edit`} className="btn btn--outline-dark btn--full">Настройки путешествия</Link>
                        {description && (
                            <div className="short-plan-card">
                                <h3 className="short-plan__title">О поездке</h3>
                                <p className="trip-overview__description">{description}</p>
                            </div>
                        )}
                    </div>
                </div>
            </main>
        </TripLayout>
    );
}
