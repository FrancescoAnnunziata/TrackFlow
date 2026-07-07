<?php

namespace App\Http\Controllers;

use App\Models\Device;
use OpenSpout\Common\Entity\Row;
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
            $query->where('client_id', $user->client_id);
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'devices_').'.xlsx';

        $writer = new Writer();
        $writer->openToFile($tmpPath);
        $writer->addRow(Row::fromValues(['Codice', 'Tipo', 'Modello', 'Numero seriale', 'Assegnato a']));

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
