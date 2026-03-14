<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HourRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date'      => ['required', 'date', 'before_or_equal:today'],
            'hours'     => ['required', 'numeric', 'min:0.5', 'max:24'],
            'client_id' => ['required', 'integer', 'min:1'],
            'notes'     => ['nullable', 'string', 'max:1000'],
            'billable'  => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.required'        => 'La data è obbligatoria.',
            'date.date'            => 'La data non è valida.',
            'date.before_or_equal' => 'La data non può essere nel futuro.',
            'hours.required'       => 'Le ore lavorate sono obbligatorie.',
            'hours.numeric'        => 'Le ore devono essere un numero.',
            'hours.min'            => 'Il valore minimo è 0.5 ore.',
            'hours.max'            => 'Non è possibile registrare più di 24 ore al giorno.',
            'client_id.required'   => 'Seleziona un cliente.',
            'client_id.integer'    => 'Il cliente non è valido.',
            'notes.max'            => 'Le note non possono superare i 1000 caratteri.',
        ];
    }
}
