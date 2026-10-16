<?php use App\Core\Csrf;
$ok = $status['state'] === 'ok';
$limit = (int) $status['maxDevices'];
$stat = static fn (string $l, string $v, string $cls = '') => '<div class="card p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-neutral-400">' . e($l) . '</p><p class="mt-1 text-2xl font-semibold ' . $cls . '">' . e($v) . '</p></div>';
$stateLabel = ['ok' => 'Gültig', 'invalid' => 'Ungültig', 'unverified' => 'Nicht bestätigt', 'none' => 'Keine Lizenz'][$status['state']] ?? $status['state'];
?>
<div class="space-y-6">
<?php if (!$ok): ?>
  <div class="alert alert-error" role="alert"><i class="bx bx-lock-alt mt-0.5 text-lg" aria-hidden="true"></i><span><strong>Nur-Lesen-Modus.</strong> <?= e($status['message']) ?> Nachrichten bleiben lesbar; Senden, neue Konten und lizenzierte Funktionen sind gesperrt. Datenschutzrechte (Auskunft, Löschung) und die Administration bleiben nutzbar.</span></div>
<?php endif; ?>

<div class="grid gap-4 sm:grid-cols-4">
  <?= $stat('Status', $stateLabel, $ok ? 'text-emerald-600' : 'text-red-600') ?>
  <?= $stat('Aktive Konten', $activeUsers . ($limit > 0 ? ' / ' . $limit : ''), $limit > 0 && $activeUsers > $limit ? 'text-red-600' : ($limit > 0 && $activeUsers >= $limit ? 'text-amber-600' : '')) ?>
  <?= $stat('Tarif', $status['plan'] !== '' ? $status['plan'] . ($status['trial'] ? ' (Test)' : '') : '—') ?>
  <?= $stat('Gültig bis', $status['expiresAt'] ? local_dt(gmdate('Y-m-d H:i:s', (int) strtotime((string) $status['expiresAt'])), 'd.m.Y') : ($ok ? 'unbegrenzt' : '—')) ?>
</div>

<section class="card p-5 sm:p-6" aria-labelledby="h-lic">
  <h2 id="h-lic" class="mb-1 text-base font-semibold">Lizenzschlüssel</h2>
  <p class="mb-4 text-sm text-slate-500 dark:text-neutral-400">Ein Firmenschlüssel für die ganze Installation. Jedes aktive Konto belegt einen Geräteplatz des Schlüssels; dieselben Schlüssel geben Mitarbeitende bei der Selbstregistrierung ein. Zum Lizenzserver werden nur der Schlüssel, eine pseudonyme Platz-ID je Konto und die IP-Adresse dieses Servers übermittelt – keine Namen, E-Mail-Adressen oder Nachrichten.</p>
  <?php if ($lastError !== ''): ?><div class="alert alert-error mb-4" role="alert"><i class="bx bx-error-circle mt-0.5 text-lg" aria-hidden="true"></i><span class="min-w-0 break-words">Letzte Verbindungsstörung: <?= e($lastError) ?></span></div><?php endif; ?>
  <form method="post" action="<?= e(url('admin/license/save')) ?>" class="grid gap-4 sm:grid-cols-2">
    <?= Csrf::field() ?>
    <div class="sm:col-span-2"><label class="label" for="l-key">Lizenzschlüssel</label><input id="l-key" name="license_key" class="input font-mono" maxlength="80" autocomplete="off" spellcheck="false" placeholder="<?= $hasKey ? e($keyHint) . ' (gespeichert, leer lassen zum Beibehalten)' : 'XX-XXXX-XXXX-XXXX' ?>"></div>
    <div class="sm:col-span-2"><label class="label" for="l-prod">Produkt-Kennung (optional)</label><input id="l-prod" name="license_product" class="input" maxlength="80" value="<?= e($s['license_product']) ?>" placeholder="z. B. messenger"><p class="mt-1 text-xs text-slate-500 dark:text-neutral-400">Wenn gesetzt, wird nur ein Schlüssel dieses Produkts akzeptiert.</p></div>
    <div class="flex flex-wrap gap-2 sm:col-span-2"><button class="btn btn-primary" type="submit">Speichern und prüfen</button></div>
  </form>
  <div class="mt-3 flex flex-wrap gap-2">
    <form method="post" action="<?= e(url('admin/license/check')) ?>"><?= Csrf::field() ?><button class="btn btn-secondary" type="submit" <?= $hasKey ? '' : 'disabled' ?>><i class="bx bx-refresh text-lg" aria-hidden="true"></i>Jetzt prüfen und Plätze abgleichen</button></form>
    <?php if ($hasKey): ?><form method="post" action="<?= e(url('admin/license/remove')) ?>" class="flex items-center gap-2"><?= Csrf::field() ?><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="confirm" value="1" required class="h-4 w-4 rounded border-slate-300 text-accent focus:ring-accent"><span>Ja, Schlüssel entfernen (Nur-Lesen-Modus)</span></label><button class="btn btn-secondary" type="submit">Entfernen</button></form><?php endif; ?>
  </div>
  <p class="mt-3 text-xs text-slate-500 dark:text-neutral-400">Lizenzserver: <code><?= e(parse_url(\App\Services\License::server(), PHP_URL_HOST) ?: \App\Services\License::server()) ?></code> · Prüfsumme des Signaturschlüssels: <code><?= e($fingerprint) ?></code> (fest eingebaut) · Zuletzt geprüft: <?= $status['checkedAt'] ? e(local_dt(gmdate('Y-m-d H:i:s', (int) $status['checkedAt']), 'd.m.Y H:i')) : 'noch nie' ?> · belegte Plätze (lokal): <?= (int) $seats ?> · Der Server wird alle 6 Stunden befragt; fällt er aus, gilt die letzte Bestätigung <?= (int) $s['license_grace_hours'] ?> Stunden weiter.</p>
</section>

<section class="card p-5 sm:p-6" aria-labelledby="h-feat">
  <h2 id="h-feat" class="mb-1 text-base font-semibold">Funktionen (Scopes)</h2>
  <p class="mb-4 text-sm text-slate-500 dark:text-neutral-400">Welche Funktionen eine Lizenz freischaltet, legst du im Lizenz-Panel im Feld «Funktionen» des Tarifs oder der einzelnen Lizenz fest – mit diesen Kennungen:</p>
  <div class="overflow-x-auto"><table class="w-full text-left text-sm">
    <thead><tr class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500 dark:border-neutral-800"><th class="py-2 pr-4">Kennung</th><th class="py-2 pr-4">Funktion</th><th class="py-2 pr-4">Freigabe</th></tr></thead>
    <tbody class="divide-y divide-slate-100 dark:divide-neutral-800">
    <?php foreach ($scopes as $key => [$label, $desc]): $on = $ok && in_array($key, $status['features'], true); ?>
      <tr><td class="py-2.5 pr-4 align-top"><code><?= e($key) ?></code></td><td class="py-2.5 pr-4 align-top"><p class="font-medium"><?= e($label) ?></p><p class="text-xs text-slate-500 dark:text-neutral-400"><?= e($desc) ?></p></td>
        <td class="py-2.5 pr-4 align-top"><?= $on ? '<span class="inline-flex items-center gap-1 text-emerald-600"><i class="bx bx-check-circle" aria-hidden="true"></i>freigeschaltet</span>' : '<span class="inline-flex items-center gap-1 text-slate-500"><i class="bx bx-lock-alt" aria-hidden="true"></i>gesperrt</span>' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="mt-3 text-xs text-slate-500 dark:text-neutral-400">Beispiel für «Funktionen» im Panel: <code>messaging, groups, files, export, 2fa, sso, push, realtime, backup</code>. Ohne <code>messaging</code> können keine Nachrichten gesendet werden.</p>
</section>
</div>
