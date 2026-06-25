<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Devices\DeviceResource;
use App\Models\Device;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class AssetLookupController extends Controller
{
    /**
     * Lookup di un dispositivo tramite il qr_token presente sull'etichetta.
     *
     * - Utente autenticato e autorizzato: redirect alla scheda completa.
     * - Altrimenti: pagina pubblica minimale, senza dati sensibili.
     */
    public function __invoke(string $qrToken): Response|RedirectResponse
    {
        $device = Device::where('qr_token', $qrToken)->first();

        if (! $device) {
            return response()->view('assets.lookup-public', ['device' => null], 404);
        }

        $user = auth()->user();

        if ($user && $user->can('view', $device)) {
            return redirect(DeviceResource::getUrl('view', ['record' => $device]));
        }

        return response()->view('assets.lookup-public', ['device' => $device]);
    }
}
