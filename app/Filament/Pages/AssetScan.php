<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Devices\DeviceResource;
use App\Models\Device;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class AssetScan extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewfinderCircle;

    protected static string|\UnitEnum|null $navigationGroup = 'Asset Management';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'Scansione barcode';

    protected static ?string $navigationLabel = 'Scansione barcode';

    protected string $view = 'filament.pages.asset-scan';

    /** Valore letto dal lettore barcode USB (digitato + invio). */
    public string $code = '';

    /** True quando la ricerca non ha prodotto risultati. */
    public bool $notFound = false;

    public static function canAccess(): bool
    {
        return ! auth()->user()->isClient();
    }

    public function search(): void
    {
        $this->notFound = false;

        $code = trim($this->code);

        if ($code === '') {
            return;
        }

        $device = $this->scopedQuery()
            ->where(function (Builder $query) use ($code): void {
                $query->where('asset_code', $code)
                    ->orWhere('barcode', $code)
                    ->orWhere('serial_number', $code);
            })
            ->first();

        if ($device) {
            $this->redirect(DeviceResource::getUrl('view', ['record' => $device]));

            return;
        }

        $this->notFound = true;
    }

    public function createWithCode(): void
    {
        $this->redirect(DeviceResource::getUrl('create', ['serial_number' => trim($this->code)]));
    }

    private function scopedQuery(): Builder
    {
        $query = Device::query();
        $user = auth()->user();

        if ($user && $user->isClient()) {
            $query->whereIn('client_id', $user->allClientIds());
        }

        return $query;
    }
}
