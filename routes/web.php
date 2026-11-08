<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\FavoritePlaceController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\TripAccommodationController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\TripPlaceController;
use App\Http\Controllers\TripTransportController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

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
     * «Обзор» — это сама страница поездки /my-trips/{trip} (trips.show).
     * Пока вкладка не готова, она открывает заглушку «Скоро появится» (TripController@comingSoon).
     * Чтобы сделать вкладку: замените её строку здесь на свой контроллер (имя маршрута оставьте),
     * а страницу соберите в Pages/Trips/<Вкладка>.jsx внутри Components/TripLayout.jsx.
     */
    Route::get('/my-trips/{trip}/plan', [TripController::class, 'comingSoon'])->defaults('tab', 'plan')->name('trips.plan');

    Route::get('/my-trips/{trip}/map', [TripController::class, 'comingSoon'])->defaults('tab', 'map')->name('trips.map');

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

    Route::get('/my-trips/{trip}/packing', [TripController::class, 'comingSoon'])->defaults('tab', 'packing')->name('trips.packing');

    Route::post('/places/{place}/favorite', [FavoritePlaceController::class, 'store'])->name('places.favorite');
    Route::delete('/places/{place}/favorite', [FavoritePlaceController::class, 'destroy'])->name('places.unfavorite');

    Route::post('/places/{place}/trip-places', [TripPlaceController::class, 'store'])->name('trip-places.store');
    Route::delete('/trip-places/{tripPlace}', [TripPlaceController::class, 'destroy'])->name('trip-places.destroy');

    Route::get('/favorites', [FavoritePlaceController::class, 'index'])->name('favorites.index');
});
