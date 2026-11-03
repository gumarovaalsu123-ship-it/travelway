import React from 'react';

export default function CustomZoomControl() {
    return (
        <div className="map-zoom-controls" style={{ position: 'absolute', zIndex: 1000, top: '20px', left: '20px' }}>
            <button className="zoom-btn">+</button>
            <button className="zoom-btn">−</button>
            <button className="zoom-btn">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <circle cx="12" cy="12" r="3"></circle>
                    <line x1="12" y1="2" x2="12" y2="4"></line>
                    <line x1="12" y1="20" x2="12" y2="22"></line>
                    <line x1="20" y1="12" x2="22" y2="12"></line>
                    <line x1="2" y1="12" x2="4" y2="12"></line>
                </svg>
            </button>
        </div>
    );
}
