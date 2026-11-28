<?php

namespace App\Filament\Actions;

use App\Models\Abbonamento;
use App\Services\Abbonamenti\SegnaAbbonamento;
use Carbon\CarbonInterface;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * «Segna come abbonamento», uguale sui movimenti bancari e sulle fatture
 * passive: chi rivede le spese a fine mese segna quali si rinnovano da sole e
 * con che cadenza, e da lì parte il promemoria.
 *
 * Se la spesa è il rinnovo di un abbonamento già in elenco, lo si sceglie dalla
 * prima tendina: si aggiornano data e importo invece di creare un doppione.
 */
class SegnaAbbonamentoAction
{
    /**
     * @param  Closure(Model): array{name: string, amount: float|null, date: CarbonInterface, supplier_id: int|null}  $defaults
     */
    public static function make(Closure $defaults, ?Closure $visible = null): Action
    {
        return Action::make('segnaAbbonamento')
            ->label(new HtmlString('Segna come<br>abbonamento'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible($visible ?? true)
            ->modalHeading('Segna come abbonamento')
            ->modalDescription('Solo per quello che si rinnova da sé ogni tre mesi o ogni anno: una settimana prima del rinnovo arriva una mail di promemoria.')
            ->fillForm(function (Model $record) use ($defaults): array {
                $d = $defaults($record);

                return [
                    'existing_id' => self::guessExisting($d['name'], $d['supplier_id']),
                    'name' => $d['name'],
                    'cadence' => Abbonamento::CADENCE_ANNUAL,
                    'amount' => $d['amount'],
                ];
            })
            ->schema([
                Select::make('existing_id')
                    ->label('È il rinnovo di un abbonamento già in elenco?')
                    ->placeholder('No, è un abbonamento nuovo')
                    ->options(fn (): array => Abbonamento::orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->live(),
                TextInput::make('name')
                    ->label('Nome')
                    ->helperText('Cosa si rinnova, per esempio «Vimeo» o «Dominio dolcitaliaexchange.it».')
                    ->required(fn (Get $get): bool => blank($get('existing_id')))
                    ->hidden(fn (Get $get): bool => filled($get('existing_id'))),
                Select::make('cadence')
                    ->label('Ogni quanto si rinnova')
                    ->options(Abbonamento::cadenceOptions())
                    ->required(),
                TextInput::make('amount')
                    ->label('Importo')
                    ->numeric()
                    ->prefix('EUR')
                    ->step(0.01),
            ])
            ->action(function (array $data, Model $record) use ($defaults): void {
                $d = $defaults($record);
                $existingId = filled($data['existing_id'] ?? null) ? (int) $data['existing_id'] : null;

                $abbonamento = app(SegnaAbbonamento::class)->segna(
                    $existingId !== null ? (string) Abbonamento::whereKey($existingId)->value('name') : (string) $data['name'],
                    (string) $data['cadence'],
                    $d['date'],
                    filled($data['amount'] ?? null) ? (float) $data['amount'] : null,
                    $d['supplier_id'],
                    $existingId,
                );

                Notification::make()
                    ->success()
                    ->title('Segnato come abbonamento')
                    ->body('Prossimo rinnovo: '.$abbonamento->next_renewal_on->format('d/m/Y').'. La mail arriva una settimana prima.')
                    ->send();
            });
    }

    /**
     * Abbonamento già in elenco a cui probabilmente appartiene la spesa: stesso
     * nome, oppure l'unico abbonamento di quel fornitore. Con più abbonamenti
     * dello stesso fornitore (SiteGround: un dominio per riga) non indovina e
     * lascia scegliere.
     */
    private static function guessExisting(string $name, ?int $supplierId): ?int
    {
        $byName = Abbonamento::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])->value('id');
        if ($byName !== null || $supplierId === null) {
            return $byName;
        }

        $ids = Abbonamento::where('supplier_id', $supplierId)->limit(2)->pluck('id');

        return $ids->count() === 1 ? $ids->first() : null;
    }
}
