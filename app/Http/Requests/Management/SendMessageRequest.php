<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Texto obligatorio sólo para mensajes de texto; ubicación, voto, reacción (quitar) y contacto van sin texto
            'message' => 'nullable|string|required_if:type,text',
            'type'    => 'nullable|string|in:text,sticker,location,contact,reaction,poll,poll_vote',
        ];
    }
}
