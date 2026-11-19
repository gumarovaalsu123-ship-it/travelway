import React from 'react';

/**
 * Контуры иконок способов передвижения — в том же стиле, что иконки админки (линии 24×24, stroke).
 */
const paths = {
    walk: (
        <>
            <circle cx="13" cy="4" r="2" />
            <path d="M10 22l2-7 3 3v4" />
            <path d="M7 12l3-4 4 1 2 4 3 1" />
            <path d="M10 8l-1 6" />
        </>
    ),
    transit: (
        <>
            <rect x="4" y="3" width="16" height="15" rx="2" />
            <path d="M4 11h16" />
            <path d="M8 3v8M16 3v8" />
            <circle cx="8" cy="15" r="0.5" />
            <circle cx="16" cy="15" r="0.5" />
            <path d="M6 18v3M18 18v3" />
        </>
    ),
    car: (
        <>
            <path d="M5 17H3v-5l2-5h14l2 5v5h-2" />
            <path d="M3 12h18" />
            <circle cx="7.5" cy="17" r="2" />
            <circle cx="16.5" cy="17" r="2" />
            <path d="M9.5 17h5" />
        </>
    ),
    taxi: (
        <>
            <path d="M9 3h6l1 4" />
            <path d="M5 17H3v-5l2-5h14l2 5v5h-2" />
            <path d="M3 12h18" />
            <circle cx="7.5" cy="17" r="2" />
            <circle cx="16.5" cy="17" r="2" />
            <path d="M9.5 17h5" />
        </>
    ),
};

/**
 * Иконка способа передвижения (walk, transit, car, taxi) вместо смайлика.
 * Цвет берётся из текста вокруг (currentColor).
 */
export default function TravelModeIcon({ mode, size = 16 }) {
    return (
        <svg
            className="travel-mode-icon"
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            {paths[mode] ?? paths.walk}
        </svg>
    );
}
