import React from 'react';

/** Значок-заглушка вместо фото: у большинства мест (кафе, дома) статьи в Википедии нет. */
const CATEGORY_ICONS = {
    cafe: '☕',
    restaurant: '🍽️',
    museum: '🏛️',
    attraction: '🏰',
    viewpoint: '🔭',
    park: '🌳',
    hotel: '🏨',
    shop: '🛍️',
    transport: '🚉',
    other: '📍',
};

/**
 * Фото места из Википедии или цветная заглушка с иконкой категории.
 * size: 'cover' — обложка карточки, 'popup' — попап на карте, 'thumb' — миниатюра в списке.
 */
export function PlacePhoto({ image, categorySlug, alt = '', size = 'cover' }) {
    if (image) {
        return <img className={`place-photo place-photo--${size}`} src={image} alt={alt} loading="lazy" />;
    }

    return (
        <div className={`place-photo place-photo--${size} place-photo--empty`} aria-hidden="true">
            {CATEGORY_ICONS[categorySlug] ?? CATEGORY_ICONS.other}
        </div>
    );
}

/** Внешняя ссылка: открывается в новой вкладке и не получает доступ к нашей странице. */
export function ExternalLink({ href, children, className = '' }) {
    return (
        <a href={href} className={className} target="_blank" rel="noopener noreferrer">
            {children}
        </a>
    );
}

/** Подпись об источнике — обязательна по лицензии CC BY-SA, если показываем фото или текст из Википедии. */
export function WikipediaCredit({ url }) {
    if (!url) {
        return null;
    }

    return (
        <p className="place-credit">
            Источник: <ExternalLink href={url}>Википедия</ExternalLink>
        </p>
    );
}

/** Часы работы, телефон и сайт из OpenStreetMap. Часы показываем как есть: «Mo-Fr 09:00-18:00». */
export function PlaceContacts({ openingHours, phone, website }) {
    if (!openingHours && !phone && !website) {
        return null;
    }

    return (
        <ul className="place-contacts">
            {openingHours && <li><span className="place-contacts__label">Часы:</span> {openingHours}</li>}
            {phone && <li><span className="place-contacts__label">Телефон:</span> <a href={`tel:${phone.replace(/[^\d+]/g, '')}`}>{phone}</a></li>}
            {website && <li><ExternalLink href={website}>Сайт</ExternalLink></li>}
        </ul>
    );
}
