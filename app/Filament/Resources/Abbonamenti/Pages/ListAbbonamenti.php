<?php

namespace App\Filament\Resources\Abbonamenti\Pages;

use App\Filament\Resources\Abbonamenti\AbbonamentoResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;

class ListAbbonamenti extends ListRecords
{
    protected static string $resource = AbbonamentoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('calendario')
                ->label('Collega a Google Calendar')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->modalHeading('Rinnovi su Google Calendar')
                ->modalContent(fn (): HtmlString => new HtmlString(
                    '<p class="text-sm">In Google Calendar: <strong>Altri calendari → + → Da URL</strong>, incolla questo indirizzo e conferma. '
                    .'I rinnovi compaiono come eventi di un giorno e si aggiornano da soli (Google ricarica il calendario qualche volta al giorno).</p>'
                    .'<p class="text-sm" style="margin-top:.75rem">Chi ha questo indirizzo vede nomi e importi degli abbonamenti: non condividerlo.</p>'
                    .'<input type="text" readonly onclick="this.select()" style="width:100%;margin-top:.75rem;font-family:monospace;font-size:.75rem;padding:.5rem;border:1px solid #ccc;border-radius:.375rem" value="'
                    .e(URL::signedRoute('abbonamenti.calendario')).'">'
                ))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Chiudi'),
            CreateAction::make(),
        ];
    }
}
