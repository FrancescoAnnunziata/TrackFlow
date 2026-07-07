<?php

namespace App\Filament\Resources\Reimbursements\Schemas;

use App\Enums\ReimbursementStatus;
use App\Enums\ReimbursementType;
use App\Models\Reimbursement;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ReimbursementForm
{
    private const ATTACHMENT_DISK = 'public';

    private const ATTACHMENT_DIRECTORY = 'reimbursement-attachments';

    public static function configure(Schema $schema): Schema
    {
        // I rimborsi generati da una spesa (carta personale) sono sincronizzati:
        // i campi vengono dalla Spesa e non si modificano qui.
        $fromExpense = fn (?Reimbursement $record): bool => (bool) $record?->isFromExpense();

        return $schema
            ->components([
                Placeholder::make('from_expense_note')
                    ->label('')
                    ->content('Rimborso generato automaticamente da una spesa pagata con carta personale. Importo, data e allegati si modificano dalla sezione Spese.')
                    ->visible($fromExpense)
                    ->columnSpanFull(),
                Select::make('type')
                    ->label('Tipologia')
                    ->options(ReimbursementType::class)
                    ->default(ReimbursementType::Travel)
                    ->disabled($fromExpense)
                    ->required(),
                DatePicker::make('date')
                    ->label('Data')
                    ->default(now())
                    ->disabled($fromExpense)
                    ->required(),
                TextInput::make('amount')
                    ->label('Importo')
                    ->numeric()
                    ->required()
                    ->disabled($fromExpense)
                    ->prefix('EUR')
                    ->step(0.01),
                Select::make('status')
                    ->label('Stato')
                    ->options(ReimbursementStatus::class)
                    ->default(ReimbursementStatus::Pending)
                    // Solo gli admin gestiscono lo stato (approvazione/rimborso).
                    ->disabled(fn (): bool => ! auth()->user()->isAdmin())
                    ->dehydrated()
                    ->required(),
                Select::make('client_id')
                    ->label('Cliente di riferimento (opzionale)')
                    ->relationship(name: 'client', titleAttribute: 'name')
                    ->searchable()
                    ->preload()
                    ->disabled($fromExpense),
                FileUpload::make('attachments')
                    ->label('Allegati (ricevute/scontrini)')
                    ->helperText('Foto o PDF di giustificativi.')
                    ->multiple()
                    ->acceptedFileTypes([
                        'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'application/pdf',
                    ])
                    ->maxSize(20480)
                    ->disk(self::ATTACHMENT_DISK)
                    ->directory(self::ATTACHMENT_DIRECTORY)
                    ->visibility('public')
                    ->downloadable()
                    ->openable()
                    ->disabled($fromExpense)
                    ->columnSpanFull(),
                Textarea::make('notes')
                    ->label('Note / causale')
                    ->disabled($fromExpense)
                    ->columnSpanFull(),
            ]);
    }
}
