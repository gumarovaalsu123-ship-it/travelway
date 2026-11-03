
import { useMapEvents } from 'react-leaflet';

export default function MapClickHandler({ setPosition }) {
    useMapEvents({
        async click(e) {
            // Игнорируем клики по элементам управления карты
            const target = e.originalEvent?.target;

            if (
                target?.closest('.map-zoom-controls') ||
                target?.closest('.leaflet-control') ||
                target?.closest('.leaflet-control-container')
            ) {
                return;
            }

            const { lat, lng } = e.latlng;

            setPosition({
                lat,
                lng,
                name: 'Ищем название...',
                category: 'Загрузка...'
            });

            try {
                const response = await fetch(
                    `https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json&accept-language=ru`
                );

                if (!response.ok) {
                    throw new Error('Ошибка получения данных');
                }

                const data = await response.json();

                const placeName =
                    data.name ||
                    data.address?.road ||
                    'Неизвестное место';

                const rawType = data.type || 'Объект';

                const categoryMap = {
                    cafe: 'Кофейня',
                    restaurant: 'Ресторан',
                    fast_food: 'Фастфуд',
                    hotel: 'Отель',
                    museum: 'Музей',
                    park: 'Парк',
                    monument: 'Памятник',
                    viewpoint: 'Смотровая площадка',
                    attraction: 'Достопримечательность'
                };

                const category = categoryMap[rawType] || rawType;

                setPosition({
                    lat,
                    lng,
                    name: placeName,
                    category
                });

            } catch (error) {
                console.error('Ошибка определения места:', error);

                setPosition({
                    lat,
                    lng,
                    name: 'Не удалось определить',
                    category: 'Ошибка сети'
                });
            }
        }
    });

    return null;
}
