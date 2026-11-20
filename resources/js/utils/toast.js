// Имя события, которое слушает компонент Toast.
export const TOAST_EVENT = 'travelway:toast';

/**
 * Показывает всплывающее уведомление прямо из браузера, без запроса к серверу —
 * например «Ссылка скопирована» или ошибку геолокации. Серверные уведомления по-прежнему идут через flash.
 *
 * @param {string} text
 */
export function showToast(text) {
    window.dispatchEvent(new CustomEvent(TOAST_EVENT, { detail: text }));
}
