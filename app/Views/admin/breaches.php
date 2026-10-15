<?php use App\Core\Csrf;
$riskL = ['low' => 'Geringes Risiko', 'medium' => 'Mittleres Risiko', 'high' => 'Hohes Risiko'];
$loc = static fn (?string $u): string => $u ? str_replace(' ', 'T', substr(local_dt($u, 'Y-m-d H:i'), 0, 16)) : '';
?>
<div class="space-y-6">
<div class="alert alert-info" role="note"><i class="bx bx-info-circle mt-0.5 text-lg" aria-hidden="true"></i>
  <span><strong>Meldepflicht:</strong> Verletzungen der Datensicherheit mit voraussichtlichem Risiko für Betroffene sind der Aufsichtsbehörde <strong>binnen 72 Stunden</strong> ab Kenntnis zu melden (Art. 33 DSGVO); nach Schweizer DSG „so rasch als möglich“ an den EDÖB (Art. 24 DSG). Bei hohem Risiko sind die Betroffenen zu informieren (Art. 34 DSGVO). Auch nicht meldepflichtige Vorfälle sind zu dokumentieren (Art. 33 Abs. 5 DSGVO). Dieses Register ersetzt keine Rechtsberatung.</span></div>

<section class="card p-5 sm:p-6" aria-labelledby="h-new">
  <h2 id="h-new" class="mb-3 text-base font-semibold">Vorfall erfassen</h2>
  <form method="post" action="<?= e(url('admin/privacy/breaches')) ?>" class="grid gap-4 sm:grid-cols-2">
    <?= Csrf::field() ?>
    <div><label class="label" for="b-found">Kenntnisnahme (Beginn der 72-Stunden-Frist)</label><input id="b-found" name="discovered_at" type="datetime-local" required class="input"></div>
    <div><label class="label" for="b-occ">Zeitpunkt des Vorfalls (falls bekannt)</label><input id="b-occ" name="occurred_at" type="datetime-local" class="input"></div>
    <div class="sm:col-span-2"><label class="label" for="b-desc">Was ist passiert?</label><textarea id="b-desc" name="description" rows="3" required maxlength="3000" class="input"></textarea></div>
    <div><label class="label" for="b-cat">Betroffene Datenkategorien</label><input id="b-cat" name="data_categories" maxlength="500" class="input" placeholder="z. B. E-Mail-Adressen, Nachrichten"></div>
    <div class="grid grid-cols-2 gap-3"><div><label class="label" for="b-n">Betroffene (ca.)</label><input id="b-n" name="affected_count" type="number" min="0" class="input"></div>
      <div><label class="label" for="b-risk">Risiko</label><select id="b-risk" name="risk" class="input"><?php foreach ($riskL as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div></div>
    <div class="sm:col-span-2"><label class="label" for="b-m">Getroffene Massnahmen</label><textarea id="b-m" name="measures" rows="2" maxlength="3000" class="input"></textarea></div>
    <div class="sm:col-span-2"><button class="btn btn-primary" type="submit">Erfassen</button></div>
  </form>
</section>

<section class="card p-5 sm:p-6" aria-labelledby="h-list">
  <h2 id="h-list" class="mb-3 text-base font-semibold">Register</h2>
  <?php if (!$rows): ?><p class="py-4 text-center text-sm text-slate-500">Keine Vorfälle erfasst.</p><?php endif; ?>
  <div class="space-y-3">
  <?php foreach ($rows as $b):
      $deadline = strtotime($b['discovered_at'] . ' UTC') + 72 * 3600;
      $needs = $b['status'] === 'open' && $b['risk'] !== 'low' && !$b['authority_notified_at'] && !$b['authority_reason'];
      $late = $needs && $deadline < time(); ?>
    <details class="rounded-xl border <?= $late ? 'border-red-300 dark:border-red-800' : 'border-slate-200 dark:border-neutral-700' ?> p-3" <?= $needs ? 'open' : '' ?>>
      <summary class="cursor-pointer text-sm font-semibold"><?= e(local_dt($b['discovered_at'])) ?> · <?= e($riskL[$b['risk']]) ?> · <?= $b['status'] === 'open' ? 'offen' : 'abgeschlossen' ?>
        <?php if ($needs): ?><span class="ml-2 rounded-full px-2 py-0.5 text-[11px] font-semibold <?= $late ? 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200' : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200' ?>"><?= $late ? 'Frist überschritten' : 'Meldung bis ' . e(local_dt(gmdate('Y-m-d H:i:s', $deadline))) ?></span><?php endif; ?></summary>
      <p class="mt-2 whitespace-pre-line text-sm"><?= e($b['description']) ?></p>
      <p class="mt-1 text-xs text-slate-500 dark:text-neutral-400"><?= e($b['data_categories'] ?: 'Kategorien nicht angegeben') ?><?= $b['affected_count'] !== null ? ' · ca. ' . (int) $b['affected_count'] . ' Betroffene' : '' ?></p>
      <form method="post" action="<?= e(url('admin/privacy/breaches/' . (int) $b['id'])) ?>" class="mt-3 grid gap-3 sm:grid-cols-2">
        <?= Csrf::field() ?>
        <div><label class="label">Risiko</label><select name="risk" class="input"><?php foreach ($riskL as $k => $l): ?><option value="<?= e($k) ?>" <?= $b['risk'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div><label class="label">Status</label><select name="status" class="input"><option value="open" <?= $b['status'] === 'open' ? 'selected' : '' ?>>Offen</option><option value="closed" <?= $b['status'] === 'closed' ? 'selected' : '' ?>>Abgeschlossen</option></select></div>
        <div><label class="label">Behörde informiert am</label><input name="authority_notified_at" type="datetime-local" class="input" value="<?= e($loc($b['authority_notified_at'])) ?>"></div>
        <div><label class="label">Betroffene informiert am</label><input name="individuals_notified_at" type="datetime-local" class="input" value="<?= e($loc($b['individuals_notified_at'])) ?>"></div>
        <div class="sm:col-span-2"><label class="label">Begründung, falls keine Meldung erfolgt (z. B. kein Risiko)</label><input name="authority_reason" maxlength="500" class="input" value="<?= e((string) $b['authority_reason']) ?>"></div>
        <div class="sm:col-span-2"><label class="label">Massnahmen</label><textarea name="measures" rows="2" maxlength="3000" class="input"><?= e((string) $b['measures']) ?></textarea></div>
        <div class="sm:col-span-2"><button class="btn btn-secondary btn-sm" type="submit">Speichern</button></div>
      </form>
    </details>
  <?php endforeach; ?>
  </div>
</section>
</div>
