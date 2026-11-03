<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\MapController;

Route::get('/interactive-map', [MapController::class, 'index'])->name('map.index');

Route::get('/', function () {
    return redirect('/interactive-map');
});

Route::get('/interactive-map', function () {
    // В будущем здесь данные будут браться из БД,
    // пока передаем тестовые маркеры напрямую во фронтенд
    return Inertia::render('InteractiveMap', [
        'initialMarkers' => [
            ['id' => 1, 'title' => 'Кафе A Brasileira', 'lat' => 38.7107, 'lng' => -9.1422],
            ['id' => 2, 'title' => 'Замок Святого Георгия', 'lat' => 38.7139, 'lng' => -9.1335]
        ]
    ]);
});
