<?php

namespace Modules\User\App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\User\App\Models\User;

final class AuthenticatableUser
{
    public static function canAuthenticate(User $user): bool
    {
        return $user->statusValue() === 'active';
    }

    public static function ensureCanAuthenticate(User $user): void
    {
        if (self::canAuthenticate($user)) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => __('Your account is not allowed to sign in.'),
        ]);
    }

    public static function hasLinkedSocialAccount(User $user): bool
    {
        if (! DB::getSchemaBuilder()->hasTable('socialite_users')) {
            return false;
        }

        return DB::table('socialite_users')
            ->where('user_id', $user->getKey())
            ->exists();
    }
}
