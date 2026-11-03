import { useMapEvents } from 'react-leaflet';

export default function MapClickHandler({ setPosition }) {
    useMapEvents({
        async click(e) {
            const { lat, lng } = e.latlng;

            // 1. Сразу показываем маркер и статус загрузки в боковой панели
            setPosition({ lat, lng, name: 'Ищем название...', category: 'Загрузка...' });

            try {
                // 2. Делаем запрос к гео-базе OpenStreetMap (просим ответ на русском)
                const response = await fetch(`https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json&accept-language=ru`);
                const data = await response.json();

                // 3. Вытаскиваем название заведения, парка или хотя бы улицу
                const placeName = data.name || data.address?.road || 'Неизвестное место';

                // 4. Переводим базовые категории для красоты (API отдает их на английском)
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

                // 5. Обновляем стейт готовыми данными
                setPosition({ lat, lng, name: placeName, category: category });
            } catch (error) {
                setPosition({ lat, lng, name: 'Не удалось определить', category: 'Ошибка сети' });
            }
        },
    });
    return null;
}
