<?php

namespace Database\Seeders;

use App\Models\PackingList;
use App\Models\Place;
use App\Models\PlaceCategory;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Three complete demo routes of one traveller: places for every day, transport there and back, lodging,
 * things to pack and to do in advance. All three are opened by link and shown in "Примеры маршрутов" on the landing page.
 *
 * Dates are counted from today, so the statuses stay the same whenever the seeder is run:
 * Kazan is planned, Saint Petersburg is completed, Kaliningrad is planned for spring.
 * Photos are not stored here: run php artisan trips:covers and php artisan places:photos afterwards (needs internet).
 *
 * php artisan db:seed --class=DemoTripsSeeder
 */
class DemoTripsSeeder extends Seeder
{
    /**
     * @var array<string, int>
     */
    private array $categoryIds = [];

    public function run(): void
    {
        $this->categoryIds = PlaceCategory::query()->pluck('id', 'slug')->all();

        $author = User::factory()->create([
            'name' => 'Анна Смирнова',
            'email' => 'demo@travelway.test',
            'password' => 'password',
        ]);

        foreach ($this->routes() as $index => $route) {
            $trip = $this->createTrip($author, $route);
            $trip->openPublicAccess();
            // Разное время — чтобы порядок на главной был всегда один и тот же.
            $trip->forceFill(['featured_at' => now()->subMinutes($index)])->save();
        }
    }

    /**
     * @param  array{title: string, destination: string, description: string, start: Carbon, days: array<int, array<int, array{0: string, 1: string, 2: string, 3: float, 4: float, 5: string}>>, from: string, station: string, transport: string, hotel: array{0: string, 1: string, 2: float, 3: float}, tasks: array<int, string>}  $route
     */
    private function createTrip(User $author, array $route): Trip
    {
        $start = $route['start'];
        $end = $start->copy()->addDays(count($route['days']) - 1);

        $trip = Trip::factory()->for($author)->create([
            'title' => $route['title'],
            'destination' => $route['destination'],
            'description' => $route['description'],
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ]);

        foreach ($route['days'] as $dayNumber => $stops) {
            $day = $trip->days()->where('day_number', $dayNumber)->sole();

            foreach ($stops as [$name, $category, $address, $latitude, $longitude, $time]) {
                $place = Place::query()->create([
                    'user_id' => $author->id,
                    'category_id' => $this->categoryIds[$category],
                    'name' => $name,
                    'address' => $address,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                ]);

                $day->addPlace($place, $time)->update(['time_locked' => true]);
            }
        }

        // Самолёт летит в день начала поездки, поезд — ночной, накануне.
        $isPlane = $route['transport'] === 'plane';

        $trip->transports()->createMany([
            [
                'type' => $route['transport'],
                'direction' => 'to',
                'departure_place' => $route['from'],
                'arrival_place' => $route['station'],
                'departure_at' => $isPlane ? $start->copy()->setTime(7, 15) : $start->copy()->subDay()->setTime(22, 10),
                'arrival_at' => $isPlane ? $start->copy()->setTime(8, 30) : $start->copy()->setTime(7, 40),
                'is_booked' => true,
            ],
            [
                'type' => $route['transport'],
                'direction' => 'back',
                'departure_place' => $route['station'],
                'arrival_place' => $route['from'],
                'departure_at' => $end->copy()->setTime(21, 30),
                'arrival_at' => $isPlane ? $end->copy()->setTime(23, 55) : $end->copy()->addDay()->setTime(7, 0),
                'is_booked' => true,
            ],
        ]);

        [$hotelName, $hotelAddress, $hotelLatitude, $hotelLongitude] = $route['hotel'];

        $trip->accommodations()->create([
            'type' => 'hotel',
            'name' => $hotelName,
            'address' => $hotelAddress,
            'latitude' => $hotelLatitude,
            'longitude' => $hotelLongitude,
            'check_in' => $start->copy()->setTime(14, 0),
            'check_out' => $end->copy()->setTime(12, 0),
            'is_booked' => true,
        ]);

        PackingList::addDefaultsTo($trip);

        foreach ($route['tasks'] as $task) {
            $trip->tasks()->create([
                'title' => $task,
                'due_date' => $start->copy()->subWeek()->toDateString(),
                // У прошедшей поездки всё заранее уже сделано.
                'is_done' => $start->isPast(),
            ]);
        }

        return $trip;
    }

    /**
     * Routes: for every day — [name, category slug, address, latitude, longitude, start time].
     *
     * @return array<int, array<string, mixed>>
     */
    private function routes(): array
    {
        return [
            [
                'title' => 'Казань за три дня',
                'destination' => 'Казань',
                'description' => 'Кремль, старые улицы, татарская кухня и закат на набережной Казанки.',
                'start' => today()->addWeeks(3),
                'from' => 'Москва, Казанский вокзал',
                'station' => 'Казань-Пассажирская',
                'transport' => 'train',
                'hotel' => ['Отель на Баумана', 'ул. Баумана, 9', 55.7880, 49.1190],
                'tasks' => ['Купить билеты в Кремль на экскурсию', 'Забронировать столик в «Доме татарской кулинарии»'],
                'days' => [
                    1 => [
                        ['Казанский Кремль', 'attraction', 'Кремлёвская ул., 2', 55.7986, 49.1059, '10:00'],
                        ['Мечеть Кул-Шариф', 'attraction', 'Кремлёвская ул., 13', 55.7983, 49.1050, '12:00'],
                        ['Национальный музей Республики Татарстан', 'museum', 'Кремлёвская ул., 2', 55.7949, 49.1098, '13:30'],
                        ['Дом татарской кулинарии', 'restaurant', 'ул. Баумана, 31', 55.7896, 49.1170, '15:30'],
                        ['Улица Баумана', 'attraction', 'ул. Баумана', 55.7903, 49.1145, '17:00'],
                    ],
                    2 => [
                        ['Старо-Татарская слобода', 'attraction', 'ул. Каюма Насыри', 55.7785, 49.1155, '10:00'],
                        ['Озеро Нижний Кабан', 'park', 'наб. озера Нижний Кабан', 55.7745, 49.1200, '12:30'],
                        ['Дворец земледельцев', 'attraction', 'ул. Федосеевская, 36', 55.8010, 49.1030, '15:00'],
                        ['Центр семьи «Казан»', 'viewpoint', 'ул. Сибгата Хакима, 2', 55.8129, 49.1088, '17:30'],
                    ],
                    3 => [
                        ['Храм всех религий', 'attraction', 'ул. Старо-Аракчинская, 4', 55.8028, 48.9757, '10:30'],
                        ['Кофейня на Кремлёвской', 'cafe', 'Кремлёвская ул., 21', 55.7935, 49.1110, '14:00'],
                    ],
                ],
            ],
            [
                'title' => 'Белые ночи без белых ночей',
                'destination' => 'Санкт-Петербург',
                'description' => 'Осенний Петербург: музеи, соборы, каналы и фонтаны Петергофа.',
                'start' => today()->subMonths(2),
                'from' => 'Москва, Ленинградский вокзал',
                'station' => 'Санкт-Петербург, Московский вокзал',
                'transport' => 'train',
                'hotel' => ['Апарт-отель на Невском', 'Невский пр., 90', 59.9320, 30.3540],
                'tasks' => ['Купить билеты в Эрмитаж онлайн', 'Купить билеты в Нижний парк Петергофа'],
                'days' => [
                    1 => [
                        ['Дворцовая площадь', 'attraction', 'Дворцовая пл.', 59.9390, 30.3158, '10:00'],
                        ['Государственный Эрмитаж', 'museum', 'Дворцовая наб., 34', 59.9398, 30.3146, '10:30'],
                        ['Пышечная на Большой Конюшенной', 'cafe', 'Большая Конюшенная ул., 25', 59.9373, 30.3233, '15:00'],
                        ['Казанский собор', 'attraction', 'Казанская пл., 2', 59.9343, 30.3248, '16:00'],
                    ],
                    2 => [
                        ['Исаакиевский собор', 'attraction', 'Исаакиевская пл., 4', 59.9341, 30.3061, '10:00'],
                        ['Новая Голландия', 'park', 'наб. Адмиралтейского канала, 2', 59.9293, 30.2895, '12:30'],
                        ['Спас на Крови', 'attraction', 'наб. канала Грибоедова, 2Б', 59.9400, 30.3289, '16:00'],
                    ],
                    3 => [
                        ['Петропавловская крепость', 'attraction', 'Заячий остров', 59.9500, 30.3166, '10:00'],
                        ['Летний сад', 'park', 'наб. Кутузова, 2', 59.9445, 30.3360, '14:00'],
                    ],
                    4 => [
                        ['Петергоф, Нижний парк', 'park', 'Разводная ул., 2, Петергоф', 59.8853, 29.9086, '11:00'],
                    ],
                ],
            ],
            [
                'title' => 'Весна на Балтике',
                'destination' => 'Калининград',
                'description' => 'Остров Канта, Рыбная деревня, море в Зеленоградске и Танцующий лес на Куршской косе.',
                'start' => today()->addMonths(5),
                'from' => 'Москва, Шереметьево',
                'station' => 'Калининград, Храброво',
                'transport' => 'plane',
                'hotel' => ['Гостиница у Рыбной деревни', 'ул. Октябрьская, 4', 54.7110, 20.5150],
                'tasks' => ['Оплатить экосбор на Куршскую косу'],
                'days' => [
                    1 => [
                        ['Кафедральный собор', 'attraction', 'ул. Канта, 1', 54.7064, 20.5115, '11:00'],
                        ['Рыбная деревня', 'attraction', 'ул. Октябрьская', 54.7067, 20.5160, '13:00'],
                        ['Музей Мирового океана', 'museum', 'наб. Петра Великого, 1', 54.7068, 20.4995, '15:00'],
                    ],
                    2 => [
                        ['Королевские ворота', 'attraction', 'ул. Фрунзе, 112', 54.7206, 20.5338, '10:00'],
                        ['Улица Кутузова (Амалиенау)', 'attraction', 'ул. Кутузова', 54.7210, 20.4700, '13:00'],
                        ['Бранденбургские ворота', 'attraction', 'ул. Багратиона, 2', 54.6967, 20.4980, '16:00'],
                    ],
                    3 => [
                        ['Танцующий лес', 'park', 'Куршская коса, 37-й км', 55.1806, 20.8615, '10:30'],
                        ['Променад Зеленоградска', 'viewpoint', 'Зеленоградск, набережная', 54.9600, 20.4800, '15:00'],
                    ],
                ],
            ],
        ];
    }
}
