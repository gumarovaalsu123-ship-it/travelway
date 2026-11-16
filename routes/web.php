<?php

use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\FavoritePlaceController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\PackingController;
use App\Http\Controllers\PackingItemController;
use App\Http\Controllers\PackingListController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicTripController;
use App\Http\Controllers\TripAccommodationController;
use App\Http\Controllers\TripAdvanceController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\TripPlaceController;
use App\Http\Controllers\TripPlanController;
use App\Http\Controllers\TripShareController;
use App\Http\Controllers\TripTransportController;
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

// Публичная ссылка на поездку: открывается без входа, ищется по секретному токену, а не по id.
Route::get('/t/{token}', [PublicTripController::class, 'show'])->name('trips.public');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/interactive-map', [MapController::class, 'index'])->name('map.index');
    Route::post('/interactive-map/places', [MapController::class, 'store'])->name('map.places.store');

    Route::resource('my-trips', TripController::class)
        ->parameters(['my-trips' => 'trip'])
        ->names('trips');

    /*
     * Вкладки страницы поездки. Каждая вкладка — свой адрес: /my-trips/{trip}/<вкладка>,
     * «Подготовка» — это сама страница поездки /my-trips/{trip} (trips.show).
     * Каждая вкладка — свой контроллер и страница Pages/Trips/<Вкладка>.jsx внутри Components/TripLayout.jsx.
     */
    Route::get('/my-trips/{trip}/plan', [TripPlanController::class, 'index'])->name('trips.plan');
    // План по дням. scopeBindings: {day} ищется среди дней этой поездки, {tripPlace} — среди её мест, иначе 404.
    // Убрать место из плана — существующий trip-places.destroy (ниже).
    Route::scopeBindings()->prefix('/my-trips/{trip}')->name('trips.')->controller(TripPlanController::class)->group(function () {
        Route::post('/days/{day}/schedule', 'calculateSchedule')->name('days.schedule');
        // Место, найденное подсказками Overpass («Что по пути?», «Пора пообедать?»), — сразу в нужное место дня.
        Route::post('/days/{day}/places', 'storePlace')->name('days.places.store');
        Route::patch('/trip-places/{tripPlace}', 'update')->name('trip-places.update');
        Route::patch('/trip-places/{tripPlace}/move', 'move')->name('trip-places.move');
        Route::patch('/trip-places/{tripPlace}/day', 'changeDay')->name('trip-places.change-day');
    });

    // Отдельной вкладки «Карта» больше нет: карта дня теперь на вкладке «План». Старый адрес оставили,
    // чтобы не сломались сохранённые ссылки, — он ведёт на план с тем же днём (?day=N) и жильём (?lodging=id).
    Route::get('/my-trips/{trip}/map', fn (Request $request, Trip $trip) => to_route('trips.plan', [
        'trip' => $trip,
        ...$request->only(['day', 'lodging']),
    ]))->name('trips.map');

    // «Настройки»: открыть и закрыть доступ по ссылке.
    Route::post('/my-trips/{trip}/share', [TripShareController::class, 'store'])->name('trips.share.store');
    Route::delete('/my-trips/{trip}/share', [TripShareController::class, 'destroy'])->name('trips.share.destroy');

    Route::get('/my-trips/{trip}/transport', [TripTransportController::class, 'index'])->name('trips.transport');
    // scopeBindings: {transport} ищется только среди записей этой поездки ($trip->transports()), иначе 404.
    Route::scopeBindings()->group(function () {
        Route::post('/my-trips/{trip}/transport', [TripTransportController::class, 'store'])->name('trips.transport.store');
        Route::put('/my-trips/{trip}/transport/{transport}', [TripTransportController::class, 'update'])->name('trips.transport.update');
        Route::delete('/my-trips/{trip}/transport/{transport}', [TripTransportController::class, 'destroy'])->name('trips.transport.destroy');
    });

    Route::get('/my-trips/{trip}/accommodation', [TripAccommodationController::class, 'index'])->name('trips.accommodation');
    // scopeBindings: {accommodation} ищется только среди жилья этой {trip}, иначе 404.
    Route::scopeBindings()->controller(TripAccommodationController::class)->group(function () {
        Route::post('/my-trips/{trip}/accommodation', 'store')->name('trips.accommodation.store');
        Route::put('/my-trips/{trip}/accommodation/{accommodation}', 'update')->name('trips.accommodation.update');
        Route::delete('/my-trips/{trip}/accommodation/{accommodation}', 'destroy')->name('trips.accommodation.destroy');
    });

    Route::get('/my-trips/{trip}/packing', [PackingController::class, 'index'])->name('trips.packing');

    // Список вещей: группы и вещи. scopeBindings — группа/вещь ищется только среди записей этой поездки.
    Route::scopeBindings()->prefix('/my-trips/{trip}')->name('trips.')->group(function () {
        Route::post('/packing-lists/defaults', [PackingController::class, 'storeDefaults'])->name('packing-lists.defaults');
        Route::post('/packing-lists/hints', [PackingController::class, 'storeHint'])->name('packing-lists.hints');
        Route::post('/packing-lists', [PackingListController::class, 'store'])->name('packing-lists.store');
        Route::patch('/packing-lists/{packingList}', [PackingListController::class, 'update'])->name('packing-lists.update');
        Route::delete('/packing-lists/{packingList}', [PackingListController::class, 'destroy'])->name('packing-lists.destroy');
        Route::post('/packing-lists/{packingList}/items', [PackingItemController::class, 'store'])->name('packing-items.store');
        Route::patch('/packing-items/{packingItem}', [PackingItemController::class, 'update'])->name('packing-items.update');
        Route::patch('/packing-items/{packingItem}/toggle', [PackingItemController::class, 'toggle'])->name('packing-items.toggle');
        Route::delete('/packing-items/{packingItem}', [PackingItemController::class, 'destroy'])->name('packing-items.destroy');
    });

    Route::post('/places/{place}/favorite', [FavoritePlaceController::class, 'store'])->name('places.favorite');
    Route::delete('/places/{place}/favorite', [FavoritePlaceController::class, 'destroy'])->name('places.unfavorite');

    Route::post('/places/{place}/trip-places', [TripPlaceController::class, 'store'])->name('trip-places.store');
    Route::delete('/trip-places/{tripPlace}', [TripPlaceController::class, 'destroy'])->name('trip-places.destroy');

    Route::get('/favorites', [FavoritePlaceController::class, 'index'])->name('favorites.index');

    // Профиль: просмотр, редактирование, аватар и пароль — всегда свой (пользователь из сессии).
    Route::prefix('/profile')->name('profile.')->controller(ProfileController::class)->group(function () {
        Route::get('/', 'show')->name('show');
        Route::get('/edit', 'edit')->name('edit');
        Route::put('/', 'update')->name('update');
        Route::post('/avatar', 'updateAvatar')->name('avatar.update');
        Route::delete('/avatar', 'destroyAvatar')->name('avatar.destroy');
        Route::put('/password', 'updatePassword')->name('password.update');
    });

    // «Заранее»: свои дела и отметки «куплено/забронировано» для автоматических дел.
    // scopeBindings — дело, билет, жильё или место ищется только среди записей этой поездки, иначе 404.
    Route::scopeBindings()->prefix('/my-trips/{trip}')->name('trips.')->controller(TripAdvanceController::class)->group(function () {
        Route::get('/advance', 'index')->name('advance');
        Route::post('/tasks', 'store')->name('tasks.store');
        Route::put('/tasks/{task}', 'update')->name('tasks.update');
        Route::patch('/tasks/{task}/toggle', 'toggle')->name('tasks.toggle');
        Route::delete('/tasks/{task}', 'destroy')->name('tasks.destroy');
        Route::patch('/transport/{transport}/booked', 'toggleTransport')->name('transport.booked');
        Route::patch('/accommodation/{accommodation}/booked', 'toggleAccommodation')->name('accommodation.booked');
        Route::patch('/trip-places/{tripPlace}/booking', 'updatePlaceBooking')->name('trip-places.booking');
    });
});

// Админ-панель: гостя 'auth' отправит на вход, обычному пользователю 'admin' ответит 403.
Route::middleware(['auth', 'admin'])->prefix('/admin')->name('admin.')->group(function () {
    Route::get('/', AdminDashboardController::class)->name('dashboard');

    Route::controller(AdminUserController::class)->prefix('/users')->name('users.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::patch('/{user}/block', 'block')->name('block');
        Route::patch('/{user}/unblock', 'unblock')->name('unblock');
        Route::patch('/{user}/role', 'updateRole')->name('role');
        Route::delete('/{user}', 'destroy')->name('destroy');
    });
});
