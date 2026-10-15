<?php
declare(strict_types=1);

namespace App\Core;

/** Liest config/config.php (wird vom Installer erzeugt). */
final class Config
{
    private static ?array $data = null;

    public static function file(): string
    {
        return ROOT_PATH . '/config/config.php';
    }

    public static function exists(): bool
    {
        return is_file(self::file());
    }

    public static function load(): array
    {
        if (self::$data === null) {
            self::$data = self::exists() ? (array) require self::file() : [];
        }
        return self::$data;
    }

    /** Nur für den Installer: frisch geschriebene Konfiguration sofort verwenden. */
    public static function override(array $data): void
    {
        self::$data = $data;
    }

    /** Zugriff per Punktnotation, z. B. "db.host". */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::load();
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
