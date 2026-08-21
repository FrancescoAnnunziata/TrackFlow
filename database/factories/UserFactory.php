<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Segreto TOTP valido (base32) condiviso da tutti gli utenti di test.
     */
    public const TWO_FACTOR_SECRET = 'ADUMJO5634NPDEKW';

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->firstName(),
            'surname' => fake()->lastName(),
            'role' => 'member',
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // I due fattori sono obbligatori per entrare nel pannello, quindi
            // l'utente di default ce li ha gia' configurati: altrimenti ogni
            // test che fa una GET su una pagina finirebbe sul redirect di
            // setup invece che sulla pagina da verificare. Chi vuole provare
            // proprio quel redirect usa lo stato withoutTwoFactor().
            'app_authentication_secret' => self::TWO_FACTOR_SECRET,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin',
        ]);
    }

    /**
     * Utente che non ha ancora collegato l'app di autenticazione: e' lo stato
     * in cui si trova chiunque al primo accesso dopo l'attivazione della 2FA.
     */
    public function withoutTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'app_authentication_secret' => null,
            'app_authentication_recovery_codes' => null,
        ]);
    }
}
