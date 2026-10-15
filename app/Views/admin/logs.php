<section class="card p-5" aria-labelledby="h-l">
  <h2 id="h-l" class="sr-only">Protokoll</h2>
  <form method="get" class="mb-4 grid gap-3 sm:grid-cols-5" role="search">
    <div><label class="label" for="f-a">Aktion beginnt mit</label><input id="f-a" name="action" class="input" placeholder="z. B. login" value="<?= e($action) ?>"></div>
    <div><label class="label" for="f-u">Benutzer</label><input id="f-u" name="user" class="input" placeholder="Name oder E-Mail" value="<?= e($who) ?>"></div>
    <div><label class="label" for="f-f">Von</label><input id="f-f" name="from" type="date" class="input" value="<?= e($from) ?>"></div>
    <div><label class="label" for="f-t">Bis</label><input id="f-t" name="to" type="date" class="input" value="<?= e($to) ?>"></div>
    <div class="flex items-end gap-2"><button class="btn btn-primary" type="submit">Filtern</button><a class="btn btn-secondary" href="<?= e(url('admin/logs')) ?>">Zurücksetzen</a></div>
  </form>
  <p class="mb-3 text-sm text-slate-500 dark:text-neutral-400"><?= (int) $total ?> Einträge · Zeiten in der Zeitzone <?= e((string) \App\Core\Config::get('app.timezone', 'Europe/Zurich')) ?></p>
  <div class="overflow-x-auto">
    <table class="w-full min-w-[760px] text-left text-sm">
      <caption class="sr-only">Audit-Protokoll</caption>
      <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500 dark:border-neutral-800 dark:text-neutral-400"><tr><th scope="col" class="py-2 pr-3 font-semibold">Zeit</th><th scope="col" class="px-3 py-2 font-semibold">Benutzer</th><th scope="col" class="px-3 py-2 font-semibold">Aktion</th><th scope="col" class="px-3 py-2 font-semibold">Objekt / Details</th><th scope="col" class="py-2 pl-3 font-semibold">IP</th></tr></thead>
      <tbody class="divide-y divide-slate-100 dark:divide-neutral-800">
        <?php foreach ($rows as $l): ?>
          <tr>
            <td class="whitespace-nowrap py-2 pr-3 text-slate-600 dark:text-neutral-300"><?= e(local_dt($l['created_at'], 'd.m.Y H:i:s')) ?></td>
            <td class="px-3 py-2"><?= e($l['display_name'] ?? '–') ?></td>
            <td class="px-3 py-2 font-mono text-xs"><?= e($l['action']) ?></td>
            <td class="px-3 py-2 text-slate-600 dark:text-neutral-300"><?= $l['target_type'] ? e($l['target_type'] . ' #' . $l['target_id']) : '' ?> <?= e($l['details']) ?></td>
            <td class="py-2 pl-3 font-mono text-xs text-slate-500"><?= e($l['ip']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="py-8 text-center text-slate-500">Keine Einträge gefunden.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php $query = ['action' => $action, 'user' => $who, 'from' => $from, 'to' => $to]; require ROOT_PATH . '/app/Views/partials/pager.php'; ?>
</section>
