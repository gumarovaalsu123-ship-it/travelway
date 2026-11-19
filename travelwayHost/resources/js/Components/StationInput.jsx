import React, { useEffect, useRef, useState } from 'react';
import { STATION_KINDS, searchStations } from '../utils/geocoding';

/**
 * Поле «Откуда»/«Куда» формы транспорта с подсказками станций из OpenStreetMap.
 * Для самолёта ищем аэропорты, для поезда — вокзалы, для автобуса — автовокзалы, для парома — порты.
 * Для своего авто, такси и «другого» станций нет — это обычное текстовое поле.
 *
 * Поиск запускается кнопкой или клавишей Enter в поле, а не на каждую букву: так требуют правила Nominatim.
 * Выбирать из списка не обязательно — можно оставить свой текст.
 */
export default function StationInput({ id, value, onChange, transportType, placeholder, searchLabel }) {
    const [results, setResults] = useState(null);
    // idle — ничего не ищем, loading — ждём ответ, error/timeout — сервис не ответил.
    const [status, setStatus] = useState('idle');
    const abortController = useRef(null);
    const canSearch = Boolean(STATION_KINDS[transportType]);

    // Сменили вид транспорта или закрыли форму — старый поиск больше не нужен.
    useEffect(() => {
        setResults(null);
        setStatus('idle');

        return () => abortController.current?.abort();
    }, [transportType]);

    const search = async () => {
        const text = value.trim();

        if (!canSearch || text.length < 2) {
            return;
        }

        abortController.current?.abort();
        abortController.current = new AbortController();
        setResults(null);
        setStatus('loading');

        try {
            setResults(await searchStations(text, transportType, abortController.current.signal));
            setStatus('idle');
        } catch (error) {
            // AbortError — мы сами отменили поиск (новый поиск или закрыли форму), это не ошибка.
            if (error.name !== 'AbortError') {
                setStatus(error.name === 'TimeoutError' ? 'timeout' : 'error');
            }
        }
    };

    const choose = (station) => {
        setResults(null);
        onChange(station.label);
    };

    return (
        <>
            <div className={canSearch ? 'station-input' : ''}>
                <input
                    id={id}
                    type="text"
                    className="form-input form-input--filled"
                    placeholder={placeholder}
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    // Enter в поле запускает поиск станций, а не отправку всей формы.
                    onKeyDown={(event) => {
                        if (canSearch && event.key === 'Enter') {
                            event.preventDefault();
                            search();
                        }
                    }}
                    maxLength={150}
                    autoComplete="off"
                />
                {canSearch && (
                    <button
                        type="button"
                        className="btn btn--outline-dark btn--sm station-input__button"
                        onClick={search}
                        disabled={status === 'loading' || value.trim().length < 2}
                    >
                        {status === 'loading' ? <span className="spinner" aria-label="Ищем…" /> : searchLabel}
                    </button>
                )}
            </div>

            {status === 'loading' && <span className="form-hint station-input__status">Ищем в OpenStreetMap…</span>}
            {status === 'timeout' && <span className="form-hint">Сервис долго не отвечает. Попробуйте ещё раз или впишите название сами.</span>}
            {status === 'error' && <span className="form-hint">Поиск сейчас недоступен — впишите название сами.</span>}

            {status === 'idle' && results && (
                <ul className="map-search__results">
                    {results.length === 0 && (
                        <li className="map-search__empty">Ничего не нашлось — уточните город или оставьте свой текст.</li>
                    )}
                    {results.map((station) => (
                        <li key={station.id}>
                            <button type="button" className="map-search__result" onClick={() => choose(station)}>
                                <span className="map-search__result-name">{station.label}</span>
                                <span className="map-search__result-address">{station.fullAddress}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}
