<?php use App\Core\Csrf;
$box = 'mt-0.5 h-4 w-4 rounded border-slate-300 text-accent focus:ring-accent';
$stat = static fn (string $l, string $v, string $cls = '') => '<div class="card p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-neutral-400">' . e($l) . '</p><p class="mt-1 text-2xl font-semibold ' . $cls . '">' . e($v) . '</p></div>';
?>
<div class="space-y-6">
<div class="grid gap-4 sm:grid-cols-4">
  <?= $stat('Konten mit 2FA', $stats['tfa'] . ' / ' . $stats['total']) ?>
  <?= $stat('Admins mit 2FA/SSO', $stats['admins_tfa'] . ' / ' . $stats['admins'], $stats['admins_tfa'] < $stats['admins'] ? 'text-amber-600' : '') ?>
  <?= $stat('Fehlversuche (24 h)', (string) $recentFails, $recentFails > 20 ? 'text-red-600' : '') ?>
  <?= $stat('Gesperrte Konten', (string) count($locked), $locked ? 'text-red-600' : '') ?>
</div>

<?php if ($locked): ?>
<section class="card p-5 sm:p-6" aria-labelledby="h-lock">
  <h2 id="h-lock" class="mb-3 text-base font-semibold">Gesperrte Konten</h2>
  <ul class="divide-y divide-slate-100 dark:divide-neutral-800">
    <?php foreach ($locked as $u): ?><li class="flex items-center gap-3 py-2.5 text-sm"><div class="min-w-0 flex-1"><p class="truncate font-medium"><?= e($u['display_name']) ?></p><p class="truncate text-xs text-slate-500"><?= e($u['email']) ?></p></div>
      <form method="post" action="<?= e(url('admin/security/unlock/' . (int) $u['id'])) ?>"><?= Csrf::field() ?><button class="btn btn-secondary btn-sm" type="submit">Sperre aufheben</button></form></li><?php endforeach; ?>
  </ul>
  <p class="mt-2 text-xs text-slate-500 dark:text-neutral-400">Die Sperre endet nach Ablauf der Zeit automatisch.</p>
</section>
<?php endif; ?>

<section class="card p-5 sm:p-6" aria-labelledby="h-pol">
  <h2 id="h-pol" class="mb-1 text-base font-semibold">Anmelderichtlinien</h2>
  <p class="mb-4 text-sm text-slate-500 dark:text-neutral-400">Gelten für alle Konten mit lokalem Passwort. Konten mit Einmalanmeldung (SSO) folgen den Regeln des Identitätsanbieters.</p>
  <form method="post" action="<?= e(url('admin/security/policy')) ?>" class="grid gap-4 sm:grid-cols-2">
    <?= Csrf::field() ?>
    <label class="flex items-start gap-2 text-sm sm:col-span-2"><input type="checkbox" name="require_2fa_admins" value="1" <?= $s['require_2fa_admins'] === '1' ? 'checked' : '' ?> class="<?= $box ?>"><span><strong>Zwei-Faktor-Authentifizierung für Administratoren vorschreiben.</strong> Administratoren ohne 2FA werden nach der Anmeldung zur Einrichtung geleitet und können nichts anderes tun. <em>Richte zuerst dein eigenes 2FA ein</em> (Einstellungen &rarr; Sicherheit), damit du nicht in der Einrichtung festhängst.</span></label>
    <div><label class="label" for="pw-age">Passwort läuft ab nach … Tagen</label><input id="pw-age" name="password_max_age_days" type="number" min="0" max="1095" class="input" value="<?= (int) $s['password_max_age_days'] ?>"><p class="mt-1 text-xs text-slate-500 dark:text-neutral-400">0 = nie. Nach Ablauf muss bei der nächsten Anmeldung ein neues Passwort gesetzt werden. (Aktuelle Empfehlungen z. B. von NIST raten von regelmässigem Wechsel ohne Anlass ab.)</p></div>
    <div class="grid grid-cols-2 gap-3"><div><label class="label" for="lk-n">Sperre nach … Fehlversuchen</label><input id="lk-n" name="lockout_attempts" type="number" min="3" max="20" class="input" value="<?= (int) $s['lockout_attempts'] ?>"></div>
      <div><label class="label" for="lk-m">… für Minuten</label><input id="lk-m" name="lockout_minutes" type="number" min="1" max="1440" class="input" value="<?= (int) $s['lockout_minutes'] ?>"></div></div>
    <div class="sm:col-span-2"><button class="btn btn-primary" type="submit">Speichern</button></div>
  </form>
</section>

<?php if ((int) $s['password_max_age_days'] > 0): ?>
<section class="card p-5 sm:p-6" aria-labelledby="h-exp">
  <h2 id="h-exp" class="mb-2 text-base font-semibold">Abgelaufene Passwörter (<?= count($expired) ?>)</h2>
  <?php if (!$expired): ?><p class="text-sm text-slate-500">Keine.</p><?php else: ?><p class="text-sm text-slate-600 dark:text-neutral-300"><?= e(implode(', ', array_map(static fn ($u) => $u['display_name'], array_slice($expired, 0, 25)))) ?><?= count($expired) > 25 ? ' …' : '' ?></p><?php endif; ?>
</section>
<?php endif; ?>

<section class="card p-5 sm:p-6" aria-labelledby="h-sso">
  <h2 id="h-sso" class="mb-1 text-base font-semibold">Einmalanmeldung (OpenID Connect)</h2>
  <p class="mb-4 text-sm text-slate-500 dark:text-neutral-400">Für Microsoft Entra ID, Keycloak, Google Workspace, Okta u. a. SAML wird nicht unterstützt. Endpunkte, Schlüssel und Verfahren werden aus der Discovery-URL des Anbieters gelesen; nur <strong>Client-ID und Secret</strong> musst du zusätzlich angeben (sie entstehen bei der Registrierung der Anwendung beim Anbieter). Trage dort diese Weiterleitungs-URL ein: <code class="break-all rounded bg-slate-100 px-1.5 py-0.5 text-xs dark:bg-neutral-800"><?= e($redirectUri) ?></code></p>
  <?php if (!$curl): ?><div class="alert alert-error mb-4"><i class="bx bx-error-circle mt-0.5 text-lg"></i><span>Die PHP-Erweiterung curl fehlt – SSO ist nicht möglich.</span></div><?php endif; ?>
  <form method="post" action="<?= e(url('admin/security/oidc')) ?>" class="grid gap-4 sm:grid-cols-2">
    <?= Csrf::field() ?>
    <div class="sm:col-span-2"><label class="label" for="o-iss">Aussteller-URL oder Discovery-URL</label><input id="o-iss" name="oidc_issuer" class="input" maxlength="300" placeholder="https://idp.firma.ch  oder  https://idp.firma.ch/.well-known/openid-configuration" value="<?= e($s['oidc_issuer']) ?>"></div>
    <?php if (!empty($oidcInfo)): ?>
    <div class="sm:col-span-2 rounded-xl border <?= $oidcInfo['ok'] ? 'border-emerald-200 dark:border-emerald-900' : 'border-red-200 dark:border-red-900' ?> p-3 text-sm">
      <?php if ($oidcInfo['ok']): ?>
        <p class="mb-2 font-medium text-emerald-700 dark:text-emerald-400"><i class="bx bx-check-circle align-middle text-lg" aria-hidden="true"></i> Konfiguration automatisch gelesen</p>
        <dl class="grid gap-x-4 gap-y-1 sm:grid-cols-[11rem_1fr]"><?php foreach ($oidcInfo['info'] as $k => $v): ?><dt class="text-slate-500 dark:text-neutral-400"><?= e($k) ?></dt><dd class="break-all"><?= e($v) ?></dd><?php endforeach; ?></dl>
        <?php foreach ($oidcInfo['warnings'] as $w): ?><p class="mt-2 text-amber-700 dark:text-amber-400"><i class="bx bx-error align-middle" aria-hidden="true"></i> <?= e($w) ?></p><?php endforeach; ?>
      <?php else: ?><p class="font-medium text-red-600 dark:text-red-400"><i class="bx bx-x-circle align-middle text-lg" aria-hidden="true"></i> <?= e($oidcInfo['error']) ?></p><?php endif; ?>
    </div>
    <?php endif; ?>
    <div><label class="label" for="o-id">Client-ID</label><input id="o-id" name="oidc_client_id" class="input" maxlength="300" value="<?= e($s['oidc_client_id']) ?>" autocomplete="off"></div>
    <div><label class="label" for="o-sec">Client-Secret</label><input id="o-sec" name="oidc_client_secret" type="password" class="input" maxlength="500" autocomplete="new-password" placeholder="<?= $hasSecret ? '•••••••• (gespeichert, leer lassen zum Beibehalten)' : '' ?>"></div>
    <div><label class="label" for="o-am">Client-Authentifizierung am Token-Endpunkt</label><select id="o-am" name="oidc_auth_method" class="input"><?php foreach (['auto' => 'Automatisch (Basic bevorzugt)', 'basic' => 'client_secret_basic (HTTP-Basic-Header)', 'post' => 'client_secret_post (Formularfeld)'] as $k => $l): ?><option value="<?= e($k) ?>" <?= ($s['oidc_auth_method'] ?? 'auto') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label class="label" for="o-btn">Text der Anmelde-Schaltfläche</label><input id="o-btn" name="oidc_button" class="input" maxlength="60" value="<?= e($s['oidc_button']) ?>"></div>
    <div><label class="label" for="o-dom">Erlaubte E-Mail-Domains für automatische Kontoanlage</label><input id="o-dom" name="oidc_domains" class="input" maxlength="500" placeholder="firma.ch, firma.com" value="<?= e($s['oidc_domains']) ?>"></div>
    <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="oidc_autocreate" value="1" <?= $s['oidc_autocreate'] === '1' ? 'checked' : '' ?> class="<?= $box ?>"><span>Konten bei der ersten SSO-Anmeldung automatisch anlegen (nur für die erlaubten Domains)</span></label>
    <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="oidc_enabled" value="1" <?= $s['oidc_enabled'] === '1' ? 'checked' : '' ?> class="<?= $box ?>"><span><strong>SSO aktivieren</strong> (die Passwort-Anmeldung bleibt daneben bestehen)</span></label>
    <div class="sm:col-span-2 text-xs text-slate-500 dark:text-neutral-400">Bestehende Konten werden über die bestätigte E-Mail-Adresse verknüpft. SSO-Anmeldungen umgehen die lokale 2FA – der Identitätsanbieter ist für die Mehrfaktor-Prüfung verantwortlich.</div>
    <div class="sm:col-span-2"><button class="btn btn-primary" type="submit">Speichern</button></div>
  </form>
</section>
</div>
