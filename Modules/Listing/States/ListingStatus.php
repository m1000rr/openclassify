<?php

namespace Modules\Listing\States;

use A909M\FilamentStateFusion\Concerns\StateFusionInfo;
use A909M\FilamentStateFusion\Contracts\HasFilamentStateFusion;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

abstract class ListingStatus extends State implements HasFilamentStateFusion
{
    use StateFusionInfo;

    public static function config(): StateConfig
    {
        return parent::config()
            ->default(PendingListingStatus::class)
            ->allowTransition(PendingListingStatus::class, ActiveListingStatus::class)
            ->allowTransition(PendingListingStatus::class, SoldListingStatus::class)
            ->allowTransition(PendingListingStatus::class, ExpiredListingStatus::class)
            ->allowTransition(ActiveListingStatus::class, SoldListingStatus::class)
            ->allowTransition(ActiveListingStatus::class, ExpiredListingStatus::class)
            ->allowTransition(ActiveListingStatus::class, PendingListingStatus::class)
            ->allowTransition(ExpiredListingStatus::class, ActiveListingStatus::class)
            ->allowTransition(SoldListingStatus::class, ActiveListingStatus::class)
            ->allowTransition(SoldListingStatus::class, ExpiredListingStatus::class)
            ->ignoreSameState();
    }
}
