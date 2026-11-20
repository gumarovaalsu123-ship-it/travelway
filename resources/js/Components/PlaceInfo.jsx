import React from 'react';
import { categorySvg } from '../utils/mapIcons';

/** Размер иконки-заглушки в пикселях для каждого вида фото. */
const ICON_SIZES = { cover: 44, popup: 36, thumb: 22 };

/**
 * Фото места из Википедии или заглушка с иконкой категории: у большинства мест (кафе, дома) статьи в Википедии нет.
 * Иконки те же, что у маркеров на карте (utils/mapIcons.js): контурные SVG выглядят одинаково в любой системе, в отличие от эмодзи.
 * size: 'cover' — обложка карточки, 'popup' — попап на карте, 'thumb' — миниатюра в списке.
 */
export function PlacePhoto({ image, categorySlug, alt = '', size = 'cover' }) {
    if (image) {
        return <img className={`place-photo place-photo--${size}`} src={image} alt={alt} loading="lazy" />;
    }

    return (
        <div
            className={`place-photo place-photo--${size} place-photo--empty`}
            aria-hidden="true"
            // SVG собран из наших же констант, пользовательского текста в нём нет.
            dangerouslySetInnerHTML={{ __html: categorySvg(categorySlug, ICON_SIZES[size] ?? ICON_SIZES.cover) }}
        />
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
