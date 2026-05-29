<?php

namespace Modules\Listing\App\Console;

use Illuminate\Console\Command;
use Modules\Listing\Models\Listing;
use Modules\Listing\States\ExpiredListingStatus;

class ExpireListingsCommand extends Command
{
    protected $signature = 'listings:expire';

    protected $description = 'Mark active listings past their expiry date as expired';

    public function handle(): int
    {
        $count = Listing::query()
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get()
            ->each(function (Listing $listing): void {
                $listing->status->transitionTo(ExpiredListingStatus::class);
            })
            ->count();

        $this->info("Expired {$count} listing(s).");

        return self::SUCCESS;
    }
}
