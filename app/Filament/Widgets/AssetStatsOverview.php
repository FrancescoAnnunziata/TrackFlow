<?php

namespace App\Filament\Widgets;

use App\Models\Device;
use App\Models\SecurityFinding;
use App\Models\SupportTicket;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class AssetStatsOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Asset Management';

    public static function canView(): bool
    {
        return ! auth()->user()->isClient();
    }

    protected function getStats(): array
    {
        $clientId = auth()->user()?->isClient() ? auth()->user()->client_id : null;

        $devices = fn (): Builder => $clientId
            ? Device::query()->where('client_id', $clientId)
            : Device::query();

        $tickets = fn (): Builder => $clientId
            ? SupportTicket::query()->where('client_id', $clientId)
            : SupportTicket::query();

        $findings = fn (): Builder => $clientId
            ? SecurityFinding::query()->where('client_id', $clientId)
            : SecurityFinding::query();

        $withoutRecentCheck = $devices()
            ->whereDoesntHave('securityChecks', fn (Builder $q) => $q->where('checked_at', '>=', now()->subDays(90)))
            ->count();

        $warrantyExpiring = $devices()
            ->whereNotNull('warranty_until')
            ->whereBetween('warranty_until', [now(), now()->addDays(60)])
            ->count();

        return [
            Stat::make('Dispositivi totali', $devices()->count())
                ->icon('heroicon-o-computer-desktop'),
            Stat::make('Assegnati', $devices()->where('status', 'assigned')->count())
                ->icon('heroicon-o-user')
                ->color('success'),
            Stat::make('In manutenzione', $devices()->whereIn('status', ['maintenance', 'repair'])->count())
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('warning'),
            Stat::make('Ticket aperti', $tickets()->whereIn('status', ['open', 'in_progress', 'waiting'])->count())
                ->icon('heroicon-o-lifebuoy')
                ->color('info'),
            Stat::make('Criticità aperte', $findings()->whereIn('status', ['open', 'in_progress'])->count())
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger'),
            Stat::make('Senza security check (90gg)', $withoutRecentCheck)
                ->icon('heroicon-o-shield-exclamation')
                ->color($withoutRecentCheck > 0 ? 'warning' : 'success'),
            Stat::make('Garanzie in scadenza (60gg)', $warrantyExpiring)
                ->icon('heroicon-o-clock')
                ->color($warrantyExpiring > 0 ? 'warning' : 'gray'),
        ];
    }
}
