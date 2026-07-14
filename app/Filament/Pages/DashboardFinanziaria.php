<?php

namespace App\Filament\Pages;

use App\Models\BankTransaction;
use App\Services\Reporting\FinancialOverviewBuilder;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Dashboard finanziaria mensile, con le due entità tenute separate: G8LABS (SRL,
 * regime ordinario) e Giorgio Giotto (P.IVA forfettaria). Solo admin.
 */
class DashboardFinanziaria extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Controllo Finanziario';

    protected static ?string $title = 'Dashboard finanziaria';

    protected static ?string $navigationLabel = 'Dashboard finanziaria';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.dashboard-finanziaria';

    private const MESI = [
        1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile', 5 => 'Maggio', 6 => 'Giugno',
        7 => 'Luglio', 8 => 'Agosto', 9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre',
    ];

    public int $year;

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->isAdmin();
    }

    /**
     * Modale con l'elenco dei movimenti bancari dietro una cella della tabella
     * mensile (entrate/uscite di un mese, o uscite non riconciliate). I criteri
     * ricalcano esattamente quelli del builder: giroconti sempre esclusi.
     */
    public function movimentiAction(): Action
    {
        return Action::make('movimenti')
            ->modalHeading(fn (array $arguments): string => $this->movimentiTitolo($arguments))
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Chiudi')
            ->modalContent(function (array $arguments) {
                $tipo = (string) ($arguments['tipo'] ?? 'entrate');
                $mese = (int) ($arguments['mese'] ?? 0);
                $anno = (int) ($arguments['anno'] ?? $this->year);

                return view('filament.pages.partials.movimenti-modal', [
                    'movimenti' => $this->movimenti($tipo, $mese, $anno),
                    'tipo' => $tipo,
                ]);
            });
    }

    private function movimentiTitolo(array $arguments): string
    {
        $tipo = (string) ($arguments['tipo'] ?? 'entrate');
        $mese = (int) ($arguments['mese'] ?? 0);
        $anno = (int) ($arguments['anno'] ?? $this->year);

        $etichetta = match ($tipo) {
            'uscite' => 'Uscite',
            'non_attribuite' => 'Uscite non riconciliate',
            default => 'Entrate',
        };
        $periodo = isset(self::MESI[$mese]) ? self::MESI[$mese].' '.$anno : (string) $anno;

        return "{$etichetta} — {$periodo}";
    }

    /**
     * Movimenti bancari corrispondenti alla cella cliccata. `mese` a 0 = tutto
     * l'anno (usato dal totale e dall'avviso uscite non riconciliate).
     *
     * @return Collection<int, BankTransaction>
     */
    public function movimenti(string $tipo, int $mese, int $anno): Collection
    {
        $query = BankTransaction::query()
            ->with('bankAccount')
            ->withCount('reconciliations')
            ->whereYear('booked_at', $anno)
            // I giroconti tra conti propri sono esclusi dal quadro operativo,
            // esattamente come nel FinancialOverviewBuilder.
            ->whereNull('transfer_pair_id');

        if ($mese >= 1 && $mese <= 12) {
            $query->whereMonth('booked_at', $mese);
        }

        match ($tipo) {
            'uscite' => $query->where('amount', '<', 0),
            'non_attribuite' => $query->where('amount', '<', 0)->doesntHave('reconciliations'),
            default => $query->where('amount', '>=', 0),
        };

        return $query->orderBy('booked_at')->get();
    }

    public function mount(): void
    {
        $this->year = (int) now()->year;
    }

    /**
     * @return array<int, int>
     */
    public function anniDisponibili(): array
    {
        $current = (int) now()->year;

        return range($current, $current - 4);
    }

    /**
     * @return array<string, mixed>
     */
    public function dati(): array
    {
        $builder = app(FinancialOverviewBuilder::class);

        return [
            'g8labs' => $builder->g8labsMonthly($this->year),
            'g8labsTotali' => $builder->g8labsTotals($this->year),
            'snapshot' => $builder->g8labsSnapshot(),
            'forfettario' => $builder->forfettario($this->year),
        ];
    }
}
