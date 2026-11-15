<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Trip covers from Wikipedia (App\Services\CityCover). The real Wikipedia is never called:
 * Http::fake answers instead of it, preventStrayRequests fails the test on any other request.
 */
class TripCoverTest extends TestCase
{
    use RefreshDatabase;

    private const KazanPhoto = 'https://upload.wikimedia.org/wikipedia/commons/a/ab/Kazan_Kremlin.jpg';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-10');
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
    }

    /**
     * Answer of the Wikipedia "page summary" endpoint, only the fields CityCover reads.
     *
     * @return array<string, mixed>
     */
    private function summary(string $original, int $width, ?string $thumbnail = null): array
    {
        return [
            'type' => 'standard',
            'originalimage' => ['source' => $original, 'width' => $width, 'height' => 800],
            'thumbnail' => ['source' => $thumbnail ?? $original, 'width' => 320, 'height' => 200],
        ];
    }

    private function createTrip(string $destination): Trip
    {
        $this->actingAs($this->user)->post('/my-trips', [
            'title' => 'Выходные',
            'destination' => $destination,
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-17',
            'is_private' => true,
        ])->assertRedirect();

        return Trip::query()->latest('id')->firstOrFail();
    }

    public function test_new_trip_gets_photo_of_the_city_from_russian_wikipedia(): void
    {
        Http::fake(['ru.wikipedia.org/*' => Http::response($this->summary(self::KazanPhoto, 1200))]);

        $trip = $this->createTrip('Казань, Россия');

        $this->assertSame(self::KazanPhoto, $trip->cover_image);
        // Ищется статья о городе, без страны после запятой.
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/page/summary/'.rawurlencode('Казань')));
    }

    public function test_too_big_original_is_replaced_with_a_large_thumbnail(): void
    {
        Http::fake(['ru.wikipedia.org/*' => Http::response($this->summary(
            self::KazanPhoto,
            6000,
            'https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Kazan_Kremlin.jpg/320px-Kazan_Kremlin.jpg',
        ))]);

        $trip = $this->createTrip('Казань');

        $this->assertSame('https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Kazan_Kremlin.jpg/1280px-Kazan_Kremlin.jpg', $trip->cover_image);
    }

    public function test_english_wikipedia_is_used_when_there_is_no_russian_article(): void
    {
        Http::fake([
            'ru.wikipedia.org/*' => Http::response(['type' => 'https://mediawiki.org/wiki/HyperSwitch/errors/not_found'], 404),
            'en.wikipedia.org/*' => Http::response($this->summary(self::KazanPhoto, 1200)),
        ]);

        $this->assertSame(self::KazanPhoto, $this->createTrip('Kazan')->cover_image);
    }

    public function test_trip_is_created_without_cover_when_wikipedia_does_not_answer(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $trip = $this->createTrip('Казань');

        $this->assertNull($trip->cover_image);
        $this->assertSame(3, $trip->days()->count());
    }

    public function test_pictures_from_other_hosts_and_svg_emblems_are_ignored(): void
    {
        Http::fake([
            'ru.wikipedia.org/*' => Http::response($this->summary('https://example.com/kazan.jpg', 1200)),
            'en.wikipedia.org/*' => Http::response($this->summary('https://upload.wikimedia.org/wikipedia/commons/c/cc/Coat_of_Arms.svg', 500)),
        ]);

        $this->assertNull($this->createTrip('Казань')->cover_image);
    }

    public function test_cover_is_refreshed_only_when_the_city_changes(): void
    {
        $trip = Trip::factory()->for($this->user)->create(['destination' => 'Казань', 'cover_image' => self::KazanPhoto]);
        $sochiPhoto = 'https://upload.wikimedia.org/wikipedia/commons/1/11/Sochi.jpg';
        Http::fake(['ru.wikipedia.org/*' => Http::response($this->summary($sochiPhoto, 1200))]);
        $payload = ['title' => 'Отпуск', 'destination' => 'Казань', 'start_date' => '2026-07-01', 'end_date' => '2026-07-03', 'is_private' => true];

        $this->actingAs($this->user)->put("/my-trips/{$trip->id}", $payload)->assertRedirect();
        Http::assertNothingSent();

        $this->put("/my-trips/{$trip->id}", [...$payload, 'destination' => 'Сочи'])->assertRedirect();
        $this->assertSame($sochiPhoto, $trip->fresh()->cover_image);
    }

    public function test_cover_is_shown_on_the_card_and_in_the_trip_header(): void
    {
        $trip = Trip::factory()->for($this->user)->create(['cover_image' => self::KazanPhoto]);

        $this->actingAs($this->user)
            ->get('/my-trips')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('trips.0.coverImage', self::KazanPhoto));

        $this->get("/my-trips/{$trip->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('trip.coverImage', self::KazanPhoto));
    }

    public function test_command_finds_covers_for_old_trips_without_one(): void
    {
        $oldTrip = Trip::factory()->create(['destination' => 'Казань', 'cover_image' => null]);
        $withCover = Trip::factory()->create(['cover_image' => 'https://upload.wikimedia.org/wikipedia/commons/2/22/Own.jpg']);
        Http::fake(['ru.wikipedia.org/*' => Http::response($this->summary(self::KazanPhoto, 1200))]);

        $this->artisan('trips:covers')->assertSuccessful();

        $this->assertSame(self::KazanPhoto, $oldTrip->fresh()->cover_image);
        $this->assertSame('https://upload.wikimedia.org/wikipedia/commons/2/22/Own.jpg', $withCover->fresh()->cover_image);
        Http::assertSentCount(1);
    }
}
