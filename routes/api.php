<?php

use App\Http\Controllers\Api\SubscriptionInvoiceController;
use App\Http\Middleware\VerifyBillingApiSignature;
use Illuminate\Support\Facades\Route;

/*
| API in ingresso. Un solo chiamante previsto (personal-ticketing) e un solo
| scopo: trasformare un abbonamento incassato in una fattura in bozza. Non
| c'è autenticazione a sessione né a utente — vale la firma HMAC del corpo
| (vedi VerifyBillingApiSignature), e ogni chiamata finisce in api_request_logs.
|
| L'endpoint NON parla con Fatture in Cloud: crea la bozza e si ferma. L'invio
| a FIC e al SDI resta un gesto manuale dal pannello.
|
| Documentazione per chi integra: docs/api-abbonamenti.md
*/
Route::middleware([VerifyBillingApiSignature::class, 'throttle:60,1'])
    ->prefix('billing')
    ->name('api.billing.')
    ->group(function (): void {
        Route::post('/subscription-invoices', SubscriptionInvoiceController::class)
            ->name('subscription-invoices.store');
    });
