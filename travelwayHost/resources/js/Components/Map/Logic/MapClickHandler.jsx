import { useRef } from 'react';
import { useMapEvents } from 'react-leaflet';

/**
 * Сообщает координаты клика по карте. Клик, которым пользователь просто закрыл попап,
 * не считается выбором новой точки.
 */
export default function MapClickHandler({ onSelect }) {
    const popupJustClosed = useRef(false);

    useMapEvents({
        popupclose() {
            popupJustClosed.current = true;
            setTimeout(() => {
                popupJustClosed.current = false;
            }, 0);
        },
        click(event) {
            const target = event.originalEvent?.target;

            if (popupJustClosed.current || target?.closest('.leaflet-control, .map-zoom-controls')) {
                return;
            }

            onSelect(event.latlng);
        },
    });

    return null;
}
