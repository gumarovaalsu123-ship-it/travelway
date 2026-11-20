<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\Trip;
use App\Models\TripPlace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Own photos instead of the automatic ones: the trip cover (TripCoverController)
 * and the photo of a place in the day plan (TripPlacePhotoController). Wikimedia is faked.
 */
class TripPhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    private const WikiPhoto = 'https://upload.wikimedia.org/wikipedia/commons/a/ab/Kazan_Kremlin.jpg';

    private User $user;

    private Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
        $this->trip = Trip::factory()->for($this->user)->create([
            'destination' => 'Казань',
            'start_date' => today()->addMonth(),
            'end_date' => today()->addMonth()->addDays(2),
            'cover_image' => self::WikiPhoto,
        ]);
    }

    private function fakePng(string $name = 'photo.png'): File
    {
        $onePixelPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        return UploadedFile::fake()->createWithContent($name, $onePixelPng);
    }

    private function assertStoredFile(?string $url): void
    {
        $this->assertStringStartsWith('/storage/', $url);
        Storage::disk('public')->assertExists(substr($url, strlen('/storage/')));
    }

    private function visit(?string $image = null): TripPlace
    {
        return $this->trip->days()->first()->addPlace(Place::factory()->for($this->user)->create(['image' => $image]));
    }

    public function test_owner_uploads_own_cover_and_the_previous_uploaded_one_is_deleted(): void
    {
        $this->actingAs($this->user)
            ->post("/my-trips/{$this->trip->id}/cover", ['photo' => $this->fakePng()])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast', 'Обложка обновлена.');

        $firstCover = $this->trip->refresh()->cover_image;
        $this->assertStringStartsWith('/storage/covers/', $firstCover);
        $this->assertStoredFile($firstCover);

        $this->actingAs($this->user)->post("/my-trips/{$this->trip->id}/cover", ['photo' => $this->fakePng()]);

        $this->assertStoredFile($this->trip->refresh()->cover_image);
        Storage::disk('public')->assertMissing(substr($firstCover, strlen('/storage/')));
    }

    public function test_resetting_cover_deletes_the_file_and_finds_the_city_photo_again(): void
    {
        Http::fake(['ru.wikipedia.org/*' => Http::response([
            'type' => 'standard',
            'originalimage' => ['source' => self::WikiPhoto, 'width' => 1200, 'height' => 800],
        ])]);
        Storage::disk('public')->put('covers/mine.jpg', 'photo');
        $this->trip->update(['cover_image' => '/storage/covers/mine.jpg']);

        $this->actingAs($this->user)
            ->delete("/my-trips/{$this->trip->id}/cover")
            ->assertInertiaFlash('toast', 'Вернули обложку по городу.');

        $this->assertSame(self::WikiPhoto, $this->trip->refresh()->cover_image);
        Storage::disk('public')->assertMissing('covers/mine.jpg');
    }

    public function test_changing_the_city_keeps_an_uploaded_cover(): void
    {
        $this->trip->update(['cover_image' => '/storage/covers/mine.jpg']);

        $this->actingAs($this->user)->put("/my-trips/{$this->trip->id}", [
            'title' => $this->trip->title,
            'destination' => 'Казань, Татарстан',
            'start_date' => $this->trip->start_date->toDateString(),
            'end_date' => $this->trip->end_date->toDateString(),
            'is_private' => true,
        ])->assertSessionHasNoErrors();

        // Http::preventStrayRequests: если бы обложку искали заново, тест упал бы на запросе к Википедии.
        $this->assertSame('/storage/covers/mine.jpg', $this->trip->refresh()->cover_image);
    }

    public function test_cover_must_be_an_image_and_strangers_cannot_change_it(): void
    {
        $this->actingAs($this->user)
            ->post("/my-trips/{$this->trip->id}/cover", ['photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('photo');

        $this->actingAs(User::factory()->create())
            ->post("/my-trips/{$this->trip->id}/cover", ['photo' => $this->fakePng()])
            ->assertForbidden();

        $this->assertSame(self::WikiPhoto, $this->trip->refresh()->cover_image);
    }

    public function test_owner_uploads_photo_of_a_place(): void
    {
        $visit = $this->visit(self::WikiPhoto);

        $this->actingAs($this->user)
            ->post("/my-trips/{$this->trip->id}/trip-places/{$visit->id}/photo", ['photo' => $this->fakePng()])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast', 'Фото места обновлено.');

        $image = $visit->place->refresh()->image;
        $this->assertStringStartsWith('/storage/places/', $image);
        $this->assertStoredFile($image);
    }

    public function test_removing_own_place_photo_deletes_the_file_and_looks_in_wikimedia_again(): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        Storage::disk('public')->put('places/mine.jpg', 'photo');
        $visit = $this->visit('/storage/places/mine.jpg');

        $this->actingAs($this->user)
            ->delete("/my-trips/{$this->trip->id}/trip-places/{$visit->id}/photo")
            ->assertInertiaFlash('toast', 'Своё фото удалено.');

        $this->assertNull($visit->place->refresh()->image);
        Storage::disk('public')->assertMissing('places/mine.jpg');
    }

    public function test_place_photo_of_another_trip_is_not_found_and_strangers_are_forbidden(): void
    {
        $visit = $this->visit();
        $foreignVisit = Trip::factory()->create()->days()->first()->addPlace(Place::factory()->create());

        $this->actingAs(User::factory()->create())
            ->post("/my-trips/{$this->trip->id}/trip-places/{$visit->id}/photo", ['photo' => $this->fakePng()])
            ->assertForbidden();

        $this->actingAs($this->user)
            ->post("/my-trips/{$this->trip->id}/trip-places/{$foreignVisit->id}/photo", ['photo' => $this->fakePng()])
            ->assertNotFound();
    }

    public function test_deleting_an_unused_place_deletes_its_uploaded_photo(): void
    {
        Storage::disk('public')->put('places/mine.jpg', 'photo');
        $visit = $this->visit('/storage/places/mine.jpg');
        $place = $visit->place;

        $this->actingAs($this->user)->delete("/trip-places/{$visit->id}")->assertRedirect();

        $this->assertModelMissing($place);
        Storage::disk('public')->assertMissing('places/mine.jpg');
    }
}
