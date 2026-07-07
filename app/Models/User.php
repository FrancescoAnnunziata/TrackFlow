<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Cache per-richiesta degli ID cliente associati (vedi allClientIds()).
     *
     * @var array<int, int>|null
     */
    protected ?array $cachedClientIds = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'surname',
        'role',
        'client_id',
        'email',
        'password',
        'must_change_password',
        'hours_reminder_opt_in',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'hours_reminder_opt_in' => 'boolean',
        ];
    }

    public function hours(): HasMany {
        return $this->hasMany(Hour::class);
    }


    public function expenses(): HasMany {
        return $this->hasMany(Expense::class);
    }

    /**
     * Cliente principale/predefinito (usato come default alla creazione dei
     * record — vedi BelongsToClient). L'insieme completo delle associazioni e'
     * dato da clients()/allClientIds().
     */
    public function client(): BelongsTo {
        return $this->belongsTo(Client::class);
    }

    /**
     * Tutti i clienti a cui l'utente e' associato (relazione molti-a-molti).
     */
    public function clients(): BelongsToMany {
        return $this->belongsToMany(Client::class, 'client_user')->withTimestamps();
    }

    /**
     * ID di tutti i clienti a cui l'utente e' associato: unione del cliente
     * principale (client_id) e delle associazioni sul pivot. Memoizzato per
     * evitare query ripetute entro la stessa richiesta (es. nelle policy).
     *
     * @return array<int, int>
     */
    public function allClientIds(): array {
        return $this->cachedClientIds ??= $this->clients()
            ->pluck('clients.id')
            ->when($this->client_id, fn ($ids) => $ids->push($this->client_id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * True se l'utente e' associato al cliente indicato.
     */
    public function belongsToClientId(int|string|null $clientId): bool {
        return $clientId !== null && in_array((int) $clientId, $this->allClientIds(), true);
    }

    public function assignedDevices(): HasMany {
        return $this->hasMany(Device::class, 'assigned_user_id');
    }

    public function openedTickets(): HasMany {
        return $this->hasMany(SupportTicket::class, 'opened_by_user_id');
    }

    public function isAdmin(): bool {
        return $this->role === 'admin';
    }

    public function isClient(): bool {
        return $this->role === 'client';
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->name . ' ' . $this->surname);
    }
}
