<?php

namespace App\Filament\Admin\Resources\EpisodeResource\Pages;

use App\Filament\Admin\Resources\EpisodeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEpisode extends EditRecord
{
    protected static string $resource = EpisodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
