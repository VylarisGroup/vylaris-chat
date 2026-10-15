<?php use App\Core\Csrf;
use App\Services\Privacy;
$box = 'h-4 w-4 rounded border-slate-300 text-accent focus:ring-accent';
$statusCls = [
    'open' => 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    'in_progress' => 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-200',
    'completed' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    'rejected' => 'bg-slate-200 text-slate-700 dark:bg-neutral-700 dark:text-neutral-200',
];
$filters = ['active' => 'Offen', 'closed' => 'Abgeschlossen', 'all' => 'Alle'];
$missing = trim((string) $s['privacy_controller']) === '' || trim((string) $s['privacy_contact_email']) === '';
?>
<div class="space-y-6">

<?php if ($missing): ?>
  <div class="alert alert-error" role="alert"><i class="bx bx-error-circle mt-0.5 text-lg" aria-hidden="true"></i>
    <span>Die Datenschutzerklärung ist unvollständig: Bitte hinterlege unten den <strong>Verantwortlichen</strong> und die <strong>Kontaktadresse</strong>, bevor der Messenger produktiv genutzt wird.</span></div>
<?php endif; ?>

<div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900"><p class="text-sm">Für die rechtliche Prüfung: Checkliste, Verzeichnis der Bearbeitungstätigkeiten und Prüfvermerk.</p><a class="btn btn-secondary btn-sm" href="<?= e(url('admin/privacy/review')) ?>"><i class="bx bx-clipboard text-base" aria-hidden="true"></i>Prüfpaket öffnen</a><a class="btn btn-secondary btn-sm" href="<?= e(url('admin/privacy/breaches')) ?>"><i class="bx bx-error text-base" aria-hidden="true"></i>Datenschutzverletzungen</a></div>

<div class="grid gap-4 sm:grid-cols-3">
  <div class="card p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-neutral-400">Offene Anfragen</p><p class="mt-1 text-2xl font-semibold"><?= (int) $stats['open'] ?></p></div>
  <div class="card p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-neutral-400">Frist überschritten</p><p class="mt-1 text-2xl font-semibold <?= $stats['overdue'] ? 'text-red-600 dark:text-red-400' : '' ?>"><?= (int) $stats['overdue'] ?></p></div>
  <div class="card p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-neutral-400">Eingeschränkte Konten</p><p class="mt-1 text-2xl font-semibold"><?= (int) $stats['restricted'] ?></p></div>
</div>

<section class="card p-5 sm:p-6" aria-labelledby="h-req">
  <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div><h2 id="h-req" class="text-base font-semibold">Anfragen von Betroffenen</h2>
      <p class="text-sm text-slate-500 dark:text-neutral-400">Antwortfrist <?= (int) Privacy::DEADLINE_DAYS ?> Tage (Art. 25 Abs. 7 DSG, Art. 12 Abs. 3 DSGVO). Mitglieder stellen Anfragen unter Einstellungen &rarr; Meine Daten.</p></div>
    <nav class="flex gap-1" aria-label="Filter"><?php foreach ($filters as $k => $l): ?><a class="btn btn-sm <?= $status === $k ? 'btn-primary' : 'btn-secondary' ?>" href="<?= e(url('admin/privacy?status=' . $k)) ?>" <?= $status === $k ? 'aria-current="page"' : '' ?>><?= e($l) ?></a><?php endforeach; ?></nav>
  </div>
  <?php if (!$requests): ?>
    <p class="py-6 text-center text-sm text-slate-500">Keine Anfragen in dieser Ansicht.</p>
  <?php else: ?>
    <ul class="divide-y divide-slate-100 dark:divide-neutral-800">
      <?php foreach ($requests as $q):
        $active = in_array($q['status'], ['open', 'in_progress'], true);
        $overdue = $active && strtotime($q['due_at'] . ' UTC') < time(); ?>
        <li class="py-3">
          <details>
            <summary class="flex cursor-pointer list-none flex-wrap items-center gap-x-4 gap-y-1 text-sm">
              <span class="font-mono text-xs text-slate-500">#<?= (int) $q['id'] ?></span>
              <span class="font-medium"><?= e(Privacy::TYPES[$q['type']] ?? $q['type']) ?></span>
              <span class="text-slate-600 dark:text-neutral-300"><?= e($q['display_name'] ?? ($q['subject_label'] ?: 'Unbekannt')) ?></span>
              <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold <?= $statusCls[$q['status']] ?>"><?= e(Privacy::STATUSES[$q['status']]) ?></span>
              <span class="text-xs <?= $overdue ? 'font-semibold text-red-600 dark:text-red-400' : 'text-slate-500 dark:text-neutral-400' ?>">Frist <?= e(local_dt($q['due_at'], 'd.m.Y')) ?><?= $overdue ? ' (überschritten)' : '' ?></span>
              <span class="ml-auto text-xs text-accent">Bearbeiten</span>
            </summary>
            <div class="mt-3 space-y-3 rounded-xl bg-slate-50 p-4 dark:bg-neutral-800/50">
              <p class="text-xs text-slate-500 dark:text-neutral-400">Eingegangen <?= e(local_dt($q['created_at'])) ?> · Quelle: <?= $q['source'] === 'admin' ? 'durch Administration erfasst' : 'von der Person im Messenger gestellt' ?><?= $q['handled_at'] ? ' · bearbeitet ' . e(local_dt($q['handled_at'])) . ($q['handler'] ? ' von ' . e($q['handler']) : '') : '' ?></p>
              <?php if ($q['message'] !== ''): ?><p class="whitespace-pre-line break-words text-sm"><?= e($q['message']) ?></p><?php endif; ?>
              <?php if ($q['user_id']): ?>
                <div class="flex flex-wrap gap-2">
                  <a class="btn btn-secondary btn-sm" href="<?= e(url('admin/users?edit=' . (int) $q['user_id'])) ?>"><i class="bx bx-user text-base" aria-hidden="true"></i>Person verwalten</a>
                  <form method="post" action="<?= e(url('admin/users/' . (int) $q['user_id'] . '/export')) ?>"><?= Csrf::field() ?><button class="btn btn-secondary btn-sm" type="submit"><i class="bx bx-download text-base" aria-hidden="true"></i>Datenauszug (Metadaten)</button></form>
                </div>
              <?php endif; ?>
              <form method="post" action="<?= e(url('admin/privacy/requests/' . (int) $q['id'])) ?>" class="grid gap-3 sm:grid-cols-[12rem_1fr_auto] sm:items-end">
                <?= Csrf::field() ?>
                <div><label class="label" for="rs-<?= (int) $q['id'] ?>">Status</label>
                  <select id="rs-<?= (int) $q['id'] ?>" name="status" class="input"><?php foreach (Privacy::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $q['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div><label class="label" for="rr-<?= (int) $q['id'] ?>">Antwort / Vermerk (wird der Person nicht automatisch zugestellt, aber in ihrer Anfrageliste angezeigt)</label>
                  <textarea id="rr-<?= (int) $q['id'] ?>" name="response" rows="2" maxlength="2000" class="input"><?= e($q['response']) ?></textarea></div>
                <button class="btn btn-primary" type="submit">Speichern</button>
              </form>
            </div>
          </details>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<div class="grid gap-6 xl:grid-cols-2">
  <section class="card p-5 sm:p-6" aria-labelledby="h-find">
    <h2 id="h-find" class="mb-1 text-base font-semibold">Daten einer Person bearbeiten</h2>
    <p class="mb-4 text-sm text-slate-500 dark:text-neutral-400">Suche die betroffene Person, um ihre Daten herunterzuladen, zu berichtigen, einzuschränken oder zu löschen.</p>
    <form method="get" class="flex gap-2" role="search"><label class="sr-only" for="pq">Person suchen</label><input id="pq" name="q" type="search" class="input" placeholder="Name oder E-Mail" value="<?= e($q) ?>"><input type="hidden" name="status" value="<?= e($status) ?>"><button class="btn btn-secondary" type="submit">Suchen</button></form>
    <?php if ($q !== ''): ?>
      <ul class="mt-3 divide-y divide-slate-100 dark:divide-neutral-800">
        <?php foreach ($found as $f): ?>
          <li class="flex items-center justify-between gap-3 py-2 text-sm"><span><span class="font-medium"><?= e($f['display_name']) ?></span> <span class="text-xs text-slate-500"><?= e($f['email']) ?></span></span>
            <a class="btn btn-secondary btn-sm" href="<?= e(url('admin/users?edit=' . (int) $f['id'])) ?>">Verwalten</a></li>
        <?php endforeach; ?>
        <?php if (!$found): ?><li class="py-3 text-sm text-slate-500">Keine Person gefunden.</li><?php endif; ?>
      </ul>
    <?php endif; ?>
    <?php if ($restricted): ?>
      <h3 class="mb-2 mt-6 text-sm font-semibold">Eingeschränkte Konten</h3>
      <ul class="divide-y divide-slate-100 text-sm dark:divide-neutral-800">
        <?php foreach ($restricted as $x): ?>
          <li class="flex items-center justify-between gap-3 py-2"><span><span class="font-medium"><?= e($x['display_name']) ?></span> <span class="text-xs text-slate-500">seit <?= e(local_dt($x['processing_restricted_at'], 'd.m.Y')) ?><?= $x['processing_restricted_note'] !== '' ? ' · ' . e($x['processing_restricted_note']) : '' ?></span></span>
            <a class="btn btn-secondary btn-sm" href="<?= e(url('admin/users?edit=' . (int) $x['id'])) ?>">Verwalten</a></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="card p-5 sm:p-6" aria-labelledby="h-new">
    <h2 id="h-new" class="mb-1 text-base font-semibold">Anfrage erfassen</h2>
    <p class="mb-4 text-sm text-slate-500 dark:text-neutral-400">Für Anfragen, die per E-Mail oder Brief eingehen, auch von Personen ohne aktives Konto. Prüfe vor der Herausgabe von Daten die Identität.</p>
    <form method="post" action="<?= e(url('admin/privacy/requests')) ?>" class="space-y-3">
      <?= Csrf::field() ?>
      <div><label class="label" for="n-sub">Betroffene Person (E-Mail oder Name)</label><input id="n-sub" name="subject" class="input" required maxlength="190"></div>
      <div><label class="label" for="n-type">Art der Anfrage</label><select id="n-type" name="type" class="input"><?php foreach (Privacy::TYPES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div><label class="label" for="n-msg">Notiz (optional)</label><textarea id="n-msg" name="message" rows="2" maxlength="2000" class="input"></textarea></div>
      <button class="btn btn-primary" type="submit">Erfassen</button>
    </form>
  </section>
</div>

<section class="card p-5 sm:p-6" aria-labelledby="h-cfg">
  <h2 id="h-cfg" class="mb-1 text-base font-semibold">Angaben für die Datenschutzerklärung</h2>
  <p class="mb-4 text-sm text-slate-500 dark:text-neutral-400">Diese Angaben erscheinen auf der öffentlichen Seite <a class="font-medium text-accent hover:underline" href="<?= e(url('privacy')) ?>" target="_blank" rel="noopener">Datenschutzerklärung</a>. Der Mustertext ersetzt keine Rechtsberatung: Lass ihn für deine Organisation prüfen, besonders bei Beschäftigtendaten, Hosting im Ausland und internen Reglementen.</p>
  <form method="post" action="<?= e(url('admin/privacy/settings')) ?>" class="grid gap-4 sm:grid-cols-2">
    <?= Csrf::field() ?>
    <div class="sm:col-span-2"><label class="label" for="p-ctl">Verantwortlicher (Firma, Adresse)</label><textarea id="p-ctl" name="privacy_controller" rows="3" maxlength="600" class="input" placeholder="Muster AG&#10;Musterstrasse 1&#10;8000 Zürich"><?= e($s['privacy_controller']) ?></textarea></div>
    <div><label class="label" for="p-mail">Kontakt für Datenschutzanliegen (E-Mail)</label><input id="p-mail" name="privacy_contact_email" type="email" maxlength="190" class="input" value="<?= e($s['privacy_contact_email']) ?>"></div>
    <div><label class="label" for="p-scope">Anwendbares Recht</label>
      <select id="p-scope" name="privacy_scope" class="input"><?php foreach (['both' => 'Schweiz (DSG) und EU (DSGVO)', 'ch' => 'Nur Schweiz (DSG)', 'eu' => 'Nur EU/EWR (DSGVO)'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $s['privacy_scope'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label class="label" for="p-dpo">Datenschutzberater / Datenschutzbeauftragte(r) (optional)</label><textarea id="p-dpo" name="privacy_dpo" rows="2" maxlength="400" class="input"><?= e($s['privacy_dpo']) ?></textarea></div>
    <div><label class="label" for="p-rep">Vertretung in CH bzw. EU (optional)</label><textarea id="p-rep" name="privacy_representative" rows="2" maxlength="400" class="input"><?= e($s['privacy_representative']) ?></textarea><p class="mt-1 text-xs text-slate-500 dark:text-neutral-400">Nur nötig, wenn der Verantwortliche seinen Sitz ausserhalb des jeweiligen Gebiets hat.</p></div>
    <div class="sm:col-span-2"><label class="label" for="p-host">Hosting und Serverstandort</label><textarea id="p-host" name="privacy_hosting" rows="2" maxlength="600" class="input" placeholder="Server bei Hosting-Anbieter X in Zürich, Schweiz."><?= e($s['privacy_hosting']) ?></textarea></div>
    <div class="sm:col-span-2"><label class="label" for="p-rec">Dienstleister / Empfänger (Hosting, SMTP-Anbieter usw.)</label><textarea id="p-rec" name="privacy_recipients" rows="3" maxlength="1000" class="input"><?= e($s['privacy_recipients']) ?></textarea></div>
    <div class="sm:col-span-2"><label class="label" for="p-eu">Zuständige EU-Aufsichtsbehörde (optional)</label><input id="p-eu" name="privacy_authority_eu" maxlength="400" class="input" value="<?= e($s['privacy_authority_eu']) ?>"></div>
    <div class="sm:col-span-2"><label class="label" for="p-extra">Zusätzliche Hinweise (optional)</label><textarea id="p-extra" name="privacy_extra" rows="3" maxlength="2000" class="input"><?= e($s['privacy_extra']) ?></textarea></div>
    <div class="sm:col-span-2 flex flex-wrap items-center gap-3"><button class="btn btn-primary" type="submit">Speichern</button><?php if ($s['privacy_updated_at'] !== ''): ?><span class="text-xs text-slate-500 dark:text-neutral-400">Stand der Erklärung: <?= e($s['privacy_updated_at']) ?></span><?php endif; ?></div>
  </form>
</section>

<section class="card p-5 sm:p-6" aria-labelledby="h-hint">
  <h2 id="h-hint" class="mb-2 text-base font-semibold">Wichtig für die Praxis</h2>
  <ul class="list-disc space-y-1.5 pl-5 text-sm text-slate-600 dark:text-neutral-300">
    <li>Der Datenauszug der Administration enthält <strong>nur Konto- und Metadaten</strong>. Nachrichteninhalte kann nur die Person selbst unter Einstellungen &rarr; Meine Daten exportieren. So bleibt das Prinzip „Administratoren sehen keine privaten Nachrichten“ gewahrt.</li>
    <li>Nachrichten anderer Personen werden nie herausgegeben (Rechte Dritter).</li>
    <li>Beim Löschen eines Kontos wählst du, ob alle Nachrichten und Dateien der Person endgültig gelöscht werden oder anonymisiert erhalten bleiben.</li>
    <li>Pflege ausserdem das Verzeichnis der Bearbeitungstätigkeiten (Art. 12 DSG, Art. 30 DSGVO): Vorlage in <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs dark:bg-neutral-800">docs/DATENSCHUTZ.md</code>.</li>
  </ul>
</section>

</div>
