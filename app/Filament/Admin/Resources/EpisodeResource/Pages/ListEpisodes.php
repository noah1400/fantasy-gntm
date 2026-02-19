<?php

namespace App\Filament\Admin\Resources\EpisodeResource\Pages;

use App\Filament\Admin\Resources\EpisodeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEpisodes extends ListRecords
{
    protected static string $resource = EpisodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
