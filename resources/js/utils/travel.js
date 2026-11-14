/**
 * Способы добраться до места — ключи как в TripPlace::TravelModes на сервере.
 */
export const TRAVEL_MODES = {
    walk: { icon: '🚶', label: 'Пешком' },
    transit: { icon: '🚌', label: 'Общественный транспорт' },
    car: { icon: '🚗', label: 'Машина' },
    taxi: { icon: '🚕', label: 'Такси / каршеринг' },
};

/**
 * 75 → «1 ч 15 мин», 40 → «40 мин», 120 → «2 ч».
 */
export function formatMinutes(total) {
    const hours = Math.floor(total / 60);
    const minutes = total % 60;

    if (hours === 0) {
        return `${minutes} мин`;
    }

    return minutes > 0 ? `${hours} ч ${minutes} мин` : `${hours} ч`;
}

/**
 * «10:00–11:30», «10:00» или пустая строка, если время не указано.
 */
export function timeLabel(place) {
    if (!place.startTime) {
        return '';
    }

    return place.endTime ? `${place.startTime}–${place.endTime}` : place.startTime;
}
