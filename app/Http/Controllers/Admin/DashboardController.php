<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Place;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Main page of the admin panel: totals with growth for the last week and new users by month.
 */
class DashboardController extends Controller
{
    /**
     * How many recent months the "Рост пользователей" chart shows.
     */
    protected const ChartMonths = 6;

    public function __invoke(): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'users' => $this->countWithWeek(User::class),
                'trips' => $this->countWithWeek(Trip::class),
                'places' => $this->countWithWeek(Place::class),
            ],
            'blockedCount' => User::query()->whereNotNull('blocked_at')->count(),
            'chart' => $this->newUsersByMonth(),
        ]);
    }

    /**
     * Total number of records and how many of them were created in the last 7 days.
     *
     * @param  class-string<Model>  $model
     * @return array{total: int, week: int}
     */
    protected function countWithWeek(string $model): array
    {
        return [
            'total' => $model::query()->count(),
            'week' => $model::query()->where('created_at', '>=', now()->subWeek())->count(),
        ];
    }

    /**
     * New users in each of the last months, from the oldest to the current one.
     * Группируем уже в PHP: так одинаково работает и в MySQL, и в SQLite у тестов.
     *
     * @return list<array{month: string, count: int}>
     */
    protected function newUsersByMonth(): array
    {
        $firstMonth = now()->startOfMonth()->subMonths(self::ChartMonths - 1);

        $countsByMonth = User::query()
            ->where('created_at', '>=', $firstMonth)
            ->pluck('created_at')
            ->countBy(fn (Carbon $createdAt) => $createdAt->format('Y-m'));

        return collect(range(0, self::ChartMonths - 1))
            ->map(function (int $offset) use ($firstMonth, $countsByMonth) {
                $month = $firstMonth->copy()->addMonths($offset);

                return [
                    // «ноя», «дек» — короткое название месяца по-русски.
                    'month' => mb_substr($month->locale('ru')->translatedFormat('M'), 0, 3),
                    'count' => $countsByMonth->get($month->format('Y-m'), 0),
                ];
            })
            ->all();
    }
}
