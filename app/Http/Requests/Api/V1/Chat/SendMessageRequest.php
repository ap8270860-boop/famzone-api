<?php

namespace App\Http\Requests\Api\V1\Chat;

use App\Models\Message;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/conversations/{uuid}/messages
 *
 * Method names here are checked against Illuminate\Http\Request before being
 * added: a Form Request extends it, so a name that collides with a framework
 * method is a fatal signature error at class load — invisible to php -l and
 * to every test that does not hit this exact endpoint.
 */
class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             | Generated on the device before the message has a server id.
             |
             | Required, not optional. It is what makes a retry safe, and a
             | client that omits it is a client that will create duplicates
             | the first time a request times out — better to fail loudly in
             | development than quietly in production.
             */
            'client_uuid' => ['required', 'uuid'],

            // Only text is accepted until the media phase. The column already
            // knows about the other types; the endpoint does not yet.
            'type' => ['sometimes', Rule::in([Message::TYPE_TEXT])],

            'body' => ['required', 'string', 'min:1', 'max:'.Message::BODY_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Write something first.',
            'body.max' => 'That message is too long.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $body = $this->input('body');

        $this->merge([
            'type' => $this->input('type', Message::TYPE_TEXT),
            // Trimmed before the length check, so trailing newlines from a
            // multiline composer cannot push a message over the limit and a
            // message of pure whitespace fails min:1 rather than arriving as
            // an empty bubble.
            'body' => is_string($body) ? trim($body) : $body,
        ]);
    }

    /**
     * @return array{client_uuid: string, type: string, body: ?string}
     */
    public function payload(): array
    {
        return [
            'client_uuid' => (string) $this->input('client_uuid'),
            'type' => (string) $this->input('type'),
            'body' => $this->input('body'),
        ];
    }
}
