<?php use App\Core\Csrf; ?>
<h1 class="mb-1 text-2xl font-semibold tracking-tight">Neues Passwort festlegen</h1>
<p class="mb-6 text-sm text-slate-500 dark:text-neutral-400"><?= e($reason) ?></p>
<?php if (!empty($error)): ?>
  <div class="alert alert-error" role="alert"><i class="bx bx-error-circle text-lg" aria-hidden="true"></i><span><?= e($error) ?></span></div>
<?php endif; ?>
<form method="post" action="<?= e(url('password-change')) ?>" class="space-y-4">
  <?= Csrf::field() ?>
  <div><label for="current" class="label">Aktuelles Passwort</label><input id="current" name="current" type="password" required autocomplete="current-password" class="input" maxlength="500"></div>
  <div><label for="new" class="label">Neues Passwort</label><input id="new" name="new" type="password" required minlength="10" autocomplete="new-password" class="input" maxlength="500"></div>
  <div><label for="repeat" class="label">Neues Passwort wiederholen</label><input id="repeat" name="repeat" type="password" required autocomplete="new-password" class="input" maxlength="500"></div>
  <button type="submit" class="btn btn-primary w-full">Passwort speichern</button>
</form>
<form method="post" action="<?= e(url('logout')) ?>" class="mt-4 text-center"><?= Csrf::field() ?><button class="text-sm font-medium text-accent hover:underline" type="submit">Abmelden</button></form>
