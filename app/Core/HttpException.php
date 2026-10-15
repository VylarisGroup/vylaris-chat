<?php
declare(strict_types=1);

namespace App\Core;

/** Wird in Controllern geworfen und vom Front Controller in eine Antwort umgewandelt. */
final class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message = '', public readonly array $extra = [])
    {
        parent::__construct($message, $status);
    }
}
