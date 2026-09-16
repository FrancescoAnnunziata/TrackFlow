<?php

namespace App\Filament\Resources\Quotes\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\User;
use App\Notifications\QuoteSubmittedNotification;
use App\Services\Quotes\QuotePdf;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Notification;

class ViewQuote extends ViewRecord
{
    protected static string $resource = QuoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->signAction(),
            $this->openDocumentAction(),
            $this->downloadPdfAction(),
            $this->sendAction(),
            $this->resendAction(),
            $this->copyLinkAction(),
            $this->recordAcceptanceAction(),
            $this->uploadSignedCopyAction(),
            $this->generateInvoiceAction(),
            EditAction::make()
                ->visible(fn (Quote $record): bool => auth()->user()->isAdmin()),
            DeleteAction::make()
                ->visible(fn (Quote $record): bool => auth()->user()->isAdmin()),
        ];
    }

    /**
     * Admin: invia il preventivo ai referenti del cliente con magic link.
     */
    private function sendAction(): Action
    {
        return Action::make('send')
            ->label('Invia al cliente')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->visible(fn (Quote $record): bool => auth()->user()->isAdmin() && $record->status === Quote::STATUS_DRAFT)
            ->requiresConfirmation()
            ->modalDescription('Invia il preventivo via email a tutti i referenti del cliente, con un link di accesso per approvarlo.')
            ->action(fn (Quote $record) => $this->dispatchToClient($record));
    }

    /**
     * Admin: reinvia un preventivo già inviato (nuovo link, conteggio solleciti azzerato).
     */
    private function resendAction(): Action
    {
        return Action::make('resend')
            ->label('Reinvia al cliente')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (Quote $record): bool => auth()->user()->isAdmin() && $record->status === Quote::STATUS_SENT)
            ->requiresConfirmation()
            ->modalDescription('Reinvia il preventivo ai referenti del cliente con un nuovo link di accesso. La data di invio e il conteggio dei solleciti ripartono da zero.')
            ->action(fn (Quote $record) => $this->dispatchToClient($record, resend: true));
    }

    /**
     * Invia (o reinvia) il preventivo: magic link ai referenti, copia per
     * conoscenza all'emittente, e (re)imposta stato/data invio/solleciti.
     */
    private function dispatchToClient(Quote $record, bool $resend = false): void
    {
        $recipients = $record->client->contacts;

        if ($recipients->isEmpty()) {
            FilamentNotification::make()
                ->warning()
                ->title('Nessun referente')
                ->body('Il cliente non ha utenti referente a cui inviare il preventivo. Creane uno dalla sezione Utenti.')
                ->send();

            return;
        }

        $record->update([
            'status' => Quote::STATUS_SENT,
            'sent_at' => now(),
            'reminders_sent' => 0,
        ]);

        Notification::send($recipients, new QuoteSubmittedNotification($record));
        Notification::send($record->user, new QuoteSubmittedNotification($record));

        FilamentNotification::make()
            ->success()
            ->title($resend ? 'Preventivo reinviato' : 'Preventivo inviato')
            ->body('Email inviata a '.$recipients->count().' referente/i.')
            ->send();
    }

    /**
     * Cliente: l'accettazione avviene sul documento, firmandolo a mano — non
     * più con un bottone da qui.
     */
    private function signAction(): Action
    {
        return Action::make('sign')
            ->label('Leggi e firma')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('success')
            ->visible(fn (Quote $record): bool => auth()->user()->isClient() && $record->status === Quote::STATUS_SENT)
            ->url(fn (Quote $record): string => $record->documentUrl());
    }

    /**
     * Apre il documento come lo vede il cliente.
     */
    private function openDocumentAction(): Action
    {
        return Action::make('openDocument')
            ->label('Apri il documento')
            ->icon(Heroicon::OutlinedDocumentMagnifyingGlass)
            ->color('gray')
            ->visible(fn (Quote $record): bool => ! (auth()->user()->isClient() && $record->status === Quote::STATUS_SENT))
            ->url(fn (Quote $record): string => $record->documentUrl());
    }

    private function downloadPdfAction(): Action
    {
        return Action::make('downloadPdf')
            ->label('Scarica il PDF')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->url(fn (Quote $record): string => route('quote.pdf', $record));
    }

    /**
     * Admin: i link di firma da copiare e incollare a mano — in un'email di
     * sollecito, in chat — senza passare da "Reinvia al cliente", che invece
     * rimanda l'email e azzera i solleciti.
     *
     * Uno per referente e non uno solo: il link fa da autenticazione (vedi
     * QuoteMagicAccess), quindi chi lo apre firma col nome dell'intestatario
     * del link. Mandare a Tizio il link di Caio farebbe risultare Caio come
     * firmatario.
     */
    private function copyLinkAction(): Action
    {
        return Action::make('copyLink')
            ->label('Copia link')
            ->icon(Heroicon::OutlinedLink)
            ->color('gray')
            ->visible(fn (Quote $record): bool => auth()->user()->isAdmin() && $record->status === Quote::STATUS_SENT)
            ->modalHeading('Link per la firma')
            ->modalDescription(fn (): string => 'Un link per ogni referente: chi lo apre entra come quella persona, ed è a suo nome che risulterà la firma. Valido fino al '
                .now()->addDays(Quote::MAGIC_LINK_DAYS)->format('d/m/Y').' (ogni volta che apri questa finestra i link ripartono da oggi).')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Chiudi')
            ->schema(fn (Quote $record): array => $this->linkEntries($record));
    }

    /**
     * @return array<int, TextEntry|Text>
     */
    private function linkEntries(Quote $record): array
    {
        $contacts = $record->client->contacts;

        if ($contacts->isEmpty()) {
            return [
                Text::make('Il cliente non ha referenti, quindi non c\'è nessuno a cui intestare il link. Creane uno dalla sezione Utenti e torna qui.'),
            ];
        }

        return $contacts
            ->map(fn (User $contact): TextEntry => TextEntry::make('magic_link_'.$contact->getKey())
                ->label($contact->name.' — '.$contact->email)
                ->state($record->magicLinkFor($contact))
                ->copyable()
                ->copyMessage('Link copiato')
                ->extraAttributes(['class' => 'break-all']))
            ->all();
    }

    /**
     * Admin: registra un'accettazione arrivata fuori da TrackFlow — per email,
     * a voce, o su un foglio firmato.
     *
     * Serve perché il link con firma online non va sempre bene: capita che il
     * cliente autorizzi per iscritto e rimandi la formalizzazione a un cartaceo.
     * Senza questa azione il preventivo resterebbe «Inviato» e continuerebbe a
     * ricevere i solleciti automatici di firma (vedi SendQuoteReminders).
     *
     * Resta scritto chi ha autorizzato, come, e chi l'ha registrata: un'accettazione
     * messa a mano non deve poter passare per una firma del cliente.
     */
    private function recordAcceptanceAction(): Action
    {
        return Action::make('recordAcceptance')
            ->label('Registra accettazione')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->visible(fn (Quote $record): bool => auth()->user()->isAdmin() && $record->status === Quote::STATUS_SENT)
            ->modalHeading('Registra l\'accettazione del cliente')
            ->modalDescription('Da usare quando il cliente ha accettato fuori da TrackFlow. Allega la prova: è quella che vale, non il pulsante.')
            ->modalSubmitActionLabel('Registra')
            ->schema([
                Select::make('acceptance_method')
                    ->label('Come è arrivata')
                    ->options(Quote::acceptanceMethodOptions())
                    ->default(Quote::METHOD_EMAIL)
                    ->required()
                    ->selectablePlaceholder(false),
                TextInput::make('acceptance_author')
                    ->label('Chi ha autorizzato')
                    ->placeholder('Nome e cognome')
                    ->required()
                    ->maxLength(120),
                TextInput::make('acceptance_author_role')
                    ->label('In che qualità')
                    ->placeholder('es. Legale rappresentante, oppure: per conto del legale rappresentante')
                    ->maxLength(120),
                DateTimePicker::make('accepted_at')
                    ->label('Accettato il')
                    ->seconds(false)
                    ->default(now())
                    ->maxDate(now())
                    ->required(),
                FileUpload::make('acceptance_evidence_path')
                    ->label('Prova da conservare')
                    ->helperText('L\'email salvata in PDF, o una foto del foglio. Resta sul disco privato, non è pubblica.')
                    ->disk(Quote::DOCUMENTS_DISK)
                    ->directory(fn (Quote $record): string => 'quotes/'.$record->getKey())
                    ->visibility('private')
                    ->acceptedFileTypes(['application/pdf', 'image/png', 'image/jpeg', 'message/rfc822'])
                    ->maxSize(10240),
                Textarea::make('acceptance_note')
                    ->label('Nota')
                    ->placeholder('Es. accettato via email, formalizzazione su carta da far firmare al legale rappresentante.')
                    ->rows(3),
            ])
            ->action(function (Quote $record, array $data) {
                $record->forceFill([
                    'status' => Quote::STATUS_ACCEPTED,
                    'accepted_at' => $data['accepted_at'],
                    // Nessun referente ha firmato: chi ha autorizzato sta nei
                    // campi di testo, e accepted_by resta vuoto apposta.
                    'accepted_by' => null,
                    'acceptance_method' => $data['acceptance_method'],
                    'acceptance_author' => $data['acceptance_author'],
                    'acceptance_author_role' => $data['acceptance_author_role'] ?? null,
                    'acceptance_recorded_by' => auth()->id(),
                    'acceptance_recorded_at' => now(),
                    'acceptance_evidence_path' => $data['acceptance_evidence_path'] ?? null,
                    'acceptance_note' => $data['acceptance_note'] ?? null,
                    'rejected_at' => null,
                    'rejection_reason' => null,
                ])->save();

                // Congela il PDF adesso, con scritto dentro come è stata
                // accettata: è la copia da mandare al cliente e da archiviare.
                QuotePdf::store($record);

                FilamentNotification::make()
                    ->success()
                    ->title('Accettazione registrata')
                    ->body($record->needsFormalization()
                        ? 'Il preventivo è accettato. Resta da formalizzare: quando torna il documento firmato, caricalo con «Carica copia firmata».'
                        : 'Il preventivo è accettato.')
                    ->send();
            });
    }

    /**
     * Admin: carica la scansione del preventivo firmato su carta. Da lì in poi
     * è quel file la copia che fa fede, ed è quello che esce da «Scarica il PDF».
     */
    private function uploadSignedCopyAction(): Action
    {
        return Action::make('uploadSignedCopy')
            ->label(fn (Quote $record): string => $record->hasSignedCopy() ? 'Sostituisci la copia firmata' : 'Carica copia firmata')
            ->icon(Heroicon::OutlinedPaperClip)
            ->color(fn (Quote $record): string => $record->needsFormalization() ? 'warning' : 'gray')
            ->visible(fn (Quote $record): bool => auth()->user()->isAdmin() && $record->isAccepted())
            ->modalHeading('Copia firmata su carta')
            ->modalDescription('La scansione del documento firmato dal cliente. Una volta caricata è lei la copia che fa fede: «Scarica il PDF» restituisce questa.')
            ->modalSubmitActionLabel('Carica')
            ->schema([
                FileUpload::make('signed_copy_path')
                    ->label('Documento firmato')
                    ->disk(Quote::DOCUMENTS_DISK)
                    ->directory(fn (Quote $record): string => 'quotes/'.$record->getKey())
                    ->visibility('private')
                    ->acceptedFileTypes(['application/pdf', 'image/png', 'image/jpeg'])
                    ->maxSize(20480)
                    ->required(),
                TextInput::make('acceptance_author')
                    ->label('Chi ha firmato')
                    ->placeholder('Nome e cognome')
                    ->maxLength(120)
                    ->default(fn (Quote $record): ?string => $record->acceptance_author),
                TextInput::make('acceptance_author_role')
                    ->label('In che qualità')
                    ->placeholder('es. Legale rappresentante')
                    ->maxLength(120)
                    ->default(fn (Quote $record): ?string => $record->acceptance_author_role),
            ])
            ->action(function (Quote $record, array $data) {
                $record->forceFill([
                    'signed_copy_path' => $data['signed_copy_path'],
                    'acceptance_author' => $data['acceptance_author'] ?: $record->acceptance_author,
                    'acceptance_author_role' => $data['acceptance_author_role'] ?: $record->acceptance_author_role,
                ])->save();

                FilamentNotification::make()
                    ->success()
                    ->title('Copia firmata caricata')
                    ->body('È questa ora la copia che fa fede: «Scarica il PDF» restituisce la scansione.')
                    ->send();
            });
    }

    /**
     * Admin: genera una fattura in bozza dal preventivo accettato.
     */
    private function generateInvoiceAction(): Action
    {
        return Action::make('generateInvoice')
            ->label('Genera fattura')
            ->icon(Heroicon::OutlinedDocumentText)
            ->color('primary')
            ->visible(fn (Quote $record): bool => auth()->user()->isAdmin()
                && $record->status === Quote::STATUS_ACCEPTED
                && $record->invoice_id === null)
            ->requiresConfirmation()
            ->modalDescription('Crea una fattura in bozza con stesso cliente, tariffa e IVA. Le ore effettive le aggancerai poi alla fattura.')
            ->action(function (Quote $record) {
                $invoice = Invoice::create([
                    'user_id' => auth()->id(),
                    'client_id' => $record->client_id,
                    // Numero assegnato da FIC all'invio (non inventato qui).
                    'issue_date' => now(),
                    'period_from' => now()->startOfMonth(),
                    'period_to' => now()->endOfMonth(),
                    'hourly_rate' => $record->hourly_rate,
                    'vat_rate' => $record->vat_rate,
                    'status' => 'draft',
                    'notes' => trim("Da preventivo {$record->number}\n".(string) $record->description),
                ]);

                $record->update([
                    'status' => Quote::STATUS_INVOICED,
                    'invoice_id' => $invoice->getKey(),
                ]);

                FilamentNotification::make()
                    ->success()
                    ->title('Fattura creata')
                    ->body("Fattura {$invoice->number} generata in bozza.")
                    ->send();

                return redirect(InvoiceResource::getUrl('edit', ['record' => $invoice]));
            });
    }
}
