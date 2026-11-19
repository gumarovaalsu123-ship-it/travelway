const dayMonthFormatter = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long' });
const shortDateFormatter = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short' });

const toDate = (isoDate) => new Date(`${isoDate}T00:00:00`);

/**
 * «День 1 · 15 июня», а для поездки без дат — просто «День 1».
 */
export function formatDayLabel(dayNumber, isoDate) {
    return isoDate ? `День ${dayNumber} · ${dayMonthFormatter.format(toDate(isoDate))}` : `День ${dayNumber}`;
}

/**
 * «15 мар — 22 мар 2026»
 */
export function formatDateRange(startDate, endDate) {
    if (!startDate) {
        return 'Даты не выбраны';
    }

    const end = toDate(endDate);

    return `${shortDateFormatter.format(toDate(startDate)).replace('.', '')} — ${shortDateFormatter.format(end).replace('.', '')} ${end.getFullYear()}`;
}

/**
 * Склонение по числу: plural(5, ['день', 'дня', 'дней']) → «дней».
 */
export function plural(count, [one, few, many]) {
    const lastTwo = count % 100;
    const last = count % 10;

    if (lastTwo >= 11 && lastTwo <= 14) {
        return many;
    }

    if (last === 1) {
        return one;
    }

    return last >= 2 && last <= 4 ? few : many;
}

/**
 * «6 дней / 5 ночей»
 */
export function formatDuration(daysCount) {
    const nights = daysCount - 1;
    const days = `${daysCount} ${plural(daysCount, ['день', 'дня', 'дней'])}`;

    return nights > 0 ? `${days} / ${nights} ${plural(nights, ['ночь', 'ночи', 'ночей'])}` : days;
}
