<?php

namespace App\Filament\Resources\Reimbursements\Schemas;

use App\Models\Reimbursement;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

class ReimbursementInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('user.name')
                    ->label('Utente')
                    ->placeholder('-'),
                TextEntry::make('type')
                    ->label('Tipologia')
                    ->badge(),
                TextEntry::make('status')
                    ->label('Stato')
                    ->badge(),
                TextEntry::make('date')
                    ->label('Data')
                    ->date(),
                TextEntry::make('amount')
                    ->label('Importo')
                    ->money('EUR'),
                TextEntry::make('client.name')
                    ->label('Cliente')
                    ->placeholder('-'),
                ImageEntry::make('image_attachments')
                    ->label('Allegati (immagini)')
                    ->disk('public')
                    ->height(140)
                    ->stacked()
                    ->state(fn (Reimbursement $record): array => self::imageAttachments($record))
                    ->visible(fn (Reimbursement $record): bool => self::imageAttachments($record) !== [])
                    ->columnSpanFull(),
                TextEntry::make('pdf_attachments')
                    ->label('Allegati (documenti)')
                    ->state(fn (Reimbursement $record): string => collect(self::pdfAttachments($record))
                        ->map(fn (string $path): string => '<a href="'.e(Storage::disk('public')->url($path)).'" target="_blank" style="text-decoration:underline;">Apri '.e(str_ends_with(strtolower($path), '.pdf') ? 'PDF' : basename($path)).'</a>')
                        ->implode('<br>'))
                    ->html()
                    ->visible(fn (Reimbursement $record): bool => self::pdfAttachments($record) !== [])
                    ->columnSpanFull(),
                TextEntry::make('notes')
                    ->label('Note / causale')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function imageAttachments(Reimbursement $record): array
    {
        return collect($record->attachments ?? [])
            ->filter(fn (string $path): bool => self::isImage($path))
            ->values()
            ->all();
    }

    /**
     * Le immagini si mostrano in anteprima; tutto il resto (PDF, l'Excel di
     * una nota spese importata) si apre con un link.
     */
    private static function isImage(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'gif'], true);
    }

    /**
     * @return array<int, string>
     */
    private static function pdfAttachments(Reimbursement $record): array
    {
        return collect($record->attachments ?? [])
            ->reject(fn (string $path): bool => self::isImage($path))
            ->values()
            ->all();
    }
}
