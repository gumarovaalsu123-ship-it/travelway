<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\FavoritePlaceController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\TripPlaceController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/interactive-map');

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

    Route::post('/places/{place}/favorite', [FavoritePlaceController::class, 'store'])->name('places.favorite');
    Route::delete('/places/{place}/favorite', [FavoritePlaceController::class, 'destroy'])->name('places.unfavorite');

    Route::post('/places/{place}/trip-places', [TripPlaceController::class, 'store'])->name('trip-places.store');
    Route::delete('/trip-places/{tripPlace}', [TripPlaceController::class, 'destroy'])->name('trip-places.destroy');
});
