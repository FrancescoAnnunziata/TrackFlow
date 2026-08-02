<?php

namespace App\Services;

use App\Enums\ReimbursementType;
use App\Models\Reimbursement;
use App\Models\User;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

class ReimbursementNoteExporter
{
    // Colonne della tabella viaggi (indice 0 = colonna A).
    private const COL_GG = 0;

    private const COL_DA = 1;

    private const COL_A = 2;

    private const COL_OGGETTO = 3;

    private const COL_KM = 4;

    private const COL_ALTRE = 5;

    private const COL_NOTE = 6;

    private const COL_TIPO = 7;

    private const WIDTH = 8;

    /**
     * Genera la "Nota spese rimborsi chilometrici" (XLSX) per un utente in un
     * mese, opzionalmente filtrata su un cliente. Restituisce il path del file
     * temporaneo. Trasferte e altre spese (carta personale/manuali) confluiscono
     * nello stesso documento: le trasferte come righe km, le altre spese nella
     * colonna "ALTRE SPESE" del giorno corrispondente.
     */
    public function export(User $user, int $year, int $month, ?int $clientId = null): string
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = (clone $start)->endOfMonth();
        $daysInMonth = $start->daysInMonth;

        $reimbursements = Reimbursement::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->orderBy('date')
            ->get();

        // Indicizza per giorno del mese: trasferta (una per giorno) + altre spese.
        $travelByDay = [];
        $otherByDay = [];
        foreach ($reimbursements as $r) {
            $day = (int) Carbon::parse($r->date)->day;
            if ($r->type === ReimbursementType::Travel) {
                $travelByDay[$day] = $r;
            } else {
                $otherByDay[$day][] = $r;
            }
        }

        $kmRate = (float) ($user->km_rate ?? 0);

        $tmpPath = tempnam(sys_get_temp_dir(), 'rimborso_').'.xlsx';

        $options = new Options;
        $options->setColumnWidth(6, 1);   // GG
        $options->setColumnWidth(28, 2);  // DA
        $options->setColumnWidth(28, 3);  // A
        $options->setColumnWidth(32, 4);  // OGGETTO
        $options->setColumnWidth(9, 5);   // KM
        $options->setColumnWidth(13, 6);  // ALTRE SPESE
        $options->setColumnWidth(22, 7);  // Note
        $options->setColumnWidth(22, 8);  // Tipo trasferta

        $writer = new Writer($options);
        $writer->openToFile($tmpPath);

        $bold = (new Style)->setFontBold();
        $title = (new Style)->setFontBold()->setFontSize(13);
        $header = (new Style)
            ->setFontBold()
            ->setBackgroundColor('EFEFEF')
            ->setCellAlignment(CellAlignment::CENTER)
            ->setBorder($this->allBorders());
        $cell = (new Style)->setBorder($this->allBorders());
        $totalLabel = (new Style)->setFontBold()->setCellAlignment(CellAlignment::RIGHT);

        // --- Intestazione destinatario ---------------------------------------
        $writer->addRow($this->cells([self::COL_OGGETTO => 'Spett.le Società'], $bold));
        $writer->addRow($this->cells([self::COL_OGGETTO => 'NOME', self::COL_KM => config('company.name')]));
        $writer->addRow($this->cells([self::COL_OGGETTO => 'VIA', self::COL_KM => config('company.address')]));
        $writer->addRow($this->cells([self::COL_OGGETTO => "CAP E CITTA'", self::COL_KM => config('company.city')]));
        $writer->addRow($this->cells([self::COL_OGGETTO => 'C.F. e P. IVA:', self::COL_KM => config('company.vat')]));
        $writer->addRow($this->cells([]));

        // --- Dati dipendente / automezzo -------------------------------------
        $writer->addRow($this->cells([self::COL_GG => 'NOTA SPESE RIMBORSI CHILOMETRICI'], $title));
        $writer->addRow($this->cells([self::COL_GG => 'IL SIG. '.trim($user->full_name)], $bold));
        $writer->addRow($this->cells([self::COL_GG => 'HA EFFETTUATO NEL MESE DI '.ucfirst($start->locale('it')->translatedFormat('F Y'))]));
        $writer->addRow($this->cells([self::COL_GG => "MEDIANTE L'USO DELL'AUTOMEZZO DI PROPRIETA'"]));
        $writer->addRow($this->cells([]));
        $writer->addRow($this->cells([
            self::COL_A => 'TARGA',
            self::COL_OGGETTO => 'MODELLO',
            self::COL_KM => 'TARIFFA €/KM',
        ], $bold));
        $writer->addRow($this->cells([
            self::COL_GG => 'AUTOVETTURA',
            self::COL_A => $user->vehicle_plate,
            self::COL_OGGETTO => $user->vehicle_model,
            self::COL_KM => $kmRate,
        ]));
        $writer->addRow($this->cells([]));
        $writer->addRow($this->cells([self::COL_GG => 'I SEGUENTI VIAGGI:'], $bold));

        // --- Intestazione tabella --------------------------------------------
        $writer->addRow($this->cells([
            self::COL_GG => 'GG',
            self::COL_DA => 'DA',
            self::COL_A => 'A',
            self::COL_OGGETTO => 'OGGETTO',
            self::COL_KM => 'KM',
            self::COL_ALTRE => 'ALTRE SPESE',
            self::COL_NOTE => 'Note',
            self::COL_TIPO => 'Tipo trasferta',
        ], $header));

        // --- Righe giornaliere -----------------------------------------------
        $kmTotal = 0.0;
        $altreTotal = 0.0;

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $travel = $travelByDay[$day] ?? null;
            $others = $otherByDay[$day] ?? [];

            $altre = 0.0;
            $notes = [];
            foreach ($others as $o) {
                $altre += (float) $o->amount;
                if ($o->notes) {
                    $notes[] = $o->notes;
                }
            }

            $km = $travel ? (float) $travel->km : null;
            if ($travel && $travel->notes) {
                array_unshift($notes, $travel->notes);
            }

            $kmTotal += $km ?? 0;
            $altreTotal += $altre;

            $writer->addRow($this->cells([
                self::COL_GG => $day,
                self::COL_DA => $travel?->from_location ?? '',
                self::COL_A => $travel?->to_location ?? '',
                self::COL_OGGETTO => $travel?->purpose ?? '',
                self::COL_KM => $km,
                self::COL_ALTRE => $altre > 0 ? $altre : '',
                self::COL_NOTE => implode(' — ', array_filter($notes)),
                self::COL_TIPO => $travel?->travel_type ?? '',
            ], $cell));
        }

        // --- Totali -----------------------------------------------------------
        $indennitaKm = round($kmTotal * $kmRate, 2);
        $totale = round($indennitaKm + $altreTotal, 2);

        $writer->addRow($this->cells([]));
        $writer->addRow($this->cells([
            self::COL_OGGETTO => 'TOTALE KM',
            self::COL_KM => $kmTotal,
        ], $totalLabel));
        $writer->addRow($this->cells([
            self::COL_OGGETTO => "INDENNITA' KM",
            self::COL_KM => $indennitaKm,
        ], $totalLabel));
        $writer->addRow($this->cells([
            self::COL_OGGETTO => 'ALTRE SPESE',
            self::COL_KM => round($altreTotal, 2),
        ], $totalLabel));
        $writer->addRow($this->cells([
            self::COL_OGGETTO => 'TOTALE TRASFERTE',
            self::COL_KM => $totale,
        ], (new Style)->setFontBold()->setFontSize(12)->setCellAlignment(CellAlignment::RIGHT)));

        $writer->close();

        return $tmpPath;
    }

    /**
     * Costruisce una Row dai valori associati agli indici di colonna (0 = A).
     * Le colonne non indicate restano vuote.
     *
     * @param  array<int, mixed>  $values
     */
    private function cells(array $values, ?Style $style = null): Row
    {
        $ordered = array_fill(0, self::WIDTH, '');
        foreach ($values as $col => $value) {
            $ordered[$col] = $value ?? '';
        }

        return $style
            ? Row::fromValues($ordered, $style)
            : Row::fromValues($ordered);
    }

    private function allBorders(): Border
    {
        return new Border(
            new BorderPart(Border::TOP, 'CCCCCC', Border::WIDTH_THIN, Border::STYLE_SOLID),
            new BorderPart(Border::RIGHT, 'CCCCCC', Border::WIDTH_THIN, Border::STYLE_SOLID),
            new BorderPart(Border::BOTTOM, 'CCCCCC', Border::WIDTH_THIN, Border::STYLE_SOLID),
            new BorderPart(Border::LEFT, 'CCCCCC', Border::WIDTH_THIN, Border::STYLE_SOLID),
        );
    }
}
