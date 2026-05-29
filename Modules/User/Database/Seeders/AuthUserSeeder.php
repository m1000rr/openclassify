<?php

namespace Modules\User\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Modules\User\App\Models\User;
use Modules\User\App\Support\DemoUserCatalog;
use Spatie\Permission\Models\Role;

class AuthUserSeeder extends Seeder
{
    public function run(): void
    {
        $users = collect(DemoUserCatalog::records())
            ->map(function (array $record): User {
                $user = User::query()->updateOrCreate(
                    ['email' => $record['email']],
                    [
                        'name' => $record['name'],
                        'password' => $record['password'],
                    ],
                );

                $user->forceFill([
                    'status' => 'active',
                    'email_verified_at' => now(),
                ])->save();

                return $user;
            });

        if (! class_exists(Role::class) || ! Schema::hasTable((new Role)->getTable())) {
            return;
        }

        $adminRole = Role::query()->firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'web',
        ]);

        $users->each(function (User $user) use ($adminRole): void {
            if (DemoUserCatalog::isAdmin($user->email)) {
                $user->syncRoles([$adminRole->name]);

                return;
            }

            $user->syncRoles([]);
        });
    }
}
