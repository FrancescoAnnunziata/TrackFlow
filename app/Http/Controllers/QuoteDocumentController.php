<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Quotes\QuoteResource;
use App\Models\Quote;
use App\Models\User;
use App\Notifications\QuoteDecidedNotification;
use App\Services\Quotes\QuotePdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Il preventivo come documento: il cliente lo legge per intero, lo firma
 * disegnando a mano e lo restituisce. Vive fuori dal pannello Filament perché
 * ci si arriva anche dal magic link, senza password.
 */
class QuoteDocumentController extends Controller
{
    /**
     * Dimensione massima accettata per la firma (PNG dal canvas: ~50 KB reali).
     */
    private const MAX_SIGNATURE_BYTES = 2 * 1024 * 1024;

    public function show(Request $request, Quote $quote): Response
    {
        $user = $this->authorizeAccess($request, $quote);

        // Traccia la prima apertura: utile all'emittente per sapere se il
        // cliente ha almeno visto il documento prima di sollecitarlo.
        if ($user->isClient() && $quote->document_viewed_at === null) {
            $quote->forceFill(['document_viewed_at' => now()])->save();
        }

        return response()->view('quotes.document', [
            'quote' => $quote->load(['client', 'user', 'acceptedBy']),
            'user' => $user,
            'isAdmin' => $user->isAdmin(),
            'canSign' => $user->isClient() && $quote->awaitsDecision(),
            'panelUrl' => QuoteResource::getUrl('view', ['record' => $quote]),
        ]);
    }

    /**
     * Il cliente firma: salviamo l'immagine della firma, congeliamo il PDF e
     * avvisiamo entrambe le parti allegandolo.
     */
    public function sign(Request $request, Quote $quote): RedirectResponse
    {
        $user = $this->authorizeSigner($request, $quote);

        $data = $request->validate([
            'signer_name' => ['required', 'string', 'max:120'],
            'signer_role' => ['nullable', 'string', 'max:120'],
            'signature' => ['required', 'string'],
            'accept' => ['accepted'],
        ], [
            'accept.accepted' => 'Devi confermare di accettare il preventivo.',
            'signature.required' => 'Manca la firma: disegnala nel riquadro.',
        ]);

        $png = $this->decodeSignature($data['signature']);
        $path = "quotes/{$quote->getKey()}/firma.png";

        Storage::disk(Quote::DOCUMENTS_DISK)->put($path, $png);

        $quote->forceFill([
            'status' => Quote::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'accepted_by' => $user->getKey(),
            'signature_path' => $path,
            'signer_name' => $data['signer_name'],
            'signer_role' => $data['signer_role'] ?? null,
            'signature_ip' => $request->ip(),
            'signature_user_agent' => substr((string) $request->userAgent(), 0, 500),
            'rejected_at' => null,
            'rejection_reason' => null,
        ])->save();

        // Il PDF va generato adesso, con la firma dentro: è la copia che fa fede
        // e non cambia più anche se il preventivo viene modificato in seguito.
        QuotePdf::store($quote);

        $this->notifyBothParties($quote, $user);

        return redirect()
            ->route('quote.document', $quote)
            ->with('quote_status', 'Preventivo firmato e inviato. Trovi la copia in PDF qui sopra e nella tua email.');
    }

    /**
     * Il cliente rifiuta, con motivo facoltativo.
     */
    public function reject(Request $request, Quote $quote): RedirectResponse
    {
        $user = $this->authorizeSigner($request, $quote);

        $data = $request->validate([
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $quote->forceFill([
            'status' => Quote::STATUS_REJECTED,
            'rejected_at' => now(),
            'rejection_reason' => $data['rejection_reason'] ?? null,
            'accepted_at' => null,
            'accepted_by' => null,
        ])->save();

        $this->notifyBothParties($quote, $user);

        return redirect()
            ->route('quote.document', $quote)
            ->with('quote_status', 'Preventivo rifiutato. L\'emittente è stato avvisato.');
    }

    /**
     * Scarica il PDF: quello congelato se il preventivo è firmato, altrimenti
     * una copia generata al momento (non firmata).
     */
    public function pdf(Request $request, Quote $quote): Response
    {
        $this->authorizeAccess($request, $quote);

        if ($quote->isSigned()) {
            return Storage::disk(Quote::DOCUMENTS_DISK)
                ->download(QuotePdf::ensureStored($quote), $quote->pdfFileName());
        }

        return response()->streamDownload(
            fn () => print QuotePdf::render($quote),
            $quote->pdfFileName(),
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Vedono il documento l'admin (che l'ha emesso) e i referenti del cliente.
     */
    private function authorizeAccess(Request $request, Quote $quote): User
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless(
            $user->isAdmin() || ($user->isClient() && $user->belongsToClientId($quote->client_id)),
            403,
        );

        return $user;
    }

    /**
     * Firma e rifiuto: solo un referente del cliente, e solo finché il
     * preventivo è in attesa di risposta.
     */
    private function authorizeSigner(Request $request, Quote $quote): User
    {
        $user = $this->authorizeAccess($request, $quote);

        abort_unless($user->isClient(), 403, 'Solo un referente del cliente può firmare il preventivo.');
        abort_unless($quote->awaitsDecision(), 409, 'Questo preventivo non è più in attesa di risposta.');

        return $user;
    }

    /**
     * Estrae il PNG dal data URI prodotto dal canvas, rifiutando tutto ciò che
     * non è un'immagine PNG vera.
     */
    private function decodeSignature(string $dataUri): string
    {
        $fallita = fn (string $messaggio) => ValidationException::withMessages(['signature' => $messaggio]);

        if (! preg_match('#^data:image/png;base64,#', $dataUri)) {
            throw $fallita('Firma non valida: riprova a disegnarla.');
        }

        $base64 = substr($dataUri, strlen('data:image/png;base64,'));
        $binario = base64_decode($base64, true);

        if ($binario === false || $binario === '') {
            throw $fallita('Firma non valida: riprova a disegnarla.');
        }

        if (strlen($binario) > self::MAX_SIGNATURE_BYTES) {
            throw $fallita('Firma troppo grande: cancellala e riprova.');
        }

        $info = @getimagesizefromstring($binario);

        if ($info === false || ($info[2] ?? null) !== IMAGETYPE_PNG) {
            throw $fallita('Firma non valida: riprova a disegnarla.');
        }

        return $binario;
    }

    /**
     * Notifica l'esito a emittente e referenti del cliente (entrambi ricevono
     * il PDF in allegato quando il preventivo è firmato).
     */
    private function notifyBothParties(Quote $quote, User $decidedBy): void
    {
        $quote->load(['user', 'client.contacts']);

        Notification::send($quote->user, new QuoteDecidedNotification($quote, $decidedBy));
        Notification::send($quote->client->contacts, new QuoteDecidedNotification($quote, $decidedBy));
    }
}
