<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eccezione al regime fiscale abituale di un emittente per un anno (es. la
 * P.IVA personale in ordinario nel 2025).
 */
class EmittenteRegime extends Model
{
    protected $table = 'emittente_regimi';

    protected $fillable = ['emittente_id', 'anno', 'regime_fiscale'];

    protected $casts = ['anno' => 'integer'];

    public function emittente(): BelongsTo
    {
        return $this->belongsTo(Emittente::class);
    }
}
