<?php

namespace App\Services\FieldApi;

use RuntimeException;

/** A business error returned to the field API client as {message, error: {code, details}}. */
final class FieldApiError extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function toResponse(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => ['code' => $this->errorCode, 'details' => (object) $this->details],
        ], $this->status);
    }
}
