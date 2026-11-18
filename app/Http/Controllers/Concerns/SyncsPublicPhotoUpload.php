<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

trait SyncsPublicPhotoUpload
{
    /**
     * @return array<string, mixed>
     */
    protected function photoUploadRules(): array
    {
        return [
            'photo' => ['nullable', 'image', 'max:2048'],
            'remove_photo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Persist a photo on the public disk. Deletes prior file when replaced or when $removeRequested and no new file.
     *
     * @return array{photo_path: string|null}
     */
    protected function resolvedPhotoPathState(
        Request $request,
        string $directory,
        ?string $previousPath,
    ): array {
        $removeRequested = $request->boolean('remove_photo');

        /** @var UploadedFile|null $file */
        $file = $request->file('photo');

        if ($file instanceof UploadedFile) {
            if ($previousPath !== null && $previousPath !== '') {
                Storage::disk('public')->delete($previousPath);
            }

            return ['photo_path' => $file->store($directory, 'public')];
        }

        if ($removeRequested) {
            if ($previousPath !== null && $previousPath !== '') {
                Storage::disk('public')->delete($previousPath);
            }

            return ['photo_path' => null];
        }

        return ['photo_path' => $previousPath];
    }
}
