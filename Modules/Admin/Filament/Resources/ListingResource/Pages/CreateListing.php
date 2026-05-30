<?php

namespace Modules\Admin\Filament\Resources\ListingResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Filament\Resources\ListingResource;
use Modules\Listing\Models\Listing;

class CreateListing extends CreateRecord
{
    protected static string $resource = ListingResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $listing = new Listing;
        $listing->applyAdminFormData($data);
        $listing->save();

        return $listing;
    }
}
