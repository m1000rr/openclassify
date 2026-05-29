<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (Schema::hasTable('settings')) {
            Artisan::call('migrate', [
                '--path' => 'database/settings',
                '--force' => true,
            ]);
        }

        $this->call([
            \Modules\User\Database\Seeders\AuthUserSeeder::class,
            \Modules\Location\Database\Seeders\LocationSeeder::class,
            \Modules\Category\Database\Seeders\CategorySeeder::class,
            \Modules\Listing\Database\Seeders\ListingCustomFieldSeeder::class,
            \Modules\Listing\Database\Seeders\ListingSeeder::class,
            \Modules\User\Database\Seeders\UserWorkspaceSeeder::class,
        ]);
    }
}
