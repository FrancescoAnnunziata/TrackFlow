<?php

namespace App\Filament\Resources\Hours\Pages;

use App\Filament\Resources\Hours\HourResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditHour extends EditRecord
{
    protected static string $resource = HourResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $minutes = (int) ($data['minutes'] ?? 0);
        $data['hours_part'] = intdiv($minutes, 60);
        $data['minutes_part'] = $minutes % 60;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['minutes'] = ((int) ($data['hours_part'] ?? 0)) * 60
            + ((int) ($data['minutes_part'] ?? 0));

        unset($data['hours_part'], $data['minutes_part']);

        return $data;
    }
}
