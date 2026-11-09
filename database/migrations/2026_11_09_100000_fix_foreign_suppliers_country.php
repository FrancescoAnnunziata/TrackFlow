<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corregge il paese dei fornitori esteri, rimasti quasi tutti al default
 * «Italia / IT» della tabella: la pagina Fatture estere li creava senza paese.
 *
 * Il paese è quello della sede che emette la fattura, letto sui PDF allegati
 * (9/11/2026), non quello della partita IVA: Anthropic, PBC fattura da San
 * Francisco anche se espone un numero IVA irlandese (registrazione OSS), e AWS
 * EMEA SARL è lussemburghese anche se ha una sede secondaria a Milano.
 *
 * Si aggancia per partita IVA o, dove manca, per nome esatto: in locale e in
 * produzione gli id coincidono solo finché il database locale è una copia.
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: string, 2: string}> [campo, valore, ISO] */
    private const FORNITORI = [
        ['vat_number', 'IE4276970QH', 'US'],    // Anthropic, PBC — San Francisco
        ['vat_number', 'FR88844156364', 'FR'],  // Meilisearch — Parigi
        ['vat_number', 'ESB87194171', 'ES'],    // SiteGround Spain S.L. — Madrid
        ['vat_number', '84-5071708', 'US'],     // Tailscale US Inc. — San Francisco
        ['vat_number', 'DE294776378', 'DE'],    // AnyDesk Software GmbH — Stoccarda
        ['vat_number', 'EE102865693', 'EE'],    // HeyReach Inc OÜ — Tallinn
        ['vat_number', 'IE3335493BH', 'IE'],    // Twilio Ireland Limited
        ['vat_number', 'GB354412222', 'GB'],    // VEED LIMITED
        ['vat_number', 'EU372079631', 'US'],    // Zapier Inc. — San Francisco
        ['vat_number', 'EU372047974', 'US'],    // Webflow, Inc. — San Francisco
        ['vat_number', '10119840964', 'LU'],    // Amazon Web Services EMEA SARL
        ['name', 'QR.io', 'US'],                // Beaverton, Oregon
        ['name', 'PressReader International Limited', 'IE'], // Dublino
        ['name', 'VIMEO.COM, INC.', 'US'],      // New York
        ['name', 'Wispr AI, Inc. (dba Wispr Flow)', 'US'],
        ['name', 'Descript Inc', 'US'],
        ['name', 'Booking.com', 'NL'],          // Amsterdam
        ['name', 'CalendarBridge', 'US'],       // Miami
        ['name', 'Hong Kong Geekom Electronics Co. Limited', 'HK'],
    ];

    private const NOMI = [
        'US' => 'Stati Uniti', 'FR' => 'Francia', 'ES' => 'Spagna', 'DE' => 'Germania',
        'EE' => 'Estonia', 'IE' => 'Irlanda', 'GB' => 'Regno Unito', 'LU' => 'Lussemburgo',
        'NL' => 'Paesi Bassi', 'HK' => 'Hong Kong',
    ];

    public function up(): void
    {
        foreach (self::FORNITORI as [$campo, $valore, $iso]) {
            DB::table('suppliers')
                ->where($campo, $valore)
                ->update(['country_iso' => $iso, 'country' => self::NOMI[$iso]]);
        }
    }

    public function down(): void
    {
        // Nessun ritorno: il valore precedente era il default sbagliato.
    }
};
