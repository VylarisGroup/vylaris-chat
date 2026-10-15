<?php
declare(strict_types=1);

namespace App\Core;

/** Aufgaben, die nach dem Senden der Antwort laufen (z. B. E-Mail-Versand), damit der Chat nicht wartet. */
final class Deferred
{
    /** @var array<int,callable> */
    private static array $tasks = [];

    public static function add(callable $task): void
    {
        self::$tasks[] = $task;
    }

    public static function run(): void
    {
        if (!self::$tasks) {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            @flush();
        }
        ignore_user_abort(true);
        @set_time_limit(60);
        foreach (self::$tasks as $task) {
            try {
                $task();
            } catch (\Throwable $e) {
                log_error('Deferred-Task fehlgeschlagen: ' . $e->getMessage());
            }
        }
        self::$tasks = [];
    }
}
