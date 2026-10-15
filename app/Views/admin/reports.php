<?php use App\Core\Csrf; ?>
<div class="alert alert-info"><i class="bx bx-lock-alt mt-0.5 text-lg" aria-hidden="true"></i><p>Du siehst hier ausschliesslich die einzelne gemeldete Nachricht, nicht die übrige Unterhaltung. Die Einsicht wird im Protokoll festgehalten.</p></div>
<nav class="mb-4 flex gap-2" aria-label="Filter">
  <a class="btn btn-sm <?= $status === 'open' ? 'btn-primary' : 'btn-secondary' ?>" href="?status=open">Offen</a>
  <a class="btn btn-sm <?= $status === 'all' ? 'btn-primary' : 'btn-secondary' ?>" href="?status=all">Alle</a>
</nav>
<div class="space-y-4">
  <?php foreach ($rows as $x): $open = $x['status'] === 'open'; ?>
    <article class="card p-5" aria-label="Meldung <?= (int) $x['id'] ?>">
      <header class="flex flex-wrap items-center justify-between gap-2 text-sm">
        <p><span class="font-semibold"><?= e($x['reporter'] ?? 'Gelöschter Benutzer') ?></span> meldet eine Nachricht von <span class="font-semibold"><?= e($x['sender'] ?? 'Unbekannt') ?></span></p>
        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold <?= $open ? 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200' : 'bg-slate-200 text-slate-700 dark:bg-neutral-700 dark:text-neutral-200' ?>"><?= $open ? 'Offen' : ($x['status'] === 'actioned' ? 'Entfernt' : 'Abgelehnt') ?></span>
      </header>
      <p class="mt-1 text-xs text-slate-500 dark:text-neutral-400"><?= e(local_dt($x['created_at'])) ?> · <?= $x['conv_type'] === 'group' ? 'Gruppe „' . e($x['conv_name']) . '“' : 'Einzelchat' ?></p>
      <p class="mt-3 text-sm"><span class="font-medium">Begründung:</span> <?= e($x['reason']) ?></p>
      <blockquote class="mt-3 whitespace-pre-wrap break-words rounded-xl border-l-4 border-accent bg-slate-50 px-4 py-3 text-sm dark:bg-neutral-800">
        <?php if ($x['deleted_at'] !== null): ?><em class="text-slate-500">Die Nachricht wurde gelöscht.</em><?php else: ?><?= e($x['body']) ?><?php endif; ?>
      </blockquote>
      <?php if ($open): ?>
        <div class="mt-4 flex flex-wrap gap-2">
          <?php if ($x['deleted_at'] === null): ?>
            <form method="post" action="<?= e(url('admin/reports/' . (int) $x['id'])) ?>"><?= Csrf::field() ?><input type="hidden" name="action" value="remove"><button class="btn btn-danger btn-sm" type="submit"><i class="bx bx-trash text-base" aria-hidden="true"></i>Nachricht entfernen</button></form>
          <?php endif; ?>
          <form method="post" action="<?= e(url('admin/reports/' . (int) $x['id'])) ?>"><?= Csrf::field() ?><input type="hidden" name="action" value="dismiss"><button class="btn btn-secondary btn-sm" type="submit">Meldung ablehnen</button></form>
        </div>
      <?php elseif ($x['handler']): ?>
        <p class="mt-3 text-xs text-slate-500 dark:text-neutral-400">Bearbeitet von <?= e($x['handler']) ?> am <?= e(local_dt($x['handled_at'])) ?></p>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
  <?php if (!$rows): ?><div class="card p-10 text-center text-sm text-slate-500"><i class="bx bx-check-circle mb-2 block text-4xl text-emerald-500" aria-hidden="true"></i><?= $status === 'open' ? 'Keine offenen Meldungen.' : 'Es liegen keine Meldungen vor.' ?></div><?php endif; ?>
</div>
