<?php use App\Core\Csrf; ?>
<h1 class="mb-1 text-2xl font-semibold tracking-tight">Passwort vergessen</h1>
<?php if (!empty($sent)): ?>
  <div class="alert alert-success mt-4" role="status"><i class="bx bx-envelope text-lg" aria-hidden="true"></i><span>Falls ein Konto mit dieser Adresse existiert, haben wir dir einen Link zum Zurücksetzen gesendet. Er ist eine Stunde gültig.</span></div>
  <a href="<?= e(url('login')) ?>" class="btn btn-secondary mt-4 w-full">Zur Anmeldung</a>
<?php else: ?>
  <p class="mb-6 text-sm text-slate-500 dark:text-neutral-400">Gib deine E-Mail-Adresse ein. Wir senden dir einen Link zum Zurücksetzen.</p>
  <form method="post" action="<?= e(url('forgot')) ?>" class="space-y-4">
    <?= Csrf::field() ?>
    <div>
      <label for="email" class="label">E-Mail-Adresse</label>
      <input id="email" name="email" type="email" required autofocus autocomplete="email" class="input" maxlength="190">
    </div>
    <button type="submit" class="btn btn-primary w-full">Link senden</button>
  </form>
  <p class="mt-6 text-center text-sm"><a class="font-medium text-accent hover:underline" href="<?= e(url('login')) ?>">Zurück zur Anmeldung</a></p>
<?php endif; ?>
