<?php

namespace App\Http\Controllers;

use App\Models\Device;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DeviceExportController extends Controller
{
    /**
     * Esporta in Excel (XLSX) tutti i dispositivi visibili all'utente.
     * I clienti vedono solo i propri dispositivi (stesso scope della tabella).
     *
     * L'XLSX viene generato su un file temporaneo e restituito con
     * response()->download(): questo imposta un Content-Length corretto ed
     * evita il download "infinito" che si verifica quando la StreamedResponse
     * senza Content-Length viene servita in chunked da nginx/PHP-FPM (Herd).
     */
    public function export(): BinaryFileResponse
    {
        $user = auth()->user();

        $query = Device::query()
            ->with('assignedUser:id,name,surname')
            ->orderBy('asset_code');

        if ($user->isClient()) {
            $query->whereIn('client_id', $user->allClientIds());
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'devices_').'.xlsx';

        // Larghezza colonne (in caratteri) dimensionata sul contenuto tipico,
        // così le celle non stringono/troncano i valori piu' lunghi.
        $options = new Options;
        $options->setColumnWidth(18, 1); // Codice
        $options->setColumnWidth(16, 2); // Tipo
        $options->setColumnWidth(34, 3); // Modello
        $options->setColumnWidth(22, 4); // Numero seriale
        $options->setColumnWidth(26, 5); // Assegnato a

        $writer = new Writer($options);
        $writer->openToFile($tmpPath);

        $headerStyle = (new Style)
            ->setFontBold()
            ->setBackgroundColor('EFEFEF');

        $writer->addRow(Row::fromValues(
            ['Codice', 'Tipo', 'Modello', 'Numero seriale', 'Assegnato a'],
            $headerStyle,
        ));

        $query->chunk(500, function ($devices) use ($writer): void {
            foreach ($devices as $device) {
                $writer->addRow(Row::fromValues([
                    $device->asset_code,
                    $device->type,
                    $device->model,
                    $device->serial_number,
                    $device->assignedUser?->full_name ?? '',
                ]));
            }
        });

        $writer->close();

        return response()
            ->download($tmpPath, 'dispositivi.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }
}
