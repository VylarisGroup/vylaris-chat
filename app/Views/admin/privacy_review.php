<?php use App\Core\Csrf;
$box = 'mt-0.5 h-4 w-4 rounded border-slate-300 text-accent focus:ring-accent';
$changed = $record && ($record['fingerprint'] ?? '') !== $fingerprint;
$pct = $check['total'] ? (int) round($check['done'] / $check['total'] * 100) : 0;
?>
<div class="space-y-6">
<div class="alert alert-info" role="note"><i class="bx bx-info-circle mt-0.5 text-lg" aria-hidden="true"></i>
  <span><strong>Dieses Paket ersetzt keine Rechtsberatung.</strong> Es sammelt, was eine Fachperson (Anwältin/Anwalt, Datenschutzberatung) für die Prüfung braucht, und zeigt, wo technisch noch Lücken sind. Die Texte sind Muster nach Schweizer DSG und DSGVO; ob sie zu deiner Organisation passen, entscheidet die rechtliche Prüfung.</span></div>

<section class="card p-5 sm:p-6" aria-labelledby="h-rec">
  <h2 id="h-rec" class="mb-1 text-base font-semibold">Prüfvermerk</h2>
  <?php if ($record): ?>
    <p class="mb-3 text-sm <?= $changed ? 'text-amber-700 dark:text-amber-400' : 'text-emerald-700 dark:text-emerald-400' ?>"><i class="bx bx-<?= $changed ? 'error' : 'check-circle' ?> align-middle text-lg" aria-hidden="true"></i>
      Geprüft von <strong><?= e($record['reviewer']) ?></strong> am <?= e(date('d.m.Y', strtotime($record['date']))) ?>.
      <?= $changed ? '<strong>Seither wurden Angaben oder Funktionen geändert</strong> – eine erneute Prüfung ist empfohlen.' : 'Seither keine relevanten Änderungen.' ?></p>
    <?php if (!empty($record['notes'])): ?><p class="mb-3 whitespace-pre-line text-sm text-slate-600 dark:text-neutral-300"><?= e($record['notes']) ?></p><?php endif; ?>
  <?php else: ?>
    <p class="mb-3 text-sm text-amber-700 dark:text-amber-400">Noch kein Prüfvermerk erfasst.</p>
  <?php endif; ?>
  <form method="post" action="<?= e(url('admin/privacy/review/record')) ?>" class="grid gap-4 sm:grid-cols-3">
    <?= Csrf::field() ?>
    <div class="sm:col-span-2"><label class="label" for="rv-by">Prüfende Stelle (Name, Kanzlei/Beratung)</label><input id="rv-by" name="reviewer" class="input" maxlength="200" required></div>
    <div><label class="label" for="rv-date">Datum der Prüfung</label><input id="rv-date" name="date" type="date" class="input" required></div>
    <div class="sm:col-span-3"><label class="label" for="rv-notes">Ergebnis / Auflagen (optional)</label><textarea id="rv-notes" name="notes" rows="2" maxlength="2000" class="input"></textarea></div>
    <div class="sm:col-span-3"><button class="btn btn-primary" type="submit">Prüfvermerk speichern</button></div>
  </form>
</section>

<section class="card p-5 sm:p-6" aria-labelledby="h-chk">
  <div class="mb-3 flex flex-wrap items-center justify-between gap-2"><h2 id="h-chk" class="text-base font-semibold">Checkliste</h2><span class="text-sm text-slate-500 dark:text-neutral-400"><?= (int) $check['done'] ?> von <?= (int) $check['total'] ?> erledigt (<?= $pct ?> %)</span></div>
  <form method="post" action="<?= e(url('admin/privacy/review/checklist')) ?>">
    <?= Csrf::field() ?>
    <ul class="divide-y divide-slate-100 dark:divide-neutral-800">
      <?php foreach ($check['items'] as $it): ?>
        <li class="flex items-start gap-3 py-2.5 text-sm">
          <?php if ($it['auto']): ?>
            <i class="bx bx-<?= $it['ok'] ? 'check-circle text-emerald-600' : 'x-circle text-red-600' ?> mt-0.5 text-xl" aria-hidden="true"></i><span class="sr-only"><?= $it['ok'] ? 'erfüllt' : 'offen' ?></span>
          <?php else: ?>
            <input type="checkbox" name="items[]" value="<?= e($it['id']) ?>" id="ck-<?= e($it['id']) ?>" <?= $it['ok'] ? 'checked' : '' ?> class="<?= $box ?> mt-1">
          <?php endif; ?>
          <div class="min-w-0 flex-1">
            <?php if ($it['auto']): ?><p class="font-medium"><?= e($it['title']) ?> <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-slate-500 dark:bg-neutral-800">automatisch</span></p>
            <?php else: ?><label for="ck-<?= e($it['id']) ?>" class="font-medium"><?= e($it['title']) ?></label><?php endif; ?>
            <?php if ($it['hint']): ?><p class="text-xs text-slate-500 dark:text-neutral-400"><?= e($it['hint']) ?></p><?php endif; ?>
            <?php if ($it['by']): ?><p class="text-xs text-slate-400">bestätigt von <?= e($it['by']) ?> am <?= e(date('d.m.Y', strtotime($it['at']))) ?></p><?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <button class="btn btn-primary mt-4" type="submit">Manuelle Punkte speichern</button>
  </form>
</section>

<section class="card p-5 sm:p-6" aria-labelledby="h-reg">
  <div class="mb-3 flex flex-wrap items-center justify-between gap-2"><div><h2 id="h-reg" class="text-base font-semibold">Verzeichnis der Bearbeitungstätigkeiten</h2><p class="text-sm text-slate-500 dark:text-neutral-400">Art. 12 DSG / Art. 30 DSGVO. Automatisch aus Funktionsumfang und Einstellungen erzeugt; ergänze es um Tätigkeiten ausserhalb des Messengers.</p></div>
    <a class="btn btn-secondary btn-sm" href="<?= e(url('admin/privacy/verzeichnis.md')) ?>"><i class="bx bx-download text-base" aria-hidden="true"></i>Als Markdown herunterladen</a></div>
  <p class="mb-3 text-sm"><strong>Verantwortlicher:</strong> <?= nl2br(e($register['controller']), false) ?></p>
  <div class="space-y-3">
    <?php foreach ($register['activities'] as $i => $a): ?>
      <details class="rounded-xl border border-slate-200 p-3 dark:border-neutral-700" <?= $i === 0 ? 'open' : '' ?>>
        <summary class="cursor-pointer text-sm font-semibold"><?= ($i + 1) . '. ' . e($a['name']) ?></summary>
        <dl class="mt-2 grid gap-x-4 gap-y-1.5 text-sm sm:grid-cols-[14rem_1fr]">
          <?php foreach (['purpose' => 'Zweck', 'subjects' => 'Betroffene Personen', 'data' => 'Datenkategorien', 'recipients' => 'Empfänger', 'abroad' => 'Ausland / Standort', 'retention' => 'Aufbewahrung', 'toms' => 'Massnahmen (TOM)'] as $k => $l): ?>
            <dt class="font-medium text-slate-500 dark:text-neutral-400"><?= e($l) ?></dt><dd><?= e($a[$k]) ?></dd>
          <?php endforeach; ?>
        </dl>
      </details>
    <?php endforeach; ?>
  </div>
</section>
</div>
