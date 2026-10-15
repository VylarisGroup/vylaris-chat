<?php use App\Core\Csrf; ?>
<h1 class="mb-1 text-2xl font-semibold tracking-tight">Neues Passwort festlegen</h1>
<p class="mb-6 text-sm text-slate-500 dark:text-neutral-400">Nach dem Speichern wirst du auf allen Geräten abgemeldet.</p>
<?php if (!empty($error)): ?>
  <div class="alert alert-error" role="alert"><i class="bx bx-error-circle text-lg" aria-hidden="true"></i><span><?= e($error) ?></span></div>
<?php endif; ?>
<form method="post" action="<?= e(url('reset/' . $token)) ?>" class="space-y-4">
  <?= Csrf::field() ?>
  <div>
    <label for="password" class="label">Neues Passwort</label>
    <input id="password" name="password" type="password" required minlength="10" maxlength="72" autofocus autocomplete="new-password" class="input">
    <p class="mt-1 text-xs text-slate-500 dark:text-neutral-400">Mindestens 10 Zeichen, mit Buchstaben und mindestens einer Ziffer oder einem Sonderzeichen.</p>
  </div>
  <div>
    <label for="password2" class="label">Passwort wiederholen</label>
    <input id="password2" name="password2" type="password" required maxlength="72" autocomplete="new-password" class="input">
  </div>
  <button type="submit" class="btn btn-primary w-full">Passwort speichern</button>
</form>
