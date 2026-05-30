<?php

namespace Modules\Admin\Filament\Resources\ListingResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Filament\Resources\ListingResource;
use Modules\Listing\Models\Listing;

class EditListing extends EditRecord
{
    protected static string $resource = ListingResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Listing) {
            return parent::handleRecordUpdate($record, $data);
        }

        $record->applyAdminFormData($data);
        $record->save();

        return $record;
    }
}
