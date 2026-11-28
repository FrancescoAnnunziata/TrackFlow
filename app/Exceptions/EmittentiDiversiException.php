<?php

namespace App\Exceptions;

use DomainException;

/**
 * Tentativo di riconciliare un movimento con un documento di un altro emittente
 * (es. un bonifico sul conto di G8 Labs con una fattura della P.IVA personale).
 */
class EmittentiDiversiException extends DomainException {}
