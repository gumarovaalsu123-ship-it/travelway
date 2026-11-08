export const STATUS_LABELS = {
    current: 'Текущая',
    planned: 'Запланировано',
    completed: 'Завершено',
    draft: 'Черновик',
};

const COVERS = ['/images/trips-first.png', '/images/trips-second.png', '/images/card1.png', '/images/card2.png', '/images/card3.png'];

/**
 * Своих обложек у поездок пока нет, поэтому берём картинку из набора по id — у поездки она всегда одна и та же.
 */
export const coverFor = (trip) => COVERS[trip.id % COVERS.length];
