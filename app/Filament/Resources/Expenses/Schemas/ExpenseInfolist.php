<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Models\Expense;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

class ExpenseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('user.name')
                    ->label('Utente')
                    ->placeholder('-'),
                TextEntry::make('client.name')
                    ->label('Cliente')
                    ->placeholder('-'),
                TextEntry::make('date')
                    ->label('Data')
                    ->date(),
                TextEntry::make('amount')
                    ->label('Importo')
                    ->money('EUR'),
                ImageEntry::make('attachaments')
                    ->label('Allegati (immagini)')
                    ->disk('public')
                    ->height(140)
                    ->stacked()
                    ->state(fn (Expense $record): array => self::imageAttachments($record))
                    ->visible(fn (Expense $record): bool => self::imageAttachments($record) !== [])
                    ->columnSpanFull(),
                TextEntry::make('pdf_attachments')
                    ->label('Allegati (PDF)')
                    ->state(fn (Expense $record): string => collect(self::pdfAttachments($record))
                        ->map(fn (string $path): string => '<a href="'.e(Storage::disk('public')->url($path)).'" target="_blank" style="text-decoration:underline;">Apri PDF</a>')
                        ->implode('<br>'))
                    ->html()
                    ->visible(fn (Expense $record): bool => self::pdfAttachments($record) !== [])
                    ->columnSpanFull(),
                TextEntry::make('notes')
                    ->label('Note')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function imageAttachments(Expense $record): array
    {
        return collect($record->attachaments ?? [])
            ->reject(fn (string $path): bool => str_ends_with(strtolower($path), '.pdf'))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function pdfAttachments(Expense $record): array
    {
        return collect($record->attachaments ?? [])
            ->filter(fn (string $path): bool => str_ends_with(strtolower($path), '.pdf'))
            ->values()
            ->all();
    }
}
