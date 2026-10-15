<?php
declare(strict_types=1);

namespace App\Core;

/** Minimaler Template-Renderer. Variablen werden im Template per e() escaped. */
final class View
{
    public static function render(string $template, array $vars = [], ?string $layout = 'layouts/main'): string
    {
        $content = self::capture($template, $vars);
        if ($layout === null) {
            return $content;
        }
        return self::capture($layout, $vars + ['content' => $content]);
    }

    private static function capture(string $template, array $vars): string
    {
        $file = ROOT_PATH . '/app/Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Template fehlt: ' . $template);
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
