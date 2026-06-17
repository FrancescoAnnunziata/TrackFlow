<?php

namespace App\Filament\Resources\Expenses\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
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
                    ->relationship(name: 'client', titleAttribute: 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
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
                FileUpload::make('attachaments')
                    ->label('Allegati')
                    ->multiple()
                    ->image()
                    ->imageResizeMode('contain')
                    ->imageResizeTargetWidth('1920')
                    ->imageResizeTargetHeight('1920')
                    ->imageResizeUpscale(false)
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
     * Salva un allegato. I JPEG/PNG (gia' ridimensionati lato client) vengono
     * salvati cosi' come sono; gli HEIC/HEIF, che i browser non sanno mostrare,
     * vengono convertiti in JPEG (max 1920px) lato server cosi' restano
     * visualizzabili ovunque.
     */
    private static function storeAttachment(TemporaryUploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        $isHeic = in_array($extension, ['heic', 'heif'], true)
            || in_array((string) $file->getMimeType(), ['image/heic', 'image/heif'], true);

        if (! $isHeic) {
            return $file->storeAs(
                self::ATTACHMENT_DIRECTORY,
                (string) Str::ulid().'.'.$extension,
                self::ATTACHMENT_DISK,
            );
        }

        $image = new Imagick($file->getRealPath());
        $image->setIteratorIndex(0);
        self::normalizeOrientation($image);

        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality(85);
        $image->thumbnailImage(1920, 1920, true);

        $path = self::ATTACHMENT_DIRECTORY.'/'.Str::ulid().'.jpg';
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
