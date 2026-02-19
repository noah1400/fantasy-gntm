<?php

namespace App\Filament\Admin\Resources\TopModelResource\Pages;

use App\Filament\Admin\Resources\TopModelResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTopModel extends EditRecord
{
    protected static string $resource = TopModelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
