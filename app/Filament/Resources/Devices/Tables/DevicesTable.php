<?php

namespace App\Filament\Resources\Devices\Tables;

use App\Enums\DeviceCategory;
use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\DeviceMaintenance;
use App\Models\SupportTicket;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DevicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('asset_code')
                    ->label('Codice')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client.name')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable()
                    ->visible(fn (): bool => ! auth()->user()->isClient()),
                TextColumn::make('category')
                    ->label('Categoria')
                    ->badge(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->toggleable(),
                TextColumn::make('manufacturer')
                    ->label('Produttore')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('model')
                    ->label('Modello')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('assignedUser.name')
                    ->label('Assegnato a')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Stato')
                    ->badge(),
                TextColumn::make('latestSecurityCheck.outcome')
                    ->label('Security')
                    ->badge()
                    ->placeholder('Mai verificato')
                    ->color(fn ($state) => $state?->getColor() ?? 'gray'),
                TextColumn::make('warranty_until')
                    ->label('Garanzia')
                    ->date()
                    ->sortable()
                    ->color(fn (?Device $record) => $record?->warranty_until && $record->warranty_until->isPast() ? 'danger' : null)
                    ->toggleable(),
                TextColumn::make('next_maintenance_at')
                    ->label('Prossima manut.')
                    ->date()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Stato')
                    ->options(DeviceStatus::class),
                SelectFilter::make('category')
                    ->label('Categoria')
                    ->options(DeviceCategory::class),
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(fn (): array => Device::query()
                        ->whereNotNull('type')
                        ->distinct()
                        ->orderBy('type')
                        ->pluck('type', 'type')
                        ->all()),
                SelectFilter::make('assigned_user_id')
                    ->label('Assegnatario')
                    ->relationship('assignedUser', 'name')
                    ->searchable()
                    ->preload()
                    ->visible(fn (): bool => ! auth()->user()->isClient()),
                Filter::make('warranty_expiring')
                    ->label('Garanzia in scadenza (60gg)')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('warranty_until')
                        ->whereBetween('warranty_until', [now(), now()->addDays(60)])),
                TernaryFilter::make('security_status')
                    ->label('Security non conforme')
                    ->placeholder('Tutti')
                    ->trueLabel('Solo non conformi / mancanti')
                    ->falseLabel('Solo conformi')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where(fn (Builder $q) => $q
                            ->whereDoesntHave('securityChecks')
                            ->orWhereHas('latestSecurityCheck', fn (Builder $c) => $c->whereIn('outcome', ['warning', 'non_compliant']))),
                        false: fn (Builder $query): Builder => $query
                            ->whereHas('latestSecurityCheck', fn (Builder $c) => $c->where('outcome', 'compliant')),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    self::assignAction(),
                    self::returnAction(),
                    self::maintenanceAction(),
                    self::securityCheckAction(),
                    self::ticketAction(),
                    self::labelAction(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::printLabelsBulkAction(),
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => ! auth()->user()->isClient()),
                ]),
            ]);
    }

    private static function assignAction(): Action
    {
        return Action::make('assign')
            ->label('Assegna')
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('success')
            ->visible(fn (): bool => ! auth()->user()->isClient())
            ->schema([
                Select::make('user_id')
                    ->label('Assegna a')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                Textarea::make('notes')
                    ->label('Note'),
            ])
            ->action(function (Device $record, array $data): void {
                $record->assignTo(User::findOrFail($data['user_id']), $data['notes'] ?? null);
                Notification::make()->title('Dispositivo assegnato')->success()->send();
            });
    }

    private static function returnAction(): Action
    {
        return Action::make('return')
            ->label('Rientro')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->visible(fn (Device $record): bool => ! auth()->user()->isClient() && $record->assigned_user_id !== null)
            ->schema([
                Textarea::make('notes')
                    ->label('Note rientro'),
            ])
            ->action(function (Device $record, array $data): void {
                $record->returnFromUser($data['notes'] ?? null);
                Notification::make()->title('Rientro registrato')->success()->send();
            });
    }

    private static function maintenanceAction(): Action
    {
        return Action::make('maintenance')
            ->label('Registra manutenzione')
            ->icon(Heroicon::OutlinedWrenchScrewdriver)
            ->color('gray')
            ->visible(fn (): bool => ! auth()->user()->isClient())
            ->schema([
                DatePicker::make('maintenance_date')
                    ->label('Data')
                    ->default(now())
                    ->required(),
                Select::make('type')
                    ->label('Tipo')
                    ->options(\App\Enums\MaintenanceType::class)
                    ->default(\App\Enums\MaintenanceType::Ordinary)
                    ->required(),
                Textarea::make('description')
                    ->label('Descrizione'),
                TextInput::make('cost')
                    ->label('Costo')
                    ->numeric()
                    ->prefix('EUR')
                    ->step(0.01),
                TextInput::make('supplier')
                    ->label('Fornitore'),
                DatePicker::make('next_maintenance_at')
                    ->label('Prossima manutenzione'),
            ])
            ->action(function (Device $record, array $data): void {
                $record->maintenances()->create([
                    'client_id' => $record->client_id,
                    'performed_by_user_id' => auth()->id(),
                    'maintenance_date' => $data['maintenance_date'],
                    'type' => $data['type'],
                    'description' => $data['description'] ?? null,
                    'cost' => $data['cost'] ?? null,
                    'supplier' => $data['supplier'] ?? null,
                    'next_maintenance_at' => $data['next_maintenance_at'] ?? null,
                ]);

                if (! empty($data['next_maintenance_at'])) {
                    $record->update(['next_maintenance_at' => $data['next_maintenance_at']]);
                }

                Notification::make()->title('Manutenzione registrata')->success()->send();
            });
    }

    private static function securityCheckAction(): Action
    {
        return Action::make('securityCheck')
            ->label('Nuovo security check')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('info')
            ->visible(fn (): bool => ! auth()->user()->isClient())
            ->url(fn (Device $record): string => \App\Filament\Resources\DeviceSecurityChecks\DeviceSecurityCheckResource::getUrl('create', ['device_id' => $record->id]));
    }

    private static function ticketAction(): Action
    {
        return Action::make('ticket')
            ->label('Apri ticket')
            ->icon(Heroicon::OutlinedLifebuoy)
            ->color('primary')
            ->schema([
                TextInput::make('title')
                    ->label('Titolo')
                    ->required(),
                Textarea::make('description')
                    ->label('Descrizione'),
                Select::make('priority')
                    ->label('Priorità')
                    ->options(\App\Enums\TicketPriority::class)
                    ->default(\App\Enums\TicketPriority::Medium)
                    ->required(),
            ])
            ->action(function (Device $record, array $data): void {
                SupportTicket::create([
                    'client_id' => $record->client_id,
                    'device_id' => $record->id,
                    'opened_by_user_id' => auth()->id(),
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'priority' => $data['priority'],
                    'status' => \App\Enums\TicketStatus::Open,
                ]);

                Notification::make()->title('Ticket aperto')->success()->send();
            });
    }

    private static function labelAction(): Action
    {
        return Action::make('label')
            ->label('Stampa etichetta')
            ->icon(Heroicon::OutlinedQrCode)
            ->color('gray')
            ->url(fn (Device $record): string => route('assets.label', $record), shouldOpenInNewTab: true);
    }

    private static function printLabelsBulkAction(): BulkAction
    {
        return BulkAction::make('printLabels')
            ->label('Stampa etichette selezionate')
            ->icon(Heroicon::OutlinedQrCode)
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => redirect()->route('assets.labels', [
                'ids' => $records->pluck('id')->implode(','),
            ]));
    }
}
