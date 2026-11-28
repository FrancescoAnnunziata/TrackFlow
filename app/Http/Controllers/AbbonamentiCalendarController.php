<?php

namespace App\Http\Controllers;

use App\Models\Abbonamento;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;

/**
 * Calendario dei rinnovi in formato iCalendar, da aggiungere una volta a Google
 * Calendar «da URL»: i rinnovi compaiono lì e si aggiornano da soli.
 *
 * Google scarica il feed dai suoi server, senza la sessione di TrackFlow: per
 * questo la rotta è protetta dalla firma dell'URL e non dal login. Chi ha il
 * link vede nomi e importi degli abbonamenti, niente di più.
 */
class AbbonamentiCalendarController extends Controller
{
    /** Quanto avanti va il calendario: un anno basta a vedere ogni rinnovo. */
    private const MONTHS_AHEAD = 13;

    public function __invoke(): Response
    {
        $until = CarbonImmutable::today()->addMonths(self::MONTHS_AHEAD);
        $stamp = CarbonImmutable::now('UTC')->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//G8Labs//TrackFlow abbonamenti//IT',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:Rinnovi abbonamenti',
            'X-WR-TIMEZONE:Europe/Rome',
        ];

        foreach (Abbonamento::orderBy('next_renewal_on')->get() as $a) {
            $months = Abbonamento::monthsFor($a->cadence);
            $amount = $a->amount !== null ? ' — € '.number_format((float) $a->amount, 2, ',', '.') : '';
            $description = 'Rinnovo '.mb_strtolower($a->cadenceLabel()).'.'.(filled($a->notes) ? ' '.$a->notes : '');

            for ($date = $a->next_renewal_on; $date->lte($until); $date = $date->addMonthsNoOverflow($months)) {
                array_push(
                    $lines,
                    'BEGIN:VEVENT',
                    'UID:abbonamento-'.$a->id.'-'.$date->format('Ymd').'@trackflow',
                    'DTSTAMP:'.$stamp,
                    'DTSTART;VALUE=DATE:'.$date->format('Ymd'),
                    'DTEND;VALUE=DATE:'.$date->addDay()->format('Ymd'),
                    'SUMMARY:'.self::escape('Rinnovo '.$a->name.$amount),
                    'DESCRIPTION:'.self::escape($description),
                    'TRANSP:TRANSPARENT',
                    'END:VEVENT',
                );
            }
        }

        $lines[] = 'END:VCALENDAR';

        $body = implode("\r\n", array_map(self::fold(...), $lines))."\r\n";

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="rinnovi-abbonamenti.ics"',
        ]);
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $text);
    }

    /** RFC 5545: righe di al massimo 75 byte, le successive iniziano con uno spazio. */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = [];
        $current = '';
        foreach (mb_str_split($line) as $char) {
            $limit = $out === [] ? 75 : 74;
            if (strlen($current.$char) > $limit) {
                $out[] = $current;
                $current = '';
            }
            $current .= $char;
        }
        $out[] = $current;

        return implode("\r\n ", $out);
    }
}
