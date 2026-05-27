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
            'date'          => ['required', 'date', 'before_or_equal:today'],
            'hours_part'    => ['required', 'integer', 'min:0', 'max:23'],
            'minutes_part'  => ['required', 'integer', 'min:0', 'max:59'],
            'client_ids'    => ['required', 'array', 'min:1'],
            'client_ids.*'  => ['integer', 'exists:clients,id'],
            'notes'         => ['nullable', 'string', 'max:1000'],
            'billable'      => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.required'         => 'La data è obbligatoria.',
            'date.date'             => 'La data non è valida.',
            'date.before_or_equal'  => 'La data non può essere nel futuro.',
            'hours_part.required'   => 'Le ore sono obbligatorie.',
            'hours_part.integer'    => 'Le ore devono essere un numero intero.',
            'hours_part.min'        => 'Le ore non possono essere negative.',
            'hours_part.max'        => 'Le ore non possono superare 23.',
            'minutes_part.required' => 'I minuti sono obbligatori.',
            'minutes_part.integer'  => 'I minuti devono essere un numero intero.',
            'minutes_part.min'      => 'I minuti non possono essere negativi.',
            'minutes_part.max'      => 'I minuti non possono superare 59.',
            'client_ids.required'   => 'Seleziona almeno un cliente.',
            'client_ids.array'      => 'I clienti non sono validi.',
            'client_ids.min'        => 'Seleziona almeno un cliente.',
            'client_ids.*.integer'  => 'Il cliente non è valido.',
            'client_ids.*.exists'   => 'Il cliente selezionato non esiste.',
            'notes.max'             => 'Le note non possono superare i 1000 caratteri.',
        ];
    }
}
