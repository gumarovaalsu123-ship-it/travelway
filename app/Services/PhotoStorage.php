<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Photos the user uploads himself: the trip cover and place photos.
 *
 * Files go to the public disk (storage/app/public/<folder>), like avatars. In the database we keep the same
 * kind of value as for Wikipedia photos — a ready address for <img>, only local: "/storage/covers/abc.jpg".
 * So the pages show both kinds the same way, and no new columns are needed.
 * The address is relative (without the host), so it works both on localhost:8000 and on the OSPanel domain.
 */
class PhotoStorage
{
    private const UrlPrefix = '/storage/';

    /**
     * Save the file and return its address for the browser.
     */
    public function store(UploadedFile $file, string $folder): string
    {
        // store() itself comes up with a unique file name and returns a path like "covers/abc123.jpg".
        return self::UrlPrefix.$file->store($folder, 'public');
    }

    /**
     * Whether the photo was uploaded by the user (and not taken from Wikipedia).
     */
    public function isUploaded(?string $url): bool
    {
        return $url !== null && Str::startsWith($url, self::UrlPrefix);
    }

    /**
     * Delete an uploaded file. Wikipedia addresses and null are simply ignored.
     */
    public function delete(?string $url): void
    {
        if ($this->isUploaded($url)) {
            Storage::disk('public')->delete(Str::after($url, self::UrlPrefix));
        }
    }
}
