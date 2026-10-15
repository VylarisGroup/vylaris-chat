<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Aktualisierung aus einem GitHub-Repository: standardmässig aus dem jüngsten Release (Kanal «release»),
 * alternativ aus dem Stand eines Branches (Kanal «branch»).
 *
 * Ablauf: Prüfen (Release-Tag bzw. Commit des Branches) → Herunterladen (ZIP des geprüften Commits) → Entpacken und prüfen →
 * Code-Sicherung → Dateien ersetzen (config/config.php und storage/ bleiben unberührt) → Migrationen laufen beim
 * nächsten Aufruf automatisch. Gesichert wird nur per HTTPS und der Übereinstimmung des Commits (kein Signaturschlüssel):
 * Wer das Repository oder das Token kontrolliert, kontrolliert den Code dieser Installation.
 */
final class Updater
{
    /** Pfade (relativ zum Projektstamm), die nie überschrieben oder gelöscht werden. */
    private const PROTECTED = ['config/config.php', 'storage/', '.git/', '.github/', 'node_modules/', 'tailwind/node_modules/'];
    private const MAX_ZIP_BYTES = 157286400;      // 150 MB
    private const MAX_UNPACKED_BYTES = 419430400; // 400 MB
    private const MAX_FILES = 20000;
    private const CHECK_INTERVAL = 21600;         // 6 h
    private const KEEP_CODE_BACKUPS = 3;

    // ------------------------------------------------------------ Einstellungen

    /** "owner/repo" aus Eingabe wie "https://github.com/owner/repo.git" oder "owner/repo". */
    public static function parseRepo(string $input): ?string
    {
        $in = trim($input);
        $in = preg_replace('#^https?://(www\.)?github\.com/#i', '', $in) ?? $in;
        $in = preg_replace('#(\.git)?/?$#i', '', $in) ?? $in;
        return preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]{0,38}/[A-Za-z0-9._-]{1,100}$#', $in) === 1 ? $in : null;
    }

    public static function configured(): bool
    {
        return self::parseRepo(Settings::get('update_repo')) !== null;
    }

    public static function channel(): string
    {
        return Settings::get('update_channel') === 'branch' ? 'branch' : 'release';
    }

    /** Ist $latest (Release-Tag) neuer als $installed? Bei nicht vergleichbaren Namen gilt jeder andere Commit als neu. */
    public static function newer(string $latest, string $installed): bool
    {
        if ($installed === '') {
            return true;
        }
        $l = ltrim($latest, 'vV');
        $i = ltrim($installed, 'vV');
        $semver = '/^\d+(\.\d+)*([-+][0-9A-Za-z.-]+)?$/';
        if (preg_match($semver, $l) && preg_match($semver, $i)) {
            return version_compare($l, $i, '>');
        }
        return $latest !== $installed;
    }

    public static function branch(): string
    {
        $b = trim(Settings::get('update_branch'));
        return preg_match('#^[A-Za-z0-9._/-]{1,100}$#', $b) === 1 && !str_contains($b, '..') ? $b : 'main';
    }

    private static function apiBase(): string
    {
        return rtrim(trim(Settings::get('update_api_base')) ?: 'https://api.github.com', '/');
    }

    private static function root(): string
    {
        return rtrim(ROOT_PATH, '/');
    }

    public static function prerequisites(): array
    {
        $r = self::root();
        $writable = true;
        foreach (['app', 'public', 'database', 'bin'] as $d) {
            if (is_dir("$r/$d") && !is_writable("$r/$d")) {
                $writable = false;
            }
        }
        return [
            'zip' => class_exists(\ZipArchive::class),
            'curl' => function_exists('curl_init'),
            'writable' => $writable && is_writable(storage_path('cache')),
        ];
    }

    // ------------------------------------------------------------ HTTP

    /**
     * @return array{code:int,json:array,location:string,error:string}
     */
    private static function api(string $path): array
    {
        $url = self::apiBase() . $path;
        self::assertUrl($url);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => self::headers(),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return ['code' => 0, 'json' => [], 'location' => '', 'error' => 'GitHub nicht erreichbar: ' . $err];
        }
        $head = substr((string) $raw, 0, $hs);
        $json = json_decode(substr((string) $raw, $hs), true);
        $loc = preg_match('/^location:\s*(\S+)/mi', $head, $m) ? $m[1] : '';
        return ['code' => $code, 'json' => is_array($json) ? $json : [], 'location' => $loc, 'error' => ''];
    }

    /** @return string[] */
    private static function headers(): array
    {
        $h = ['Accept: application/vnd.github+json', 'User-Agent: unternehmens-messenger-updater', 'X-GitHub-Api-Version: 2022-11-28'];
        $token = Crypto::decrypt(Settings::get('update_token_enc'));
        if ($token !== '' && Settings::get('update_visibility') === 'private') {
            $h[] = 'Authorization: Bearer ' . $token;
        }
        return $h;
    }

    private static function assertUrl(string $url): void
    {
        if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://127.0.0.1') && !str_starts_with($url, 'http://localhost')) {
            throw new \RuntimeException('Nur HTTPS-Adressen sind erlaubt.');
        }
    }

    private static function apiError(array $res): string
    {
        if ($res['error'] !== '') {
            return $res['error'];
        }
        $msg = (string) ($res['json']['message'] ?? '');
        return match (true) {
            $res['code'] === 401 => 'GitHub lehnt das Token ab (ungültig oder abgelaufen).',
            $res['code'] === 403 && stripos($msg, 'rate limit') !== false => 'GitHub-Abfragelimit erreicht. Bitte später erneut versuchen (mit Token ist das Limit höher).',
            $res['code'] === 403 => 'Zugriff verweigert' . ($msg !== '' ? ': ' . $msg : '.'),
            $res['code'] === 409 => 'Das Repository ist leer. Lade zuerst das Projekt in den Branch hoch.',
            $res['code'] === 404 => 'Repository oder Branch nicht gefunden. Bei privaten Repositories ist ein Token mit Lesezugriff nötig.',
            default => 'Unerwartete Antwort von GitHub (HTTP ' . $res['code'] . ').',
        };
    }

    // ------------------------------------------------------------ Prüfen

    private static function cacheFile(): string
    {
        return storage_path('cache/update.json');
    }

    /** Zwischengespeicherter Stand (kein Netzwerkzugriff). @return array<string,mixed> */
    public static function state(): array
    {
        $c = is_file(self::cacheFile()) ? json_decode((string) file_get_contents(self::cacheFile()), true) : null;
        $c = is_array($c) ? $c : [];
        $installed = Settings::get('update_installed_sha');
        $latest = (string) ($c['sha'] ?? '');
        return [
            'configured' => self::configured(),
            'repo' => self::parseRepo(Settings::get('update_repo')),
            'branch' => self::branch(),
            'installed' => $installed,
            'installed_at' => Settings::get('update_installed_at'),
            'installed_msg' => Settings::get('update_installed_msg'),
            'channel' => self::channel(),
            'tag' => (string) ($c['tag'] ?? ''),
            'installed_tag' => Settings::get('update_installed_tag'),
            'latest' => $latest,
            'latest_msg' => (string) ($c['message'] ?? ''),
            'latest_date' => (string) ($c['date'] ?? ''),
            'changes' => is_array($c['changes'] ?? null) ? $c['changes'] : [],
            'checked_at' => (int) ($c['checked_at'] ?? 0),
            'error' => (string) ($c['error'] ?? ''),
            'available' => $latest !== '' && $latest !== $installed && (self::channel() !== 'release' || self::newer((string) ($c['tag'] ?? ''), Settings::get('update_installed_tag'))),
            'unknown_base' => $installed === '',
        ];
    }

    public static function available(): bool
    {
        return self::configured() && (bool) self::state()['available'];
    }

    /** Fragt den neuesten Commit des Branches ab. @return array<string,mixed> state() */
    public static function check(): array
    {
        $repo = self::parseRepo(Settings::get('update_repo'));
        if ($repo === null) {
            return self::state();
        }
        $out = ['checked_at' => time()];
        try {
            $ref = self::branch();
            $out['changes'] = [];
            $out['tag'] = '';
            $relBody = '';
            if (self::channel() === 'release') {
                $rel = self::api('/repos/' . $repo . '/releases/latest');
                $tag = (string) ($rel['json']['tag_name'] ?? '');
                if ($rel['code'] === 404 && $rel['error'] === '') {
                    throw new \RuntimeException('Es wurde noch kein Release veröffentlicht (Entwürfe und Vorabversionen zählen nicht) – oder das Repository ist privat und es fehlt das Token.');
                }
                if ($rel['code'] !== 200 || preg_match('#^[A-Za-z0-9._+/-]{1,100}$#', $tag) !== 1 || str_contains($tag, '..')) {
                    throw new \RuntimeException(self::apiError($rel));
                }
                $ref = $tag;
                $out['tag'] = $tag;
                $relBody = (string) ($rel['json']['body'] ?? '');
                $relName = trim((string) ($rel['json']['name'] ?? '')) ?: $tag;
            }
            $res = self::api('/repos/' . $repo . '/commits/' . str_replace('%2F', '/', rawurlencode($ref)));
            if ($res['code'] !== 200 || !preg_match('/^[0-9a-f]{40}$/', (string) ($res['json']['sha'] ?? ''))) {
                $out['error'] = self::apiError($res);
            } else {
                $j = $res['json'];
                $out['sha'] = $j['sha'];
                $out['message'] = mb_substr(self::channel() === 'release' ? $relName : (strtok((string) ($j['commit']['message'] ?? ''), "\n") ?: ''), 0, 200);
                $out['date'] = (string) (self::channel() === 'release' ? ($rel['json']['published_at'] ?? '') : ($j['commit']['committer']['date'] ?? ''));
                if (self::channel() === 'release') {
                    foreach (preg_split('/\R/', $relBody) ?: [] as $line) {
                        $line = trim(preg_replace('/^(\s*[-*+]\s+|#{1,6}\s*)/', '', $line) ?? '');
                        if ($line !== '') {
                            $out['changes'][] = mb_substr($line, 0, 160);
                        }
                        if (count($out['changes']) >= 15) {
                            break;
                        }
                    }
                } else {
                    $installed = Settings::get('update_installed_sha');
                    if ($installed !== '' && $installed !== $j['sha'] && preg_match('/^[0-9a-f]{40}$/', $installed)) {
                        $cmp = self::api('/repos/' . $repo . '/compare/' . $installed . '...' . $j['sha']);
                        if ($cmp['code'] === 200) {
                            foreach (array_slice(array_reverse((array) ($cmp['json']['commits'] ?? [])), 0, 20) as $c) {
                                $out['changes'][] = mb_substr(strtok((string) ($c['commit']['message'] ?? ''), "\n") ?: '', 0, 160);
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        if (isset($out['error'])) {
            // Letzten guten Stand behalten, Fehler vermerken
            $old = is_file(self::cacheFile()) ? json_decode((string) file_get_contents(self::cacheFile()), true) : [];
            $out = array_merge(is_array($old) ? $old : [], ['checked_at' => time(), 'error' => $out['error']]);
        }
        @file_put_contents(self::cacheFile(), json_encode($out), LOCK_EX);
        return self::state();
    }

    /** Regelmässig (Bereinigung/Cron): höchstens alle 6 Stunden prüfen. */
    public static function maybeCheck(): void
    {
        if (!self::configured()) {
            return;
        }
        $s = self::state();
        if (time() - $s['checked_at'] > self::CHECK_INTERVAL) {
            self::check();
        }
    }

    // ------------------------------------------------------------ Installieren

    private static function isProtected(string $rel): bool
    {
        foreach (self::PROTECTED as $p) {
            if ($rel === rtrim($p, '/') || (str_ends_with($p, '/') && str_starts_with($rel, $p))) {
                return true;
            }
        }
        return false;
    }

    private static function safeRel(string $name): ?string
    {
        $n = str_replace('\\', '/', $name);
        if ($n === '' || str_starts_with($n, '/') || preg_match('#(^|/)\.\.(/|$)#', $n) || str_contains($n, "\0") || preg_match('#^[A-Za-z]:#', $n)) {
            return null;
        }
        return $n;
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    /** @return string[] relative Dateipfade des Projektbaums unter $base (ohne geschützte Pfade) */
    private static function listFiles(string $base): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isLink() || !$f->isFile()) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(rtrim($base, '/')) + 1));
            if (!self::isProtected($rel)) {
                $out[] = $rel;
            }
        }
        sort($out);
        return $out;
    }

    private static function manifestFile(): string
    {
        return storage_path('cache/update-manifest.json');
    }

    public static function codeBackups(): array
    {
        $files = glob(Backup::dir() . '/code-*.zip') ?: [];
        rsort($files);
        return array_map(static fn ($f) => ['file' => basename($f), 'size' => (int) filesize($f), 'at' => (int) filemtime($f)], $files);
    }

    /**
     * Installiert den neuesten Stand des Branches.
     *
     * @return array{ok:bool,error?:string,from?:string,to?:string,files?:int,removed?:int}
     */
    public static function install(bool $backupFirst = true): array
    {
        $pre = self::prerequisites();
        if (!$pre['zip'] || !$pre['curl']) {
            return ['ok' => false, 'error' => 'Die PHP-Erweiterungen zip und curl werden benötigt.'];
        }
        if (!$pre['writable']) {
            return ['ok' => false, 'error' => 'Die Code-Verzeichnisse sind für PHP nicht beschreibbar. Bitte Dateirechte prüfen.'];
        }
        $repo = self::parseRepo(Settings::get('update_repo'));
        if ($repo === null) {
            return ['ok' => false, 'error' => 'Es ist kein GitHub-Repository eingetragen.'];
        }
        $lockFh = @fopen(storage_path('cache/update.lock'), 'c');
        if (!$lockFh || !flock($lockFh, LOCK_EX | LOCK_NB)) {
            return ['ok' => false, 'error' => 'Es läuft bereits ein Update.'];
        }
        @set_time_limit(300);
        ignore_user_abort(true);
        // Klassen vorladen, die nach dem Ersetzen der Dateien noch gebraucht werden
        class_exists(Audit::class);
        class_exists(Settings::class);
        class_exists(Crypto::class);

        $tmp = storage_path('tmp/update-' . bin2hex(random_bytes(4)));
        $flag = storage_path('cache/maintenance.flag');
        $flagSet = false;
        $result = ['ok' => false, 'error' => 'Unbekannter Fehler.'];
        try {
            @mkdir($tmp, 0700, true);
            $st = self::check();
            if ($st['error'] !== '') {
                throw new \RuntimeException($st['error']);
            }
            $sha = $st['latest'];
            if (!preg_match('/^[0-9a-f]{40}$/', $sha)) {
                throw new \RuntimeException('Es konnte kein Stand ermittelt werden.');
            }
            if ($sha === $st['installed']) {
                $result = ['ok' => true, 'from' => $sha, 'to' => $sha, 'files' => 0, 'removed' => 0];
                return $result;
            }
            if ($backupFirst) {
                Backup::create(false, '', true);
            }

            // 1. Herunterladen
            $zip = $tmp . '/src.zip';
            self::download($repo, $sha, $zip);

            // 2. Prüfen und entpacken
            $srcRoot = self::unpack($zip, $tmp . '/src', $sha);

            // 3. Code-Sicherung der aktuellen Version
            $old = Settings::get('update_installed_sha');
            $oldTag = Settings::get('update_installed_tag');
            $backup = self::backupCode($old, $oldTag);

            // 4. Ersetzen
            $flagSet = (bool) @file_put_contents($flag, (string) time());
            $applied = self::apply($srcRoot, $backup);

            Settings::set('update_installed_sha', $sha);
            Settings::set('update_installed_tag', $st['channel'] === 'release' ? $st['tag'] : '');
            Settings::set('update_installed_at', now());
            Settings::set('update_installed_msg', $st['latest_msg']);
            @file_put_contents(self::cacheFile(), json_encode(['checked_at' => time(), 'sha' => $sha, 'tag' => $st['tag'], 'message' => $st['latest_msg'], 'date' => $st['latest_date'], 'changes' => []]), LOCK_EX);
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            Audit::log('system.updated', null, null, null, ($oldTag !== '' ? $oldTag : substr($old, 0, 7)) . ' → ' . ($st['tag'] !== '' && $st['channel'] === 'release' ? $st['tag'] : substr($sha, 0, 7)) . ' (' . $applied['files'] . ' Dateien)');
            $result = ['ok' => true, 'from' => $old, 'to' => $sha] + $applied;
        } catch (\Throwable $e) {
            log_error('Update fehlgeschlagen: ' . $e->getMessage());
            $result = ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            if ($flagSet) {
                @unlink($flag);
            }
            self::rrmdir($tmp);
            flock($lockFh, LOCK_UN);
            fclose($lockFh);
        }
        return $result;
    }

    private static function download(string $repo, string $sha, string $dest): void
    {
        $res = self::api('/repos/' . $repo . '/zipball/' . $sha);
        if ($res['code'] !== 302 && $res['code'] !== 301) {
            throw new \RuntimeException(self::apiError($res));
        }
        $loc = $res['location'];
        $apiHost = (string) parse_url(self::apiBase(), PHP_URL_HOST);
        $host = (string) parse_url($loc, PHP_URL_HOST);
        if (!in_array($host, ['codeload.github.com', $apiHost], true)) {
            throw new \RuntimeException('Unerwartetes Download-Ziel: ' . $host);
        }
        self::assertUrl($loc);
        $fh = fopen($dest, 'wb');
        $ch = curl_init($loc);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_TIMEOUT => 240,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['User-Agent: unternehmens-messenger-updater'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn ($r, $dt, $dl) => $dl > self::MAX_ZIP_BYTES ? 1 : 0,
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        if ($ok === false || $code !== 200) {
            throw new \RuntimeException('Download fehlgeschlagen' . ($err !== '' ? ': ' . $err : ' (HTTP ' . $code . ')') . '.');
        }
    }

    /** Prüft das ZIP und entpackt es. @return string Stamm des Projektbaums */
    private static function unpack(string $zipFile, string $dir, string $sha): string
    {
        $z = new \ZipArchive();
        if ($z->open($zipFile) !== true) {
            throw new \RuntimeException('Das heruntergeladene Archiv ist ungültig.');
        }
        if ($z->numFiles < 1 || $z->numFiles > self::MAX_FILES) {
            throw new \RuntimeException('Das Archiv hat eine unerwartete Dateianzahl.');
        }
        $total = 0;
        $top = null;
        for ($i = 0; $i < $z->numFiles; $i++) {
            $st = $z->statIndex($i);
            $name = self::safeRel((string) $st['name']);
            if ($name === null) {
                throw new \RuntimeException('Das Archiv enthält einen unzulässigen Pfad (' . mb_substr((string) $st['name'], 0, 60) . ').');
            }
            $seg = explode('/', $name)[0];
            $top ??= $seg;
            if ($seg !== $top) {
                throw new \RuntimeException('Das Archiv hat keine einheitliche Wurzel.');
            }
            $total += (int) $st['size'];
            if ($total > self::MAX_UNPACKED_BYTES) {
                throw new \RuntimeException('Das Archiv ist entpackt zu gross.');
            }
        }
        // GitHub benennt die Wurzel <owner>-<repo>-<7 Zeichen des Commits>: Abgleich mit dem geprüften Stand
        if ($top === null || !str_ends_with($top, '-' . substr($sha, 0, 7))) {
            throw new \RuntimeException('Das Archiv gehört nicht zum geprüften Stand (Commit stimmt nicht überein).');
        }
        @mkdir($dir, 0700, true);
        if (!$z->extractTo($dir)) {
            throw new \RuntimeException('Das Archiv konnte nicht entpackt werden.');
        }
        $z->close();
        $base = $dir . '/' . $top;
        foreach ([$base, $base . '/messenger'] as $cand) {
            if (is_file($cand . '/app/bootstrap.php') && is_file($cand . '/public/index.php') && is_dir($cand . '/database/migrations')) {
                return $cand;
            }
        }
        throw new \RuntimeException('Im Repository wurde kein Messenger-Projekt gefunden (erwartet: app/bootstrap.php, public/index.php, database/migrations – im Stamm oder im Ordner «messenger»).');
    }

    /** Sichert den aktuellen Code (ohne geschützte Pfade) als ZIP samt Dateiliste. @return string Pfad */
    private static function backupCode(string $oldSha, string $oldTag = ''): string
    {
        $root = self::root();
        $path = Backup::dir() . '/code-' . gmdate('Ymd-His') . '-' . ($oldSha !== '' ? substr($oldSha, 0, 7) : 'start') . '.zip';
        $z = new \ZipArchive();
        if ($z->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Die Code-Sicherung konnte nicht angelegt werden.');
        }
        $files = self::listFiles($root);
        foreach ($files as $rel) {
            $z->addFile($root . '/' . $rel, 'files/' . $rel);
        }
        $z->addFromString('meta.json', json_encode(['sha' => $oldSha, 'tag' => $oldTag, 'at' => gmdate('c'), 'files' => $files]));
        if (!$z->close()) {
            throw new \RuntimeException('Die Code-Sicherung konnte nicht geschrieben werden.');
        }
        foreach (array_slice(self::codeBackups(), self::KEEP_CODE_BACKUPS) as $b) {
            @unlink(Backup::dir() . '/' . $b['file']);
        }
        return $path;
    }

    /** @return array{files:int,removed:int} */
    private static function apply(string $src, string $backupZip): array
    {
        $root = self::root();
        $new = self::listFiles($src);
        // index.php und bootstrap.php zuletzt, damit ein Abbruch keine halbe Startdatei hinterlässt
        usort($new, static fn ($a, $b) => (in_array($a, ['public/index.php', 'app/bootstrap.php'], true) <=> in_array($b, ['public/index.php', 'app/bootstrap.php'], true)) ?: strcmp($a, $b));
        $done = 0;
        try {
            foreach ($new as $rel) {
                $dest = $root . '/' . $rel;
                if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0755, true)) {
                    throw new \RuntimeException('Verzeichnis nicht anlegbar: ' . dirname($rel));
                }
                $tmp = $dest . '.upd' . getmypid();
                if (!@copy($src . '/' . $rel, $tmp)) {
                    throw new \RuntimeException('Datei nicht schreibbar: ' . $rel);
                }
                @chmod($tmp, is_file($dest) ? (fileperms($dest) & 0777) : 0644);
                if (!@rename($tmp, $dest)) {
                    @unlink($tmp);
                    throw new \RuntimeException('Datei nicht ersetzbar: ' . $rel);
                }
                $done++;
            }
        } catch (\Throwable $e) {
            // Rückbau aus der Code-Sicherung
            try {
                self::restoreFromZip($backupZip, array_slice($new, 0, $done + 1));
            } catch (\Throwable $e2) {
                log_error('Rückbau nach Update-Fehler fehlgeschlagen: ' . $e2->getMessage());
            }
            throw new \RuntimeException($e->getMessage() . ' – das Update wurde zurückgenommen.');
        }
        // Dateien löschen, die die frühere Version mitbrachte, die neue aber nicht mehr hat
        $removed = 0;
        $prev = is_file(self::manifestFile()) ? json_decode((string) file_get_contents(self::manifestFile()), true) : [];
        foreach (array_diff(is_array($prev) ? $prev : [], $new) as $rel) {
            $safe = self::safeRel((string) $rel);
            if ($safe !== null && !self::isProtected($safe) && is_file($root . '/' . $safe)) {
                @unlink($root . '/' . $safe);
                $removed++;
            }
        }
        @file_put_contents(self::manifestFile(), json_encode($new), LOCK_EX);
        return ['files' => $done, 'removed' => $removed];
    }

    /** Stellt Dateien aus einer Code-Sicherung wieder her. @param string[]|null $only nur diese Pfade */
    private static function restoreFromZip(string $zipPath, ?array $only = null): int
    {
        $z = new \ZipArchive();
        if ($z->open($zipPath) !== true) {
            throw new \RuntimeException('Die Code-Sicherung ist nicht lesbar.');
        }
        $root = self::root();
        $n = 0;
        for ($i = 0; $i < $z->numFiles; $i++) {
            $name = (string) $z->getNameIndex($i);
            if (!str_starts_with($name, 'files/')) {
                continue;
            }
            $rel = self::safeRel(substr($name, 6));
            if ($rel === null || self::isProtected($rel) || ($only !== null && !in_array($rel, $only, true))) {
                continue;
            }
            $dest = $root . '/' . $rel;
            @mkdir(dirname($dest), 0755, true);
            $data = $z->getFromIndex($i);
            if ($data !== false && @file_put_contents($dest, $data) !== false) {
                $n++;
            }
        }
        $z->close();
        return $n;
    }

    /** Stellt die jüngste Code-Sicherung wieder her. Die Datenbank wird nicht zurückgesetzt. @return array{ok:bool,error?:string,restored?:int} */
    public static function rollback(): array
    {
        $b = self::codeBackups()[0] ?? null;
        if (!$b) {
            return ['ok' => false, 'error' => 'Keine Code-Sicherung vorhanden.'];
        }
        $lockFh = @fopen(storage_path('cache/update.lock'), 'c');
        if (!$lockFh || !flock($lockFh, LOCK_EX | LOCK_NB)) {
            return ['ok' => false, 'error' => 'Es läuft bereits ein Update.'];
        }
        class_exists(Audit::class);
        try {
            $path = Backup::dir() . '/' . $b['file'];
            $z = new \ZipArchive();
            if ($z->open($path) !== true) {
                throw new \RuntimeException('Die Code-Sicherung ist nicht lesbar.');
            }
            $meta = json_decode((string) $z->getFromName('meta.json'), true);
            $z->close();
            $old = is_array($meta['files'] ?? null) ? $meta['files'] : [];
            $n = self::restoreFromZip($path);
            // Dateien entfernen, die erst die neuere Version mitbrachte
            $cur = is_file(self::manifestFile()) ? json_decode((string) file_get_contents(self::manifestFile()), true) : [];
            foreach (array_diff(is_array($cur) ? $cur : [], $old) as $rel) {
                $safe = self::safeRel((string) $rel);
                if ($safe !== null && !self::isProtected($safe) && is_file(self::root() . '/' . $safe)) {
                    @unlink(self::root() . '/' . $safe);
                }
            }
            @file_put_contents(self::manifestFile(), json_encode($old), LOCK_EX);
            Settings::set('update_installed_sha', (string) ($meta['sha'] ?? ''));
            Settings::set('update_installed_tag', (string) ($meta['tag'] ?? ''));
            Settings::set('update_installed_at', now());
            Settings::set('update_installed_msg', 'Zurückgesetzt');
            @unlink(self::cacheFile());
            @unlink($path);
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            Audit::log('system.rollback', null, null, null, $b['file']);
            return ['ok' => true, 'restored' => $n];
        } catch (\Throwable $e) {
            log_error('Rollback fehlgeschlagen: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            flock($lockFh, LOCK_UN);
            fclose($lockFh);
        }
    }
}
