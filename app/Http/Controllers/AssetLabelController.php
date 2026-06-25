<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Support\AssetCodeRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AssetLabelController extends Controller
{
    /**
     * Etichetta stampabile (tipo Dymo) per un singolo dispositivo.
     */
    public function show(Device $device): View
    {
        abort_unless(auth()->user()->can('view', $device), 403);

        return view('assets.label', [
            'labels' => $this->buildLabels(collect([$device])),
        ]);
    }

    /**
     * Etichette multiple per la stampa in blocco (azione bulk).
     */
    public function bulk(Request $request): View
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id);

        abort_if($ids->isEmpty(), 404);

        $devices = Device::query()
            ->whereIn('id', $ids)
            ->get()
            ->filter(fn (Device $device) => auth()->user()->can('view', $device))
            ->values();

        abort_if($devices->isEmpty(), 404);

        return view('assets.label', [
            'labels' => $this->buildLabels($devices),
        ]);
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @return array<int, array<string, string>>
     */
    private function buildLabels(Collection $devices): array
    {
        return $devices->map(fn (Device $device): array => [
            'company' => $device->client?->name ?? '',
            'asset_code' => $device->asset_code,
            'name' => $device->name,
            'barcode' => AssetCodeRenderer::barcodeDataUri($device->asset_code),
            'qr' => AssetCodeRenderer::qrDataUri(route('assets.lookup', $device->qr_token)),
        ])->all();
    }
}
