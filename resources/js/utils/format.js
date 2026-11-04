const dayMonthFormatter = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long' });

/**
 * «День 1 · 15 июня»
 */
export function formatDayLabel(dayNumber, isoDate) {
    return `День ${dayNumber} · ${dayMonthFormatter.format(new Date(`${isoDate}T00:00:00`))}`;
}
