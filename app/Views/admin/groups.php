<section class="card p-5" aria-labelledby="h-g">
  <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <h2 id="h-g" class="text-base font-semibold">Alle Gruppen <span class="font-normal text-slate-500">(<?= (int) $total ?>)</span></h2>
    <form method="get" class="flex gap-2" role="search"><label class="sr-only" for="q">Suchen</label><input id="q" name="q" type="search" class="input sm:w-56" placeholder="Gruppenname" value="<?= e($q) ?>"><button class="btn btn-secondary" type="submit">Suchen</button></form>
  </div>
  <p class="mb-4 text-sm text-slate-500 dark:text-neutral-400">Hier siehst du Struktur und Mitglieder, aber keine Nachrichteninhalte.</p>
  <div class="overflow-x-auto">
    <table class="w-full min-w-[560px] text-left text-sm">
      <caption class="sr-only">Gruppenliste</caption>
      <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500 dark:border-neutral-800 dark:text-neutral-400"><tr><th scope="col" class="py-2 pr-3 font-semibold">Name</th><th scope="col" class="px-3 py-2 font-semibold">Mitglieder</th><th scope="col" class="px-3 py-2 font-semibold">Nachrichten</th><th scope="col" class="px-3 py-2 font-semibold">Erstellt</th><th scope="col" class="py-2 pl-3"><span class="sr-only">Details</span></th></tr></thead>
      <tbody class="divide-y divide-slate-100 dark:divide-neutral-800">
        <?php foreach ($groups as $g): ?>
          <tr>
            <td class="py-2.5 pr-3 font-medium"><?= e($g['name']) ?><?php if ((int) $g['admins'] === 0): ?> <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-950 dark:text-amber-200">ohne Admin</span><?php endif; ?></td>
            <td class="px-3 py-2.5"><?= (int) $g['members'] ?></td><td class="px-3 py-2.5"><?= (int) $g['messages'] ?></td>
            <td class="px-3 py-2.5 text-slate-600 dark:text-neutral-300"><?= e(local_dt($g['created_at'], 'd.m.Y')) ?></td>
            <td class="py-2.5 pl-3 text-right"><a class="btn btn-secondary btn-sm" href="<?= e(url('admin/groups/' . (int) $g['id'])) ?>" aria-label="Details zu <?= e($g['name']) ?>">Details</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$groups): ?><tr><td colspan="5" class="py-8 text-center text-slate-500">Keine Gruppen gefunden.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php $query = ['q' => $q]; require ROOT_PATH . '/app/Views/partials/pager.php'; ?>
</section>
