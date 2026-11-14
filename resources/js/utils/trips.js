export const STATUS_LABELS = {
    current: 'Текущая',
    planned: 'Запланировано',
    completed: 'Завершено',
    draft: 'Черновик',
};

const COVERS = ['/images/trips-first.png', '/images/trips-second.png', '/images/card1.png', '/images/card2.png', '/images/card3.png'];

/**
 * Обложка поездки: фото города из Википедии (сервер ищет его при создании, см. app/Services/CityCover.php).
 * Если фото не нашлось — картинка-заглушка из набора по id, у поездки она всегда одна и та же.
 */
export const coverFor = (trip) => trip.coverImage ?? COVERS[trip.id % COVERS.length];
