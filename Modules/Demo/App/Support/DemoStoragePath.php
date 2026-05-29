<?php

namespace Modules\Demo\App\Support;

use Illuminate\Support\Facades\Storage;
use Throwable;

final class DemoStoragePath
{
    public static function prefix(string $path): string
    {
        $uuid = trim((string) config('demo.active_uuid', ''));

        if ($uuid === '') {
            return ltrim($path, '/');
        }

        return 'demo/'.$uuid.'/'.ltrim($path, '/');
    }

    public static function purgeForUuid(string $uuid): void
    {
        $uuid = trim($uuid);

        if ($uuid === '') {
            return;
        }

        $prefix = 'demo/'.$uuid;

        foreach (array_unique([
            (string) config('filesystems.default', 'public'),
            'public',
            's3',
            (string) config('media_storage.local_disk', 'public'),
            (string) config('media_storage.cloud_disk', 's3'),
        ]) as $disk) {
            try {
                Storage::disk($disk)->deleteDirectory($prefix);
            } catch (Throwable) {
            }
        }
    }
}
