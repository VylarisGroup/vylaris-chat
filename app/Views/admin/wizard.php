<?php use App\Core\Csrf;
$steps = $w['steps'];
$total = count($steps) + 1; // + Überprüfung
$box = 'mt-0.5 h-4 w-4 rounded border-slate-300 text-accent focus:ring-accent';
$when = static fn (array $f): string => !empty($f['when']) ? ' data-when="' . e(json_encode($f['when'], JSON_UNESCAPED_UNICODE)) . '"' : '';
?>
<div class="mx-auto max-w-2xl">
  <form method="post" action="<?= e(url($w['action'])) ?>" class="card p-5 sm:p-6" data-wizard data-total="<?= (int) $total ?>" novalidate>
    <?= Csrf::field() ?>
    <ol class="mb-5 flex items-center gap-2" aria-label="Fortschritt" data-wizard-progress>
<?php for ($i = 0; $i < $total; $i++): ?>
      <li class="h-1.5 flex-1 rounded-full bg-slate-200 dark:bg-neutral-700" data-dot></li>
<?php endfor; ?>
    </ol>
    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-neutral-400" data-wizard-counter aria-live="polite"></p>

<?php foreach ($steps as $i => $st): ?>
    <section data-step data-title="<?= e($st['title']) ?>" <?php if (!empty($st['actions'])): ?>data-actions="<?= e(json_encode($st['actions'], JSON_UNESCAPED_UNICODE)) ?>"<?php endif; ?> aria-labelledby="ws-<?= (int) $i ?>">
      <h2 id="ws-<?= (int) $i ?>" class="text-lg font-semibold"><?= e($st['title']) ?></h2>
      <?php if (!empty($st['intro'])): ?><p class="mt-1 break-words text-sm text-slate-500 dark:text-neutral-400"><?= e($st['intro']) ?></p><?php endif; ?>
      <div class="mt-4 space-y-4">
<?php foreach ($st['fields'] as $f):
    $id = 'wf-' . $f['name'];
    $val = (string) ($f['value'] ?? '');
    $attrs = ($f['name'] ? ' name="' . e($f['name']) . '"' : '') . (!empty($f['required']) ? ' required' : '') . (isset($f['min']) ? ' min="' . (int) $f['min'] . '"' : '') . (isset($f['max']) && ($f['type'] ?? '') === 'number' ? ' max="' . (int) $f['max'] . '"' : '') . (isset($f['max']) && ($f['type'] ?? '') !== 'number' ? ' maxlength="' . (int) $f['max'] . '"' : '') . (isset($f['min']) && ($f['type'] ?? '') !== 'number' ? ' minlength="' . (int) $f['min'] . '"' : '') . (!empty($f['placeholder']) ? ' placeholder="' . e($f['placeholder']) . '"' : '') . (isset($f['autocomplete']) ? ' autocomplete="' . e($f['autocomplete']) . '"' : '');
    $meta = ' data-label="' . e($f['label']) . '"' . (!empty($f['secret']) ? ' data-secret="1"' : '');
    $type = $f['type'] ?? 'text';
    if ($type === 'hidden') { echo '<input type="hidden" name="' . e($f['name']) . '" value="' . e($val) . '">'; continue; }
?>
        <div<?= $when($f) ?>>
<?php if ($type === 'radio'): ?>
          <fieldset><legend class="label"><?= e($f['label']) ?></legend><div class="space-y-2">
<?php foreach ($f['options'] as $ov => $ol): ?>
            <label class="flex items-start gap-2 rounded-xl border border-slate-200 p-3 text-sm dark:border-neutral-700"><input type="radio" <?= 'name="' . (!empty($f['transient']) ? 'wiz_' : '') . e($f['name']) . '"' ?> data-field="<?= e($f['name']) ?>"<?= $meta ?> data-text="<?= e($ol) ?>" value="<?= e((string) $ov) ?>" class="<?= $box ?>" <?= (string) $ov === $val ? 'checked' : '' ?>><span><?= e($ol) ?></span></label>
<?php endforeach; ?>
          </div></fieldset>
<?php elseif ($type === 'select'): ?>
          <label class="label" for="<?= e($id) ?>"><?= e($f['label']) ?></label>
          <select id="<?= e($id) ?>" class="input" data-field="<?= e($f['name']) ?>"<?= $meta ?><?= $attrs ?>><?php foreach ($f['options'] as $ov => $ol): ?><option value="<?= e((string) $ov) ?>" <?= (string) $ov === $val ? 'selected' : '' ?>><?= e($ol) ?></option><?php endforeach; ?></select>
<?php elseif ($type === 'checks'): ?>
          <fieldset><legend class="label"><?= e($f['label']) ?></legend><div class="max-h-48 space-y-1 overflow-y-auto rounded-xl border border-slate-200 p-2 dark:border-neutral-700">
<?php foreach ($f['options'] as $ov => $ol): ?>
            <label class="flex items-start gap-2 px-1 py-1 text-sm"><input type="checkbox" name="<?= e($f['name']) ?>" value="<?= e((string) $ov) ?>" data-checks="<?= e($f['label']) ?>" data-text="<?= e($ol) ?>" class="<?= $box ?>"><span><?= e($ol) ?></span></label>
<?php endforeach; ?>
          </div></fieldset>
<?php elseif ($type === 'checkbox'): ?>
          <label class="flex items-start gap-2 text-sm"><input id="<?= e($id) ?>" type="checkbox" value="1" class="<?= $box ?>" data-field="<?= e($f['name']) ?>"<?= $meta ?><?= $attrs ?> <?= $val === '1' ? 'checked' : '' ?>><span><?= e($f['label']) ?></span></label>
<?php else: ?>
          <label class="label" for="<?= e($id) ?>"><?= e($f['label']) ?></label>
          <input id="<?= e($id) ?>" type="<?= e($type) ?>" class="input" data-field="<?= e($f['name']) ?>"<?= $meta ?><?= $attrs ?> value="<?= e($val) ?>">
<?php endif; ?>
          <?php if (!empty($f['help'])): ?><p class="mt-1 text-xs text-slate-500 dark:text-neutral-400"><?= e($f['help']) ?></p><?php endif; ?>
        </div>
<?php endforeach; ?>
      </div>
    </section>
<?php endforeach; ?>

    <section data-step data-review hidden data-title="Überprüfen" aria-labelledby="ws-review">
      <h2 id="ws-review" class="text-lg font-semibold">Alles richtig?</h2>
      <p class="mt-1 text-sm text-slate-500 dark:text-neutral-400">Prüfe deine Angaben. Mit «<?= e($w['submit']) ?>» werden sie gespeichert.<?= !empty($w['after']) ? ' ' . e($w['after']) : '' ?></p>
      <dl class="mt-4 divide-y divide-slate-200 rounded-xl border border-slate-200 text-sm dark:divide-neutral-700 dark:border-neutral-700" data-review-list></dl>
    </section>

    <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
      <a class="btn btn-secondary" href="<?= e(url('admin/wizard')) ?>" data-wizard-cancel>Abbrechen</a>
      <div class="flex gap-2">
        <button type="button" class="btn btn-secondary hidden" data-wizard-prev>Zurück</button>
        <button type="button" class="btn btn-primary hidden" data-wizard-next>Weiter</button>
        <button type="submit" class="btn btn-primary" data-wizard-submit><?= e($w['submit']) ?></button>
      </div>
    </div>
  </form>
  <p class="mt-3 text-center text-xs text-slate-500 dark:text-neutral-400">Lieber alles auf einmal? <a class="underline" href="<?= e(url('admin/' . (['user' => 'users', 'smtp' => 'settings', 'sso' => 'security', 'backup' => 'backup'][$name] ?? ''))) ?>">Zum klassischen Formular</a></p>
</div>
<script src="<?= e(url('assets/js/wizard.js')) ?>" defer></script>
