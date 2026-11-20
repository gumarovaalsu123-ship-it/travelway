import React, { useRef, useState } from 'react';
import { formatDistance, isTimeoutError, searchPlaces } from '../../../utils/geocoding';
import { plural } from '../../../utils/format';
import { SEARCH_CATEGORIES, categoryForQuery } from '../../../utils/searchCategories';
import Loader, { Spinner } from '../../Loader';
import MapMenuButton from './MapMenuButton';

// В списке под строкой поиска — только ближайшие; остальные видны маркерами на карте.
const CATEGORY_LIST_SIZE = 8;

/**
 * Строка поиска на карте. Два режима:
 * - по названию или адресу («Казанский кремль», «Баумана 5») — Nominatim, по кнопке или Enter;
 * - по категории («отели» или пункт «Жильё» в меню-воронке справа от строки) — родитель ищет места в видимой части карты (useCategorySearch)
 *   и передаёт сюда categoryResult, а сам рисует маркеры.
 */
export default function MapSearch({ map, onSelect, category, onCategoryChange, categoryResult }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState(null);
    // idle — ждём ввода или показываем результаты, loading — идёт запрос, error / timeout — запрос не удался.
    const [status, setStatus] = useState('idle');
    // Список найденного можно свернуть до одной строки-заголовка, чтобы он не закрывал карту (маркеры остаются).
    // Сворачивается сам после выбора места; разворачивается кнопкой в заголовке, новым поиском или кликом в строку.
    const [isListOpen, setIsListOpen] = useState(true);
    const abortController = useRef(null);

    const startCategory = (nextCategory) => {
        abortController.current?.abort();
        setResults(null);
        setStatus('idle');
        setIsListOpen(true);
        onCategoryChange(nextCategory);
    };

    const submit = async (event) => {
        event.preventDefault();

        const text = query.trim();
        if (text.length < 2) {
            return;
        }

        const queryCategory = categoryForQuery(text);
        if (queryCategory) {
            startCategory(queryCategory);

            return;
        }

        onCategoryChange(null);
        abortController.current?.abort();
        abortController.current = new AbortController();
        setStatus('loading');
        setIsListOpen(true);

        try {
            setResults(await searchPlaces(text, map, abortController.current.signal));
            setStatus('idle');
        } catch (error) {
            if (error.name !== 'AbortError') {
                setResults([]);
                setStatus(isTimeoutError(error) ? 'timeout' : 'error');
            }
        }
    };

    /** Пункт меню-воронки: включает категорию, а повторное нажатие на активную — выключает. */
    const toggleCategory = (set) => {
        const chip = SEARCH_CATEGORIES.find((item) => item.set === set);

        if (category?.set === chip.set) {
            setQuery('');
            onCategoryChange(null);

            return;
        }

        setQuery(chip.label);
        startCategory(chip);
    };

    const choose = (result) => {
        setIsListOpen(false);
        onSelect(result);
    };

    const nearestCategoryPlaces = categoryResult.places.slice(0, CATEGORY_LIST_SIZE);
    const placesCount = (count) => `${count} ${plural(count, ['место', 'места', 'мест'])}`;

    // Заголовок панели: что сейчас показано на карте. null — панели нет (ничего не ищем).
    let panelTitle = null;
    if (category) {
        panelTitle = categoryResult.status === 'ready' || categoryResult.places.length > 0
            ? `${category.label} · ${placesCount(categoryResult.places.length)}`
            : category.label;
    } else if (status === 'idle' && results) {
        panelTitle = `Найдено: ${placesCount(results.length)}`;
    }

    return (
        <form className="map-search" onSubmit={submit} role="search">
            <div className="map-search__row">
                <div className="map-search__field">
                    <span className="map-search__icon">
                        {/* Пока идёт запрос, вместо лупы крутится индикатор. */}
                        {status === 'loading' || categoryResult.status === 'loading' ? <Spinner /> : (
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <circle cx="11" cy="11" r="7" />
                            <line x1="21" y1="21" x2="16.65" y2="16.65" />
                        </svg>
                        )}
                    </span>
                    <input
                        type="search"
                        className="map-search__input"
                        placeholder="Место, адрес или «отели», «кафе»"
                        value={query}
                        onFocus={() => setIsListOpen(true)}
                        onChange={(event) => {
                            setQuery(event.target.value);
                            if (!event.target.value) {
                                setResults(null);
                                onCategoryChange(null);
                            }
                        }}
                        aria-label="Поиск места"
                    />
                </div>
                <MapMenuButton
                    className="map-search__categories"
                    label={category ? `Категория: ${category.label}` : 'Искать по категории'}
                    title="Что искать рядом"
                    isMarked={Boolean(category)}
                    items={SEARCH_CATEGORIES.map((item) => ({ value: item.set, label: item.label }))}
                    selected={category?.set ?? null}
                    onSelect={toggleCategory}
                    icon={(
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3" />
                        </svg>
                    )}
                />
            </div>

            {status === 'loading' && (
                <div className="map-search__results">
                    <div className="map-search__empty"><Loader text="Ищем место…" /></div>
                </div>
            )}

            {(status === 'error' || status === 'timeout') && (
                <div className="map-search__results">
                    <div className="map-search__empty">
                        {status === 'timeout'
                            ? 'Поиск не ответил за 15 секунд. Попробуйте ещё раз чуть позже.'
                            : 'Поиск сейчас недоступен. Проверьте интернет или попробуйте позже.'}
                    </div>
                </div>
            )}

            {panelTitle && (
                <div className="map-search__panel">
                    <div className="map-search__panel-head">
                        <span className="map-search__panel-title">{panelTitle}</span>
                        <button
                            type="button"
                            className="map-search__panel-toggle"
                            aria-expanded={isListOpen}
                            onClick={() => setIsListOpen((open) => !open)}
                        >
                            {isListOpen ? 'Свернуть' : 'Показать'}
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                <polyline points={isListOpen ? '18 15 12 9 6 15' : '6 9 12 15 18 9'} />
                            </svg>
                        </button>
                    </div>

                    {isListOpen && (
                        <>
                        {!category && results && (
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

                        {category && (
                            <ul className="map-search__results">
                                {categoryResult.status === 'loading' && nearestCategoryPlaces.length === 0 && (
                                    <li className="map-search__empty"><Loader text="Ищем на карте…" /></li>
                                )}
                                {categoryResult.status === 'zoomIn' && (
                                    <li className="map-search__empty">Приблизьте карту — так места покажутся на ней.</li>
                                )}
                                {categoryResult.status === 'error' && (
                                    <li className="map-search__empty">{categoryResult.message}</li>
                                )}
                                {categoryResult.status === 'ready' && nearestCategoryPlaces.length === 0 && (
                                    <li className="map-search__empty">Здесь ничего не нашлось — сдвиньте карту.</li>
                                )}
                                {nearestCategoryPlaces.map((place) => (
                                    <li key={place.id}>
                                        <button type="button" className="map-search__result" onClick={() => choose(place)}>
                                            <span className="map-search__result-name">{place.name}</span>
                                            <span className="map-search__result-address">
                                                <strong>{formatDistance(place.distanceKm)} · </strong>
                                                {[place.kindLabel, place.address].filter(Boolean).join(', ')}
                                            </span>
                                        </button>
                                    </li>
                                ))}
                                {categoryResult.places.length > CATEGORY_LIST_SIZE && (
                                    <li className="map-search__empty">Ещё {categoryResult.places.length - CATEGORY_LIST_SIZE} — на карте</li>
                                )}
                            </ul>
                        )}
                        </>
                    )}
                </div>
            )}
        </form>
    );
}
