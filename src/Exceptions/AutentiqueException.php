<?php

namespace JeffersonGoncalves\Autentique\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

class AutentiqueException extends RuntimeException
{
    /**
     * @param  array<int, array<string, mixed>>  $errors  GraphQL `errors` entries
     */
    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly ?Response $response = null,
    ) {
        parent::__construct($message, $response?->status() ?? 0);
    }

    public static function fromResponse(Response $response): self
    {
        /** @var array<int, array<string, mixed>> $errors */
        $errors = (array) $response->json('errors', []);

        $message = $errors[0]['message'] ?? $response->json('message') ?? "Autentique request failed with status {$response->status()}.";

        return new self((string) $message, $errors, $response);
    }

    /**
     * Validation messages keyed by field, e.g. ['folder.name' => ['field_required']].
     *
     * @return array<string, array<int, string>>
     */
    public function validation(): array
    {
        $validation = [];

        foreach ($this->errors as $error) {
            foreach ((array) ($error['extensions']['validation'] ?? []) as $field => $messages) {
                $validation[$field] = array_merge($validation[$field] ?? [], (array) $messages);
            }
        }

        return $validation;
    }
}
