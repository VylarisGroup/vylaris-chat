<?php use App\Core\Csrf;
$statuses = ['all' => 'Alle', 'active' => 'Aktiv', 'inactive' => 'Deaktiviert', 'unverified' => 'E-Mail unbestätigt', 'admins' => 'Administratoren'];
$stateOf = static function (array $u): array {
    if (!(int) $u['is_active']) return ['Deaktiviert', 'bg-slate-200 text-slate-700 dark:bg-neutral-700 dark:text-neutral-200'];
    if ($u['email_verified_at'] === null) return ['E-Mail offen', 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200'];
    return ['Aktiv', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200'];
};
?>
<?php if ($edit): ?>
  <?php [$stLabel] = $stateOf($edit); $isSelf = (int) $edit['id'] === (int) $admin['id']; ?>
  <section class="card mb-8 p-5 sm:p-6" aria-labelledby="h-edit">
    <div class="mb-4 flex items-start justify-between gap-3">
      <div><h2 id="h-edit" class="text-lg font-semibold">Benutzer verwalten</h2>
        <p class="text-sm text-slate-500 dark:text-neutral-400">@<?= e($edit['username']) ?> · Status: <?= e($stLabel) ?> · Letzte Anmeldung: <?= e(local_dt($edit['last_login_at'])) ?></p></div>
      <a class="btn btn-secondary btn-sm" href="<?= e(url('admin/users')) ?>">Schliessen</a>
    </div>
    <form method="post" action="<?= e(url('admin/users/' . (int) $edit['id'] . '/update')) ?>" class="grid gap-4 sm:grid-cols-3">
      <?= Csrf::field() ?>
      <div><label class="label" for="e-name">Anzeigename</label><input id="e-name" name="display_name" class="input" required minlength="2" maxlength="80" value="<?= e($edit['display_name']) ?>"></div>
      <div><label class="label" for="e-mail">E-Mail-Adresse</label><input id="e-mail" name="email" type="email" class="input" required maxlength="190" value="<?= e($edit['email']) ?>"></div>
      <div><label class="label" for="e-role">Rolle</label>
        <select id="e-role" name="role" class="input" <?= $isSelf ? 'disabled aria-describedby="role-hint"' : '' ?>>
          <option value="member" <?= $edit['role'] === 'member' ? 'selected' : '' ?>>Mitglied</option>
          <option value="admin" <?= $edit['role'] === 'admin' ? 'selected' : '' ?>>Administrator</option>
        </select>
        <?php if ($isSelf): ?><input type="hidden" name="role" value="<?= e($edit['role']) ?>"><p id="role-hint" class="mt-1 text-xs text-slate-500">Die eigene Rolle kann nicht geändert werden.</p><?php endif; ?>
      </div>
      <div class="sm:col-span-3"><button class="btn btn-primary" type="submit">Änderungen speichern</button></div>
    </form>
    <div class="mt-6 border-t border-slate-200 pt-5 dark:border-neutral-800">
      <h3 class="mb-3 text-sm font-semibold">Aktionen</h3>
      <div class="flex flex-wrap gap-2">
        <?php
        $act = static function (int $id, string $action, string $label, string $icon, string $cls = 'btn-secondary') {
            echo '<form method="post" action="' . e(url('admin/users/' . $id . '/action')) . '">' . Csrf::field() . '<input type="hidden" name="action" value="' . e($action) . '"><button class="btn ' . $cls . ' btn-sm" type="submit"><i class="bx bx-' . e($icon) . ' text-base" aria-hidden="true"></i>' . e($label) . '</button></form>';
        };
        $eid = (int) $edit['id'];
        if ($mailOk) $act($eid, 'reset_password', 'Passwort-Link senden', 'key');
        if ($edit['email_verified_at'] === null) {
            if ($mailOk) $act($eid, 'resend_verification', 'Bestätigung erneut senden', 'envelope');
            $act($eid, 'verify', 'Als bestätigt markieren', 'check');
        }
        $act($eid, 'unlock', 'Sperre aufheben', 'lock-open');
        $act($eid, 'force_pw_change', 'Passwortwechsel verlangen', 'refresh');
        if (!empty($edit['totp_enabled_at'])) $act($eid, 'reset_2fa', '2FA zurücksetzen', 'shield-x');
        $act($eid, 'revoke_sessions', 'Alle Sitzungen beenden', 'log-out');
        if (!$isSelf) {
            if ((int) $edit['is_active']) $act($eid, 'deactivate', 'Deaktivieren', 'block', 'btn-secondary !text-red-600 dark:!text-red-400');
            else $act($eid, 'activate', 'Aktivieren', 'check-circle');
        }
        ?>
      </div>
      <?php if (!$mailOk): ?><p class="mt-3 text-xs text-slate-500 dark:text-neutral-400">E-Mail-Aktionen sind verfügbar, sobald SMTP konfiguriert ist.</p><?php endif; ?>
      <div class="mt-6 rounded-xl border border-slate-200 p-4 dark:border-neutral-700">
        <h4 class="text-sm font-semibold">Datenschutz: Rechte der betroffenen Person</h4>
        <p class="mt-1 text-xs text-slate-600 dark:text-neutral-400">Auskunft/Datenherausgabe, Berichtigung, Einschränkung und Löschung nach DSG und DSGVO. Nachrichteninhalte sind für Administratoren nie einsehbar und nicht Teil des Auszugs.</p>
        <?php if ($edit['processing_restricted_at'] !== null): ?>
          <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-200">Bearbeitung eingeschränkt seit <?= e(local_dt($edit['processing_restricted_at'])) ?><?= $edit['processing_restricted_note'] !== '' ? ': ' . e($edit['processing_restricted_note']) : '' ?>. Die Person kann nichts senden oder hochladen.</p>
        <?php endif; ?>
        <div class="mt-3 flex flex-wrap gap-2">
          <form method="post" action="<?= e(url('admin/users/' . $eid . '/export')) ?>"><?= Csrf::field() ?><button class="btn btn-secondary btn-sm" type="submit"><i class="bx bx-download text-base" aria-hidden="true"></i>Datenauszug herunterladen</button></form>
          <?php $act($eid, 'clear_profile', 'Status und Profilbild entfernen', 'eraser'); ?>
          <?php if (!$isSelf && $edit['processing_restricted_at'] !== null) $act($eid, 'unrestrict', 'Einschränkung aufheben', 'lock-open-alt'); ?>
        </div>
        <?php if (!$isSelf && $edit['processing_restricted_at'] === null): ?>
          <form method="post" action="<?= e(url('admin/users/' . $eid . '/action')) ?>" class="mt-3 flex flex-col gap-2 sm:flex-row">
            <?= Csrf::field() ?><input type="hidden" name="action" value="restrict">
            <label class="sr-only" for="rn">Grund der Einschränkung</label>
            <input id="rn" name="note" class="input" maxlength="255" placeholder="Grund, z. B. Richtigkeit wird geprüft (Art. 18 DSGVO)">
            <button class="btn btn-secondary btn-sm shrink-0" type="submit"><i class="bx bx-lock-alt text-base" aria-hidden="true"></i>Bearbeitung einschränken</button>
          </form>
        <?php endif; ?>
        <p class="mt-3 text-xs text-slate-500 dark:text-neutral-400">Berichtigung: Name, E-Mail-Adresse und Rolle ändern im Formular oben.
          <a class="font-medium text-accent hover:underline" href="<?= e(url('admin/privacy?q=' . rawurlencode($edit['email']))) ?>">Zur Datenschutz-Verwaltung</a></p>
        <?php if (!empty($editRequests)): ?>
          <ul class="mt-3 divide-y divide-slate-100 text-xs dark:divide-neutral-800">
            <?php foreach ($editRequests as $rq): ?><li class="py-1.5">Anfrage #<?= (int) $rq['id'] ?> · <?= e(\App\Services\Privacy::TYPES[$rq['type']] ?? $rq['type']) ?> · <?= e(\App\Services\Privacy::STATUSES[$rq['status']]) ?> · Frist <?= e(local_dt($rq['due_at'], 'd.m.Y')) ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
      <?php if (!$isSelf): ?>
        <form method="post" action="<?= e(url('admin/users/' . $eid . '/action')) ?>" class="mt-6 rounded-xl border border-red-200 p-4 dark:border-red-900">
          <?= Csrf::field() ?><input type="hidden" name="action" value="delete">
          <h4 class="text-sm font-semibold text-red-700 dark:text-red-400">Konto löschen (Recht auf Löschung)</h4>
          <p class="mt-1 text-xs text-slate-600 dark:text-neutral-400">Name, E-Mail, Profilbild, Einstellungen und Sitzungsdaten werden entfernt. Nicht rückgängig zu machen.</p>
          <label class="mt-3 flex items-start gap-2 text-sm"><input type="checkbox" name="erase_content" value="1" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-accent focus:ring-accent"><span>Auch alle Nachrichten und Dateien dieser Person endgültig löschen. <span class="block text-xs text-slate-500 dark:text-neutral-400">Ohne diese Option bleiben sie für die Gesprächspartner als „Gelöschter Benutzer“ ohne Namensbezug bestehen.</span></span></label>
          <label class="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" name="confirm" value="1" required class="h-4 w-4 rounded border-slate-300 text-accent focus:ring-accent"> Ich möchte dieses Konto endgültig anonymisieren.</label>
          <button class="btn btn-danger btn-sm mt-3" type="submit">Konto löschen</button>
        </form>
      <?php endif; ?>
    </div>
  </section>
<?php endif; ?>

<div class="grid gap-6 xl:grid-cols-2">
  <section class="card p-5" aria-labelledby="h-invite">
    <h2 id="h-invite" class="mb-1 text-base font-semibold">Per E-Mail einladen</h2>
    <p class="mb-4 text-sm text-slate-500 dark:text-neutral-400">Die Person erhält einen Link (7 Tage gültig) und legt ihr Konto selbst an.<?= $mailOk ? '' : ' SMTP ist noch nicht konfiguriert: Du erhältst den Link zur manuellen Weitergabe.' ?></p>
    <form method="post" action="<?= e(url('admin/users/invite')) ?>" class="flex flex-col gap-3 sm:flex-row">
      <?= Csrf::field() ?>
      <label class="sr-only" for="inv-mail">E-Mail-Adresse</label>
      <input id="inv-mail" name="email" type="email" required maxlength="190" class="input" placeholder="name@firma.ch">
      <label class="sr-only" for="inv-role">Rolle</label>
      <select id="inv-role" name="role" class="input sm:w-44"><option value="member">Mitglied</option><option value="admin">Administrator</option></select>
      <button class="btn btn-primary shrink-0" type="submit">Einladen</button>
    </form>
  </section>
  <section class="card p-5" aria-labelledby="h-create">
    <h2 id="h-create" class="mb-1 text-base font-semibold">Konto direkt anlegen</h2>
    <p class="mb-4 text-sm text-slate-500 dark:text-neutral-400">Ohne Passwort erhält die Person eine E-Mail, um selbst eines festzulegen (empfohlen).</p>
    <form method="post" action="<?= e(url('admin/users/create')) ?>" class="grid gap-3 sm:grid-cols-2">
      <?= Csrf::field() ?>
      <div><label class="label" for="c-name">Anzeigename</label><input id="c-name" name="display_name" class="input" required minlength="2" maxlength="80"></div>
      <div><label class="label" for="c-mail">E-Mail-Adresse</label><input id="c-mail" name="email" type="email" class="input" required maxlength="190"></div>
      <div><label class="label" for="c-pass">Anfangspasswort (optional)</label><input id="c-pass" name="password" type="password" class="input" autocomplete="new-password" minlength="10" maxlength="72"></div>
      <div><label class="label" for="c-role">Rolle</label><select id="c-role" name="role" class="input"><option value="member">Mitglied</option><option value="admin">Administrator</option></select></div>
      <div class="sm:col-span-2"><button class="btn btn-primary" type="submit">Konto anlegen</button></div>
    </form>
  </section>
</div>

<section class="card mt-8 p-5" aria-labelledby="h-list">
  <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <h2 id="h-list" class="text-base font-semibold">Alle Benutzer <span class="font-normal text-slate-500">(<?= (int) $total ?>)</span></h2>
    <form method="get" class="flex gap-2" role="search">
      <label class="sr-only" for="q">Suchen</label>
      <input id="q" name="q" type="search" class="input sm:w-56" placeholder="Name oder E-Mail" value="<?= e($q) ?>">
      <label class="sr-only" for="st">Status</label>
      <select id="st" name="status" class="input sm:w-44"><?php foreach ($statuses as $k => $l): ?><option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
      <button class="btn btn-secondary" type="submit">Filtern</button>
    </form>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full min-w-[640px] text-left text-sm">
      <caption class="sr-only">Benutzerliste</caption>
      <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500 dark:border-neutral-800 dark:text-neutral-400">
        <tr><th scope="col" class="py-2 pr-3 font-semibold">Name</th><th scope="col" class="px-3 py-2 font-semibold">Rolle</th><th scope="col" class="px-3 py-2 font-semibold">Status</th><th scope="col" class="px-3 py-2 font-semibold">Letzte Anmeldung</th><th scope="col" class="py-2 pl-3 text-right font-semibold"><span class="sr-only">Aktionen</span></th></tr>
      </thead>
      <tbody class="divide-y divide-slate-100 dark:divide-neutral-800">
        <?php foreach ($users as $u): [$sl, $sc] = $stateOf($u); ?>
          <tr>
            <td class="py-2.5 pr-3"><p class="font-medium"><?= e($u['display_name']) ?><?php if ($u['processing_restricted_at'] !== null): ?> <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 dark:bg-amber-950 dark:text-amber-200">eingeschränkt</span><?php endif; ?></p><p class="text-xs text-slate-500 dark:text-neutral-400"><?= e($u['email']) ?></p></td>
            <td class="px-3 py-2.5"><?= $u['role'] === 'admin' ? 'Administrator' : 'Mitglied' ?></td>
            <td class="px-3 py-2.5"><span class="rounded-full px-2.5 py-0.5 text-xs font-semibold <?= $sc ?>"><?= e($sl) ?></span></td>
            <td class="px-3 py-2.5 text-slate-600 dark:text-neutral-300"><?= e(local_dt($u['last_login_at'])) ?></td>
            <td class="py-2.5 pl-3 text-right"><a class="btn btn-secondary btn-sm" href="<?= e(url('admin/users?edit=' . (int) $u['id'])) ?>" aria-label="<?= e($u['display_name']) ?> verwalten">Verwalten</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$users): ?><tr><td colspan="5" class="py-8 text-center text-slate-500">Keine Benutzer gefunden.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php $query = ['q' => $q, 'status' => $status === 'all' ? '' : $status]; require ROOT_PATH . '/app/Views/partials/pager.php'; ?>
</section>

<?php if ($invitations): ?>
<section class="card mt-8 p-5" aria-labelledby="h-pending">
  <h2 id="h-pending" class="mb-3 text-base font-semibold">Offene Einladungen</h2>
  <ul class="divide-y divide-slate-100 dark:divide-neutral-800">
    <?php foreach ($invitations as $i): ?>
      <li class="flex flex-wrap items-center justify-between gap-3 py-2.5 text-sm">
        <span><span class="font-medium"><?= e($i['email']) ?></span> <span class="text-xs text-slate-500 dark:text-neutral-400">· <?= $i['role'] === 'admin' ? 'Administrator' : 'Mitglied' ?> · gültig bis <?= e(local_dt($i['expires_at'])) ?> · von <?= e($i['inviter'] ?? '–') ?></span></span>
        <form method="post" action="<?= e(url('admin/invitations/' . (int) $i['id'] . '/revoke')) ?>"><?= Csrf::field() ?><button class="btn btn-secondary btn-sm" type="submit">Zurückziehen</button></form>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
