<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Models\PassiveInvoice;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Imagick;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ExpenseForm
{
    private const ATTACHMENT_DISK = 'public';

    private const ATTACHMENT_DIRECTORY = 'expense-attachaments';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('client_id')
                    ->label('Cliente')
                    ->helperText('Solo se la spesa va riaddebitata al cliente.')
                    ->relationship(name: 'client', titleAttribute: 'name')
                    ->searchable()
                    ->preload(),
                DatePicker::make('date')
                    ->label('Data')
                    ->default(now())
                    ->required(),
                TextInput::make('amount')
                    ->label('Importo')
                    ->numeric()
                    ->required()
                    ->prefix('EUR')
                    ->step(0.01),
                Select::make('supplier_id')
                    ->label('Fornitore')
                    ->helperText('Di norma arriva dalla fattura passiva importata da Fatture in Cloud; crealo qui solo se manca.')
                    ->relationship(name: 'supplier', titleAttribute: 'name')
                    ->searchable()
                    ->preload()
                    ->createOptionForm([
                        TextInput::make('name')->label('Nome')->required(),
                        TextInput::make('vat_number')->label('Partita IVA'),
                    ]),
                TextInput::make('conto')
                    ->label('Conto')
                    ->helperText('Categoria di spesa. Ereditata dalla fattura passiva quando la colleghi; per le spese senza fattura scegli fra le categorie di Fatture in Cloud.')
                    ->datalist(fn (): array => self::contoSuggestions()),
                Select::make('passive_invoice_id')
                    ->label('Fattura passiva collegata')
                    ->helperText('Se per questa spesa hai ricevuto una fattura passiva, collegala qui: conto e fornitore vengono ereditati dal documento.')
                    ->relationship(name: 'passiveInvoice', titleAttribute: 'number')
                    ->getOptionLabelFromRecordUsing(fn (PassiveInvoice $record): string => trim(
                        ($record->number ?: '—').' — '.($record->supplier->name ?? ''),
                        ' —',
                    ))
                    ->searchable()
                    ->preload()
                    ->live()
                    // Collegando la passiva, la spesa eredita fornitore e conto
                    // (la categoria di Fatture in Cloud), senza sovrascrivere
                    // valori già impostati a mano.
                    ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                        if (blank($state)) {
                            return;
                        }
                        $passive = PassiveInvoice::find($state);
                        if ($passive === null) {
                            return;
                        }
                        if (blank($get('supplier_id')) && filled($passive->supplier_id)) {
                            $set('supplier_id', $passive->supplier_id);
                        }
                        if (blank($get('conto')) && filled($passive->category)) {
                            $set('conto', $passive->category);
                        }
                    }),
                Toggle::make('paid_with_personal_card')
                    ->label('Pagato con carta personale')
                    ->helperText('Di default le spese si intendono pagate con carta aziendale. Attivando questa opzione la spesa genera automaticamente un rimborso.')
                    ->default(false),
                FileUpload::make('attachaments')
                    ->label('Allegati')
                    ->helperText('Foto (JPEG/PNG/HEIC) oppure PDF. Le immagini vengono ridotte a max 1920px.')
                    ->multiple()
                    ->acceptedFileTypes([
                        'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'application/pdf',
                    ])
                    ->maxSize(20480)
                    ->disk(self::ATTACHMENT_DISK)
                    ->directory(self::ATTACHMENT_DIRECTORY)
                    ->visibility('public')
                    ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file): string => self::storeAttachment($file))
                    ->downloadable()
                    ->openable(),
                Textarea::make('notes')
                    ->label('Note')
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Categorie di spesa suggerite: le categorie provenienti da Fatture in
     * Cloud (sulle fatture passive) più quelle già usate a mano sulle spese.
     *
     * @return array<int, string>
     */
    private static function contoSuggestions(): array
    {
        return PassiveInvoice::query()
            ->whereNotNull('category')->where('category', '!=', '')
            ->distinct()->orderBy('category')->pluck('category')
            ->merge(
                \App\Models\Expense::query()
                    ->whereNotNull('conto')->where('conto', '!=', '')
                    ->distinct()->pluck('conto')
            )
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Salva un allegato. I PDF vengono salvati cosi' come sono. Le immagini
     * vengono normalizzate (orientamento EXIF), gli HEIC/HEIF convertiti in JPEG
     * e tutte ridotte a max 1920px lato server, cosi' restano leggere e
     * visualizzabili ovunque a prescindere dal browser.
     */
    private static function storeAttachment(TemporaryUploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = (string) $file->getMimeType();

        // PDF: salvato tal quale.
        if ($extension === 'pdf' || $mime === 'application/pdf') {
            return $file->storeAs(
                self::ATTACHMENT_DIRECTORY,
                (string) Str::ulid().'.pdf',
                self::ATTACHMENT_DISK,
            );
        }

        $image = new Imagick($file->getRealPath());
        $image->setIteratorIndex(0);
        self::normalizeOrientation($image);

        $isHeic = in_array($extension, ['heic', 'heif'], true)
            || in_array($mime, ['image/heic', 'image/heif'], true);

        if ($isHeic) {
            $image->setImageFormat('jpeg');
            $extension = 'jpg';
        }

        if ($image->getImageWidth() > 1920 || $image->getImageHeight() > 1920) {
            $image->thumbnailImage(1920, 1920, true);
        }
        $image->setImageCompressionQuality(85);

        $path = self::ATTACHMENT_DIRECTORY.'/'.(string) Str::ulid().'.'.($extension ?: 'jpg');
        Storage::disk(self::ATTACHMENT_DISK)->put($path, $image->getImageBlob());
        $image->clear();

        return $path;
    }

    /**
     * Applica fisicamente l'orientamento EXIF ruotando l'immagine, cosi' la foto
     * resta dritta una volta convertita in JPEG. Usa solo metodi presenti in ogni
     * build di Imagick (a differenza di autoOrientImage()).
     */
    private static function normalizeOrientation(Imagick $image): void
    {
        match ($image->getImageOrientation()) {
            Imagick::ORIENTATION_BOTTOMRIGHT => $image->rotateImage('#000', 180),
            Imagick::ORIENTATION_RIGHTTOP => $image->rotateImage('#000', 90),
            Imagick::ORIENTATION_LEFTBOTTOM => $image->rotateImage('#000', 270),
            default => null,
        };

        $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
    }
}
