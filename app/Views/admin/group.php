<?php use App\Core\Csrf; $roles = ['admin' => 'Administrator', 'moderator' => 'Moderator', 'member' => 'Mitglied']; ?>
<a class="mb-4 inline-flex items-center gap-1 text-sm font-medium text-accent hover:underline" href="<?= e(url('admin/groups')) ?>"><i class="bx bx-left-arrow-alt text-lg" aria-hidden="true"></i>Alle Gruppen</a>
<section class="card p-5 sm:p-6">
  <h2 class="text-lg font-semibold"><?= e($group['name']) ?></h2>
  <?php if ($group['description'] !== ''): ?><p class="mt-1 whitespace-pre-wrap text-sm text-slate-600 dark:text-neutral-300"><?= e($group['description']) ?></p><?php endif; ?>
  <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-4">
    <div><dt class="text-slate-500 dark:text-neutral-400">Erstellt</dt><dd class="font-medium"><?= e(local_dt($group['created_at'], 'd.m.Y')) ?></dd></div>
    <div><dt class="text-slate-500 dark:text-neutral-400">Mitglieder</dt><dd class="font-medium"><?= count($members) ?></dd></div>
    <div><dt class="text-slate-500 dark:text-neutral-400">Nachrichten</dt><dd class="font-medium"><?= (int) $counts['messages'] ?></dd></div>
    <div><dt class="text-slate-500 dark:text-neutral-400">Dateien</dt><dd class="font-medium"><?= (int) $counts['files'] ?></dd></div>
  </dl>
</section>
<section class="card mt-6 p-5" aria-labelledby="h-m">
  <h2 id="h-m" class="mb-3 text-base font-semibold">Mitglieder</h2>
  <ul class="divide-y divide-slate-100 dark:divide-neutral-800">
    <?php foreach ($members as $m): ?>
      <li class="flex flex-wrap items-center justify-between gap-3 py-2.5 text-sm">
        <span><span class="font-medium"><?= e($m['display_name']) ?></span> <span class="text-xs text-slate-500 dark:text-neutral-400">· <?= e($m['email']) ?> · <?= e($roles[$m['role']] ?? $m['role']) ?></span><?php if (!(int) $m['is_active']): ?> <span class="text-xs text-red-600">(deaktiviert)</span><?php endif; ?></span>
        <?php if ($m['role'] !== 'admin' && (int) $m['is_active']): ?>
          <form method="post" action="<?= e(url('admin/groups/' . (int) $group['id'] . '/admin')) ?>"><?= Csrf::field() ?><input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>"><button class="btn btn-secondary btn-sm" type="submit">Zum Gruppenadmin ernennen</button></form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <p class="mt-4 text-xs text-slate-500 dark:text-neutral-400">Die Ernennung ist für Notfälle gedacht (z. B. Gruppe ohne aktiven Administrator). Sie wird im Protokoll festgehalten und in der Gruppe als Hinweis angezeigt.</p>
</section>
