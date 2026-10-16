<?php use App\Core\Csrf;
$short = static fn (string $s): string => $s !== '' ? substr($s, 0, 7) : '—';
$ver = static fn (string $tag, string $sha): string => $tag !== '' ? $tag : ($sha !== '' ? substr($sha, 0, 7) : '—');
$when = static fn (int $t): string => $t > 0 ? local_dt(gmdate('Y-m-d H:i:s', $t), 'd.m.Y H:i') : 'noch nie';
$sz = static fn (int $b): string => $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, (int) round($b / 1024)) . ' KB';
$ready = $pre['zip'] && $pre['curl'] && $pre['writable'];
$box = 'mt-0.5 h-4 w-4 rounded border-slate-300 text-accent focus:ring-accent';
$stat = static fn (string $l, string $v, string $cls = '') => '<div class="card p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-neutral-400">' . e($l) . '</p><p class="mt-1 text-2xl font-semibold ' . $cls . '">' . e($v) . '</p></div>';
?>
<div class="space-y-6">
<?php if (!$ready): ?>
  <div class="alert alert-error" role="alert"><i class="bx bx-error-circle mt-0.5 text-lg" aria-hidden="true"></i><span>Voraussetzungen fehlen:
    <?= !$pre['zip'] ? 'PHP-Erweiterung <code>zip</code>. ' : '' ?><?= !$pre['curl'] ? 'PHP-Erweiterung <code>curl</code>. ' : '' ?><?= !$pre['writable'] ? 'Die Code-Verzeichnisse (app, public, database, bin) und storage/cache sind für PHP nicht beschreibbar. ' : '' ?>Das Update kann so nicht installiert werden.</span></div>
<?php endif; ?>
<?php if ($st['configured'] && !$migrated): ?>
  <div class="alert alert-error" role="alert"><i class="bx bx-error-circle mt-0.5 text-lg" aria-hidden="true"></i><span>Die Datenbank-Migrationen sind noch nicht auf dem neuesten Stand. Sie werden bei jedem Seitenaufruf erneut versucht; prüfe das Fehlerprotokoll, falls diese Meldung bleibt.</span></div>
<?php endif; ?>

<div class="grid gap-4 sm:grid-cols-4">
  <?= $stat('Installiert', $ver($st['installed_tag'], $st['installed']), '') ?>
  <?= $stat($st['channel'] === 'release' ? 'Neuestes Release' : 'Neuester Stand', $ver($st['tag'], $st['latest']), $st['available'] ? 'text-amber-600' : '') ?>
  <?= $stat('Status', !$st['configured'] ? 'Nicht eingerichtet' : ($st['error'] !== '' ? 'Prüfung fehlgeschlagen' : ($st['available'] ? 'Update verfügbar' : ($st['latest'] === '' ? 'Nicht geprüft' : 'Aktuell'))), $st['available'] ? 'text-amber-600' : ($st['error'] !== '' ? 'text-red-600' : '')) ?>
  <?= $stat('Zuletzt geprüft', $when($st['checked_at'])) ?>
</div>

<?php if ($st['error'] !== ''): ?><div class="alert alert-error" role="alert"><i class="bx bx-error-circle mt-0.5 text-lg" aria-hidden="true"></i><span class="min-w-0 break-words"><?= e($st['error']) ?></span></div><?php endif; ?>

<?php if ($st['configured'] && $st['latest'] !== ''): ?>
<section class="card p-5 sm:p-6" aria-labelledby="h-up">
  <h2 id="h-up" class="mb-1 text-base font-semibold">Update</h2>
  <p class="mb-3 text-sm text-slate-600 dark:text-neutral-300">Version <?= e($version) ?> · installiert: <code><?= e($ver($st['installed_tag'], $st['installed'])) ?></code><?= $st['installed_msg'] !== '' ? ' – ' . e($st['installed_msg']) : '' ?><?= $st['installed_at'] !== '' ? ' (' . e(local_dt($st['installed_at'], 'd.m.Y H:i')) . ')' : '' ?></p>
  <?php if ($st['available']): ?>
    <p class="mb-2 text-sm"><?= $st['channel'] === 'release' ? 'Neues Release' : 'Neuester Stand' ?> von <code><?= e($st['repo']) ?></code><?= $st['channel'] === 'branch' ? ' (<code>' . e($st['branch']) . '</code>)' : '' ?>: <code><?= e($ver($st['tag'], $st['latest'])) ?></code> – <?= e($st['latest_msg']) ?><?= $st['latest_date'] !== '' ? ' · ' . e(local_dt(gmdate('Y-m-d H:i:s', (int) strtotime($st['latest_date'])), 'd.m.Y H:i')) : '' ?></p>
    <?php if ($st['changes']): ?>
      <ul class="mb-4 list-disc space-y-0.5 pl-5 text-sm text-slate-600 dark:text-neutral-300"><?php foreach ($st['changes'] as $c): ?><li><?= e($c) ?></li><?php endforeach; ?></ul>
    <?php elseif ($st['unknown_base']): ?>
      <p class="mb-4 text-xs text-slate-500 dark:text-neutral-400">Der installierte Stand ist unbekannt (Erstinstallation aus ZIP). Ist die Installation bereits auf dem Stand des Repositories, übernimm ihn mit «Als installiert vermerken»; sonst installiere das Update.</p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('admin/update/install')) ?>" class="space-y-3">
      <?= Csrf::field() ?>
      <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="backup" value="1" checked class="<?= $box ?>"><span>Vorher ein vollständiges Backup erstellen (Datenbank und Dateien; bei grossen Datenmengen dauert das länger)</span></label>
      <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirm" value="1" required class="<?= $box ?>"><span>Ich möchte jetzt aktualisieren. Dabei wird der Messenger für einige Sekunden nicht erreichbar sein.</span></label>
      <div class="flex flex-wrap gap-2"><button class="btn btn-primary" type="submit" <?= $ready ? '' : 'disabled' ?>><i class="bx bx-cloud-download text-lg" aria-hidden="true"></i>Update installieren</button></div>
    </form>
    <?php if ($st['unknown_base']): ?><form method="post" action="<?= e(url('admin/update/baseline')) ?>" class="mt-2"><?= Csrf::field() ?><button class="btn btn-secondary btn-sm" type="submit">Als installiert vermerken (ohne Dateien zu ändern)</button></form><?php endif; ?>
  <?php else: ?>
    <p class="text-sm text-emerald-700 dark:text-emerald-400"><i class="bx bx-check-circle" aria-hidden="true"></i> Die Installation ist auf dem neuesten Stand.</p>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card p-5 sm:p-6" aria-labelledby="h-src">
  <h2 id="h-src" class="mb-1 text-base font-semibold">Update-Quelle</h2>
  <p class="mb-4 text-sm text-slate-500 dark:text-neutral-400">Updates kommen immer aus den veröffentlichten <strong>GitHub-Releases</strong> von <code><?= e($st['repo']) ?></code>; Entwürfe und Vorabversionen werden ignoriert. Der Messenger prüft alle 6 Stunden und zeigt hier und in der Administration einen Hinweis; installiert wird nur auf Klick. <code>config/config.php</code> und <code>storage/</code> bleiben immer unberührt.</p>
  <div class="alert alert-info mb-4" role="note"><i class="bx bx-info-circle mt-0.5 text-lg" aria-hidden="true"></i><span><strong>Vertrauensgrenze:</strong> Die Echtheit wird nur über HTTPS und den Abgleich des Commits geprüft, nicht über eine Signatur. Wer Schreibzugriff auf das Repository hat, kann damit Code auf dieser Installation ausführen. Schütze das Repository entsprechend (Zwei-Faktor, Branch-Schutz).</span></div>
  <form method="post" action="<?= e(url('admin/update/check')) ?>"><?= Csrf::field() ?><button class="btn btn-secondary" type="submit"><i class="bx bx-refresh text-lg" aria-hidden="true"></i>Jetzt auf Updates prüfen</button></form>
</section>

<?php if ($backups): ?>
<section class="card p-5 sm:p-6" aria-labelledby="h-rb">
  <h2 id="h-rb" class="mb-1 text-base font-semibold">Rückbau</h2>
  <p class="mb-3 text-sm text-slate-500 dark:text-neutral-400">Vor jedem Update wird der bisherige Code gesichert (die letzten 3 bleiben). Ein Rückbau stellt <strong>nur den Code</strong> wieder her, nicht die Datenbank; Migrationen sind in der Regel nur ergänzend und mit älterem Code verträglich. Für einen vollständigen Rückbau dient das Backup.</p>
  <ul class="mb-3 space-y-1 text-sm text-slate-600 dark:text-neutral-300"><?php foreach ($backups as $b): ?><li><code><?= e($b['file']) ?></code> · <?= e($sz($b['size'])) ?> · <?= e(local_dt(gmdate('Y-m-d H:i:s', $b['at']), 'd.m.Y H:i')) ?></li><?php endforeach; ?></ul>
  <form method="post" action="<?= e(url('admin/update/rollback')) ?>" class="flex flex-wrap items-center gap-3"><?= Csrf::field() ?>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="confirm" value="1" required class="<?= $box ?>"><span>Ja, den Code auf die jüngste Sicherung zurücksetzen</span></label>
    <button class="btn btn-secondary" type="submit">Code zurücksetzen</button></form>
</section>
<?php endif; ?>
</div>
