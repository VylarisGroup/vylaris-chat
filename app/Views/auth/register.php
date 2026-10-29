<?php use App\Core\Csrf; ?>
<h1 class="mb-1 text-2xl font-semibold tracking-tight">Konto erstellen</h1>
<p class="mb-6 text-sm text-slate-500 dark:text-neutral-400">Du erhältst eine E-Mail zur Bestätigung deiner Adresse.</p>
<?php if (!empty($error)): ?>
  <div class="alert alert-error" role="alert"><i class="bx bx-error-circle text-lg" aria-hidden="true"></i><span><?= e($error) ?></span></div>
<?php endif; ?>
<form method="post" action="<?= e(url('register')) ?>" class="space-y-4">
  <?= Csrf::field() ?>
  <div>
    <label for="name" class="label">Anzeigename</label>
    <input id="name" name="name" type="text" required minlength="2" maxlength="80" autocomplete="name" class="input" value="<?= e($old['name'] ?? '') ?>">
  </div>
  <div>
    <label for="email" class="label">E-Mail-Adresse</label>
    <input id="email" name="email" type="email" required maxlength="190" autocomplete="email" class="input" value="<?= e($old['email'] ?? '') ?>">
  </div>
  <div>
    <label for="password" class="label">Passwort</label>
    <input id="password" name="password" type="password" required minlength="10" maxlength="72" autocomplete="new-password" class="input" aria-describedby="pwhint">
    <p id="pwhint" class="mt-1 text-xs text-slate-500 dark:text-neutral-400">Mindestens 10 Zeichen, mit Buchstaben und mindestens einer Ziffer oder einem Sonderzeichen.</p>
  </div>
  <div>
    <label for="password2" class="label">Passwort wiederholen</label>
    <input id="password2" name="password2" type="password" required maxlength="72" autocomplete="new-password" class="input">
  </div>
  <p class="text-xs text-slate-500 dark:text-neutral-400">Hinweis zur Bearbeitung deiner Daten: siehe <a class="font-medium text-accent hover:underline" href="<?= e(url('privacy')) ?>" target="_blank" rel="noopener">Datenschutzerklärung</a>.</p>
  <button type="submit" class="btn btn-primary w-full">Registrieren</button>
</form>
<p class="mt-6 text-center text-sm text-slate-500 dark:text-neutral-400">Bereits registriert? <a class="font-medium text-accent hover:underline" href="<?= e(url('login')) ?>">Anmelden</a></p>
