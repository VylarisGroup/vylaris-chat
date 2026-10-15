<?php use App\Core\Csrf; ?>
<h1 class="mb-1 text-2xl font-semibold tracking-tight">Bestätigungscode</h1>
<p class="mb-6 text-sm text-slate-500 dark:text-neutral-400">Gib den 6-stelligen Code aus deiner Authenticator-App ein. Hast du dein Gerät nicht dabei, kannst du stattdessen einen Wiederherstellungscode verwenden.</p>
<?php if (!empty($error)): ?>
  <div class="alert alert-error" role="alert"><i class="bx bx-error-circle text-lg" aria-hidden="true"></i><span><?= e($error) ?></span></div>
<?php endif; ?>
<form method="post" action="<?= e(url('login/2fa')) ?>" class="space-y-4">
  <?= Csrf::field() ?>
  <div>
    <label for="code" class="label">Code</label>
    <input id="code" name="code" type="text" required autofocus autocomplete="one-time-code" inputmode="text" class="input tracking-widest" maxlength="20">
  </div>
  <button type="submit" class="btn btn-primary w-full">Bestätigen</button>
</form>
<p class="mt-4 text-center text-sm"><a class="font-medium text-accent hover:underline" href="<?= e(url('login')) ?>">Abbrechen und zurück zur Anmeldung</a></p>
