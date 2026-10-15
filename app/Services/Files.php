<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Response;
use App\Models\User;

/**
 * Datei-Uploads, Auslieferung mit Berechtigungsprüfung, Avatare und Logo.
 * Dateien liegen im Speicherverzeichnis unter zufälligen Namen ohne Endung und werden nie direkt vom Webserver ausgeliefert.
 */
final class Files
{
    private const IMAGE_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    private const IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    private const BLOCKED_EXTS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar', 'phps', 'inc', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh',
        'bat', 'cmd', 'com', 'exe', 'msi', 'dll', 'scr', 'vbs', 'vbe', 'wsf', 'jar', 'htaccess', 'htpasswd', 'ini', 'asp', 'aspx', 'jsp', 'jspx',
    ];

    public static function maxBytes(): int
    {
        $configured = max(1, Settings::int('max_upload_mb')) * 1048576;
        return min($configured, self::iniBytes('upload_max_filesize') ?: $configured, self::iniBytes('post_max_size') ?: $configured);
    }

    public static function iniBytes(string $key): int
    {
        $v = trim((string) ini_get($key));
        if ($v === '') {
            return 0;
        }
        $unit = strtolower(substr($v, -1));
        $n = (int) $v;
        return match ($unit) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }

    // ---------------------------------------------------------------- Pfade

    public static function path(string $storageName): string
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $storageName)) {
            throw new \InvalidArgumentException('Ungültiger Speichername.');
        }
        return storage_path('uploads/' . substr($storageName, 0, 2) . '/' . $storageName);
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Speicherverzeichnis kann nicht angelegt werden: ' . basename($dir));
        }
    }

    public static function sanitizeName(string $name): string
    {
        $name = str_replace(['\\', '/'], '/', $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F<>:"|?*]+/u', '', $name) ?? '';
        $name = trim($name, " .\t\n\r");
        if ($name === '') {
            $name = 'datei';
        }
        if (mb_strlen($name) > 150) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 140) . ($ext !== '' ? '.' . mb_substr($ext, 0, 8) : '');
        }
        return $name;
    }

    private static function detectMime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        return (string) ($finfo->file($path) ?: 'application/octet-stream');
    }

    // --------------------------------------------------------------- Upload

    public static function upload(int $userId, int $conversationId, array $file): array
    {
        $uploader = User::find($userId);
        if ($uploader) {
            Privacy::assertNotRestricted($uploader);
        }
        Chat::requireMember($conversationId, $userId);
        if (!RateLimiter::hit('upload', (string) $userId, 40, 600)) {
            throw new HttpException(429, 'Zu viele Uploads. Bitte warte einen Moment.');
        }
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new HttpException(422, match ($err) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Datei ist grösser als erlaubt (' . human_size(self::maxBytes()) . ').',
                UPLOAD_ERR_NO_FILE => 'Es wurde keine Datei übertragen.',
                default => 'Der Upload ist fehlgeschlagen. Bitte versuche es erneut.',
            });
        }
        $tmp = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            throw new HttpException(400, 'Ungültiger Upload.');
        }
        $size = (int) filesize($tmp);
        if ($size <= 0) {
            throw new HttpException(422, 'Die Datei ist leer.');
        }
        if ($size > self::maxBytes()) {
            throw new HttpException(413, 'Die Datei ist grösser als erlaubt (' . human_size(self::maxBytes()) . ').');
        }

        $original = self::sanitizeName((string) ($file['name'] ?? 'datei'));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (str_starts_with($original, '.') || in_array($ext, self::BLOCKED_EXTS, true)) {
            throw new HttpException(422, 'Dieser Dateityp ist aus Sicherheitsgründen nicht erlaubt.');
        }
        // Mehrfachendungen wie "bild.php.jpg" ebenfalls abfangen
        foreach (explode('.', strtolower($original)) as $part) {
            if (in_array($part, ['php', 'phtml', 'phar', 'pht'], true)) {
                throw new HttpException(422, 'Dieser Dateiname ist aus Sicherheitsgründen nicht erlaubt.');
            }
        }

        $mime = self::detectMime($tmp);
        $isImage = isset(self::IMAGE_MIMES[$mime]);
        $width = $height = null;
        if (in_array($ext, self::IMAGE_EXTS, true) && !$isImage) {
            throw new HttpException(422, 'Der Dateiinhalt passt nicht zur Dateiendung.');
        }
        if ($isImage) {
            $info = @getimagesize($tmp);
            if ($info === false) {
                throw new HttpException(422, 'Das Bild ist beschädigt oder ungültig.');
            }
            [$width, $height] = [(int) $info[0], (int) $info[1]];
            if ($width > 12000 || $height > 12000 || $width * $height > 80000000) {
                throw new HttpException(422, 'Das Bild hat zu viele Pixel.');
            }
        } elseif (str_starts_with($mime, 'text/x-php') || $mime === 'application/x-httpd-php' || str_contains($mime, 'x-msdownload') || $mime === 'application/x-executable') {
            throw new HttpException(422, 'Dieser Dateityp ist aus Sicherheitsgründen nicht erlaubt.');
        }

        $storage = bin2hex(random_bytes(20));
        $dest = self::path($storage);
        self::ensureDir(dirname($dest));
        if (!move_uploaded_file($tmp, $dest)) {
            throw new HttpException(500, 'Die Datei konnte nicht gespeichert werden.');
        }
        @chmod($dest, 0640);

        $id = Database::insert(
            'INSERT INTO message_attachments (message_id, conversation_id, uploader_id, storage_name, original_name, mime_type, size_bytes, is_image, width, height, created_at)
             VALUES (NULL,?,?,?,?,?,?,?,?,?,?)',
            [$conversationId, $userId, $storage, $original, $isImage ? $mime : ($mime ?: 'application/octet-stream'), $size, $isImage ? 1 : 0, $width, $height, now()]
        );
        return Messages::attachmentView(Database::one('SELECT * FROM message_attachments WHERE id = ?', [$id]) ?? []);
    }

    /** Kopiert einen Anhang für weitergeleitete Nachrichten. */
    public static function duplicate(array $att, int $newMessageId, int $targetConversationId, int $userId): void
    {
        $src = self::path((string) $att['storage_name']);
        if (!is_file($src)) {
            return;
        }
        $storage = bin2hex(random_bytes(20));
        $dest = self::path($storage);
        self::ensureDir(dirname($dest));
        if (!@copy($src, $dest)) {
            return;
        }
        @chmod($dest, 0640);
        Database::exec(
            'INSERT INTO message_attachments (message_id, conversation_id, uploader_id, storage_name, original_name, mime_type, size_bytes, is_image, width, height, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$newMessageId, $targetConversationId, $userId, $storage, $att['original_name'], $att['mime_type'], $att['size_bytes'], $att['is_image'], $att['width'], $att['height'], now()]
        );
    }

    public static function unlinkStored(string $storageName): void
    {
        try {
            $p = self::path($storageName);
        } catch (\InvalidArgumentException) {
            return;
        }
        @unlink($p);
        foreach (glob(storage_path('cache/thumbs/' . $storageName . '.*')) ?: [] as $t) {
            @unlink($t);
        }
    }

    // -------------------------------------------------------- Auslieferung

    public static function serve(int $userId, int $attachmentId, bool $download, bool $thumb): Response
    {
        $att = Database::one('SELECT * FROM message_attachments WHERE id = ?', [$attachmentId]);
        if (!$att) {
            throw new HttpException(404, 'Datei nicht gefunden.');
        }
        $member = Chat::membership((int) $att['conversation_id'], $userId);
        if (!$member) {
            throw new HttpException(404, 'Datei nicht gefunden.');
        }
        if ($att['message_id'] === null) {
            // Noch nicht gesendeter Anhang: nur der Uploader
            if ((int) $att['uploader_id'] !== $userId) {
                throw new HttpException(404, 'Datei nicht gefunden.');
            }
        } else {
            $msg = Database::one('SELECT id, deleted_at FROM messages WHERE id = ?', [$att['message_id']]);
            if (!$msg || $msg['deleted_at'] !== null || (int) $msg['id'] <= (int) $member['visible_from_message_id']) {
                throw new HttpException(404, 'Datei nicht gefunden.');
            }
        }
        $path = self::path((string) $att['storage_name']);
        if (!is_file($path)) {
            throw new HttpException(404, 'Datei nicht gefunden.');
        }

        $etag = '"' . $att['storage_name'] . ($thumb ? '-t' : '') . '"';
        $headers = [
            'ETag' => $etag,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            return new Response(304, '', $headers);
        }

        $inlineImage = (bool) $att['is_image'] && isset(self::IMAGE_MIMES[$att['mime_type']]) && !$download;
        if ($inlineImage && $thumb) {
            $tp = self::thumbnail($att, $path);
            if ($tp !== null) {
                return Response::file($tp['path'], $headers + ['Content-Type' => $tp['mime'], 'Content-Disposition' => 'inline']);
            }
        }
        $encoded = rawurlencode((string) $att['original_name']);
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $att['original_name']) ?? 'datei';
        if ($inlineImage) {
            return Response::file($path, $headers + [
                'Content-Type' => (string) $att['mime_type'],
                'Content-Disposition' => "inline; filename=\"$ascii\"; filename*=UTF-8''$encoded",
            ]);
        }
        return Response::file($path, $headers + [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => "attachment; filename=\"$ascii\"; filename*=UTF-8''$encoded",
        ]);
    }

    /** @return array{path:string,mime:string}|null */
    private static function thumbnail(array $att, string $src): ?array
    {
        if (!extension_loaded('gd') || $att['mime_type'] === 'image/gif') {
            return null;
        }
        $w = (int) $att['width'];
        $h = (int) $att['height'];
        if ($w <= 0 || $h <= 0 || $w * $h > 25000000) {
            return null;
        }
        if (max($w, $h) <= 480) {
            return null;
        }
        $ext = self::IMAGE_MIMES[$att['mime_type']];
        $dir = storage_path('cache/thumbs');
        self::ensureDir($dir);
        $out = $dir . '/' . $att['storage_name'] . '.' . $ext;
        if (is_file($out)) {
            return ['path' => $out, 'mime' => (string) $att['mime_type']];
        }
        $img = match ($att['mime_type']) {
            'image/jpeg' => @imagecreatefromjpeg($src),
            'image/png' => @imagecreatefrompng($src),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
            default => false,
        };
        if (!$img) {
            return null;
        }
        $scale = 480 / max($w, $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $ok = match ($ext) {
            'jpg' => imagejpeg($dst, $out, 82),
            'png' => imagepng($dst, $out, 6),
            'webp' => imagewebp($dst, $out, 82),
            default => false,
        };
        imagedestroy($img);
        imagedestroy($dst);
        return $ok ? ['path' => $out, 'mime' => (string) $att['mime_type']] : null;
    }

    // ----------------------------------------------- Avatare und Firmenlogo

    /**
     * Bild prüfen und neu kodieren (entfernt Metadaten und eingebettete Nutzdaten).
     * @param 'square'|'fit' $mode
     * @return string Dateiname im Verzeichnis storage/<dir>
     */
    public static function saveProfileImage(array $file, string $dir, string $mode = 'square', int $size = 256): string
    {
        if (!extension_loaded('gd')) {
            throw new HttpException(500, 'Die PHP-Erweiterung GD fehlt.');
        }
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new HttpException(422, 'Der Upload ist fehlgeschlagen.');
        }
        $tmp = (string) $file['tmp_name'];
        if (filesize($tmp) > 5 * 1048576) {
            throw new HttpException(413, 'Das Bild darf höchstens 5 MB gross sein.');
        }
        $mime = self::detectMime($tmp);
        $info = @getimagesize($tmp);
        if (!isset(self::IMAGE_MIMES[$mime]) || $info === false || $info[0] * $info[1] > 40000000) {
            throw new HttpException(422, 'Bitte lade ein gültiges Bild (JPG, PNG, GIF oder WebP) hoch.');
        }
        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmp),
            'image/png' => @imagecreatefrompng($tmp),
            'image/gif' => @imagecreatefromgif($tmp),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
            default => false,
        };
        if (!$src) {
            throw new HttpException(422, 'Das Bild konnte nicht verarbeitet werden.');
        }
        $w = imagesx($src);
        $h = imagesy($src);
        if ($mode === 'square') {
            $side = min($w, $h);
            $sx = intdiv($w - $side, 2);
            $sy = intdiv($h - $side, 2);
            $dst = imagecreatetruecolor($size, $size);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $size, $size, $side, $side);
        } else {
            $scale = min(1, $size / max($w, $h));
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        }
        $folder = storage_path($dir);
        self::ensureDir($folder);
        $name = bin2hex(random_bytes(12)) . '.png';
        $ok = imagepng($dst, $folder . '/' . $name, 6);
        imagedestroy($src);
        imagedestroy($dst);
        if (!$ok) {
            throw new HttpException(500, 'Das Bild konnte nicht gespeichert werden.');
        }
        @chmod($folder . '/' . $name, 0640);
        return $name;
    }

    public static function serveProfileImage(?string $dir, ?string $name): Response
    {
        if ($dir === null || !$name || !preg_match('/^[a-f0-9]{24}\.png$/', $name)) {
            throw new HttpException(404, 'Bild nicht gefunden.');
        }
        $path = storage_path($dir . '/' . $name);
        if (!is_file($path)) {
            throw new HttpException(404, 'Bild nicht gefunden.');
        }
        $etag = '"' . substr($name, 0, 24) . '"';
        $headers = [
            'Content-Type' => 'image/png',
            'ETag' => $etag,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            return new Response(304, '', $headers);
        }
        return Response::file($path, $headers);
    }

    public static function deleteProfileImage(string $dir, ?string $name): void
    {
        if ($name && preg_match('/^[a-f0-9]{24}\.png$/', $name)) {
            @unlink(storage_path($dir . '/' . $name));
        }
    }
}
