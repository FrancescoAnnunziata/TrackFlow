<?php

namespace App\Filament\Resources\Reimbursements\Pages;

use App\Filament\Resources\Reimbursements\ReimbursementResource;
use App\Models\User;
use App\Services\Reimbursements\NotaSpeseImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class ListReimbursements extends ListRecords
{
    protected static string $resource = ReimbursementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->importaNoteSpeseAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Carica una o più note spese chilometriche in Excel e crea i rimborsi che
     * mancano, intestati alla persona scelta (anche diversa da chi importa: è
     * il caso di Paola che carica le note di Giorgio). Solo admin, come lo
     * stato dei rimborsi.
     */
    private function importaNoteSpeseAction(): Action
    {
        return Action::make('importaNoteSpese')
            ->label('Importa note spese (Excel)')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()->isAdmin())
            ->modalHeading('Importa note spese da Excel')
            ->modalDescription('Carica uno o più file «RIMB. <mese> <anno>.xlsx». Per ogni mese che non c\'è ancora viene creato il rimborso spese, con il costo dei km e delle altre spese; i mesi già presenti si saltano.')
            ->modalSubmitActionLabel('Importa')
            ->schema([
                Select::make('user_id')
                    ->label('Di chi sono le note spese')
                    ->options(fn (): array => User::query()
                        ->whereNotIn('role', ['client', 'accountant'])
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (User $u): array => [$u->id => $u->full_name])
                        ->all())
                    ->required(),
                FileUpload::make('files')
                    ->label('File Excel')
                    ->multiple()
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                    ->disk('public')
                    ->directory('reimbursement-attachments')
                    ->storeFileNamesIn('nomi')
                    ->required(),
            ])
            ->action(function (array $data): void {
                $disco = Storage::disk('public');
                $nomi = (array) ($data['nomi'] ?? []);

                $file = collect((array) $data['files'])
                    ->map(fn (string $allegato): array => [
                        'nome' => $nomi[$allegato] ?? basename($allegato),
                        'percorso' => $disco->path($allegato),
                        'allegato' => $allegato,
                    ])
                    ->values()
                    ->all();

                $esito = app(NotaSpeseImporter::class)->importa($file, User::findOrFail($data['user_id']));

                // Restano solo i file diventati giustificativo di un rimborso.
                $disco->delete(array_values(array_diff((array) $data['files'], $esito['allegatiUsati'])));

                $righe = array_merge(
                    array_map(fn (string $r): string => "✓ Creato {$r}", $esito['creati']),
                    array_map(fn (string $r): string => "– Saltato {$r}", $esito['saltati']),
                    array_map(fn (string $r): string => "✗ Non importato {$r}", $esito['errori']),
                );
                if ($esito['daControllare'] > 0) {
                    $righe[] = "Le altre spese ({$esito['daControllare']}) sono registrate con Conto «".NotaSpeseImporter::CONTO_ALTRE_SPESE.'»: controlla dal menu Costi che sia quello giusto.';
                }

                Notification::make()
                    ->title(count($esito['creati']) > 0 ? 'Rimborsi creati: '.count($esito['creati']) : 'Nessun rimborso creato')
                    ->body(new HtmlString(implode('<br>', array_map('e', $righe))))
                    ->status($esito['errori'] !== [] ? 'warning' : 'success')
                    ->persistent()
                    ->send();
            });
    }
}
