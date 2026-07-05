<?php

namespace App\Services\Fic;

use RuntimeException;

/**
 * Errore lato Fatture in Cloud (mancata connessione, token, o risposta API
 * non valida). Il messaggio è pensato per essere mostrato all'utente.
 */
class FicException extends RuntimeException {}
