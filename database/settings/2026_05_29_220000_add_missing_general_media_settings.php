<?php

use Modules\S3\Support\MediaStorage;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.media_disk')) {
            $this->migrator->add('general.media_disk', MediaStorage::defaultDriver());
        }

        if (! $this->migrator->exists('general.site_logo_disk')) {
            $this->migrator->add('general.site_logo_disk', null);
        }
    }
};
