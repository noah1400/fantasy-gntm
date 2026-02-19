<?php

namespace App\Filament\Admin\Resources\TopModelResource\Pages;

use App\Filament\Admin\Resources\TopModelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTopModels extends ListRecords
{
    protected static string $resource = TopModelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
