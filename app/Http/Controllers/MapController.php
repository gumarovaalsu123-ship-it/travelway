<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class MapController extends Controller
{
    public function index()
    {
        // Отдаем React-компонент InteractiveMap
        return Inertia::render('InteractiveMap');
    }
}
