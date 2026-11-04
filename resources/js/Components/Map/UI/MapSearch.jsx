import React, { useRef, useState } from 'react';
import { formatDistance, searchPlaces } from '../../../utils/geocoding';

export default function MapSearch({ map, onSelect }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState(null);
    const [status, setStatus] = useState('idle');
    const abortController = useRef(null);

    const submit = async (event) => {
        event.preventDefault();

        const text = query.trim();
        if (text.length < 2) {
            return;
        }

        abortController.current?.abort();
        abortController.current = new AbortController();
        setStatus('loading');

        try {
            setResults(await searchPlaces(text, map, abortController.current.signal));
            setStatus('idle');
        } catch (error) {
            if (error.name !== 'AbortError') {
                setResults([]);
                setStatus('error');
            }
        }
    };

    const choose = (result) => {
        setResults(null);
        onSelect(result);
    };

    return (
        <form className="map-search" onSubmit={submit} role="search">
            <div className="map-search__field">
                <span className="map-search__icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                        <circle cx="11" cy="11" r="7" />
                        <line x1="21" y1="21" x2="16.65" y2="16.65" />
                    </svg>
                </span>
                <input
                    type="search"
                    className="map-search__input"
                    placeholder="Найти место или адрес"
                    value={query}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        if (!event.target.value) {
                            setResults(null);
                        }
                    }}
                    aria-label="Поиск места"
                />
            </div>

            {status === 'loading' && <div className="map-search__results"><div className="map-search__empty">Ищем…</div></div>}

            {status === 'error' && (
                <div className="map-search__results">
                    <div className="map-search__empty">Поиск сейчас недоступен. Попробуйте позже.</div>
                </div>
            )}

            {status === 'idle' && results && (
                <ul className="map-search__results">
                    {results.length === 0 && <li className="map-search__empty">Ничего не нашлось</li>}
                    {results.map((result) => (
                        <li key={result.id}>
                            <button type="button" className="map-search__result" onClick={() => choose(result)}>
                                <span className="map-search__result-name">{result.name}</span>
                                <span className="map-search__result-address">
                                    {result.distanceKm !== null && <strong>{formatDistance(result.distanceKm)} · </strong>}
                                    {result.fullAddress}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </form>
    );
}
