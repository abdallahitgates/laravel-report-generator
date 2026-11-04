<?php

namespace Abdalla\ReportGenerator\Query;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A report that cannot run, with a stable code the UI can act on and an
 * explanation a report author can understand.
 */
class QueryException extends RuntimeException
{
    /**
     * @param  string  $errorCode  e.g. UNKNOWN_FIELD, AMBIGUOUS_PATH, FAN_TRAP
     * @param  string|null  $path  where in the definition, e.g. "fields.2.ref"
     * @param  array<string, mixed>  $details  structured data for the UI (path options, ...)
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?string $path = null,
        public readonly array $details = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    /** Laravel renders this automatically when it escapes a controller. */
    public function render(): JsonResponse
    {
        return new JsonResponse($this->toArray(), $this->status);
    }

    public function toArray(): array
    {
        return array_filter([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'path' => $this->path,
            'details' => $this->details ?: null,
        ], fn ($v) => $v !== null);
    }
}
