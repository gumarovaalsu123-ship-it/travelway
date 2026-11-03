import React, { useState, useEffect } from 'react';

export default function PlaceFormOverlay({ position, onClose }) {
    // Локальные стейты для инпутов
    const [placeName, setPlaceName] = useState('');
    const [placeCategory, setPlaceCategory] = useState('');

    // Синхронизируем данные от карты с инпутами в форме
    useEffect(() => {
        if (position) {
            setPlaceName(position.name || '');
            setPlaceCategory(position.category || '');
        }
    }, [position]);

    if (!position) return null;

    return (
        <aside className="map-overlay-form" style={{ position: 'absolute', zIndex: 1000, top: '20px', right: '20px' }}>
            <div className="map-overlay__header">
                <h2 className="map-overlay__title">Добавить место</h2>
                <button className="btn-close" aria-label="Закрыть" onClick={onClose}>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>

            <div className="draft-status-box">
                <span className="draft-status__title">Координаты:</span>
                <p className="draft-status__desc">Ш: {position.lat.toFixed(4)}, Д: {position.lng.toFixed(4)}</p>
            </div>

            <form className="map-overlay__form">
                <div className="form-group">
                    <label className="form-label">Название</label>
                    <input
                        type="text"
                        className="form-input form-input--filled"
                        value={placeName}
                        onChange={(e) => setPlaceName(e.target.value)}
                        placeholder="Введите название..."
                    />
                </div>

                <div className="form-group">
                    <label className="form-label">Категория</label>
                    <div className="form-input-inner">
                        <input
                            type="text"
                            className="form-input form-input--filled"
                            value={placeCategory}
                            readOnly
                        />
                        <span className="form-icon-suffix">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </span>
                    </div>
                </div>

                <div className="form-group">
                    <label className="form-label">Время (необязательно)</label>
                    <input type="text" className="form-input form-input--filled" placeholder="Укажите время" />
                </div>

                <div className="map-overlay__actions mt-16">
                    <button type="submit" className="btn btn--solid-dark btn--full">Сохранить место</button>
                    <button type="button" className="btn btn--outline-dark btn--full" onClick={onClose}>Отмена</button>
                </div>
            </form>
        </aside>
    );
}
