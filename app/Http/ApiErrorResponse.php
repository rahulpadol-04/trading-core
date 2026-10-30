<?php

declare(strict_types=1);

namespace App\Http;

use App\Exceptions\Domain\DomainException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every API error as {"error": {"code", "message", "details"?}}.
 */
final class ApiErrorResponse
{
    public static function from(Throwable $e): JsonResponse
    {
        [$status, $code, $message, $details, $headers] = match (true) {
            $e instanceof ValidationException => [422, 'VALIDATION_FAILED', 'The given data was invalid.', $e->errors(), []],
            $e instanceof AuthenticationException => [401, 'UNAUTHENTICATED', 'Authentication is required.', [], []],
            $e instanceof DomainException => [$e->status(), $e->errorCode(), $e->getMessage(), $e->details(), []],
            $e instanceof HttpExceptionInterface => self::fromHttp($e),
            default => [500, 'INTERNAL_ERROR', config('app.debug') ? $e->getMessage() : 'Unexpected error.', [], []],
        };

        $body = ['error' => ['code' => $code, 'message' => $message]];

        if ($details !== []) {
            $body['error']['details'] = $details;
        }

        return new JsonResponse($body, $status, $headers);
    }

    /**
     * @return array{int, string, string, array<string, mixed>, array<string, string>}
     */
    private static function fromHttp(HttpExceptionInterface $e): array
    {
        $status = $e->getStatusCode();

        $code = match ($status) {
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            429 => 'RATE_LIMITED',
            default => 'HTTP_'.$status,
        };

        $message = match ($status) {
            404 => 'Resource not found.',
            429 => 'Too many requests.',
            default => $e->getMessage() !== '' ? $e->getMessage() : 'Request failed.',
        };

        return [$status, $code, $message, [], $e->getHeaders()];
    }
}
