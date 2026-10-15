<?php use App\Core\Csrf; ?>
<h1 class="mb-1 text-2xl font-semibold tracking-tight">Willkommen zurück</h1>
<p class="mb-6 text-sm text-slate-500 dark:text-neutral-400">Melde dich mit deiner Firmen-E-Mail-Adresse an.</p>

<?php if (!empty($notice)): ?>
  <div class="alert alert-success" role="status"><i class="bx bx-check-circle text-lg" aria-hidden="true"></i><span><?= e($notice) ?></span></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-error" role="alert"><i class="bx bx-error-circle text-lg" aria-hidden="true"></i><span><?= e($error) ?></span></div>
<?php endif; ?>

<form method="post" action="<?= e(url('login')) ?>" class="space-y-4" autocomplete="on">
  <?= Csrf::field() ?>
  <input type="hidden" name="next" value="<?= e($next ?? '') ?>">
  <div>
    <label for="email" class="label">E-Mail-Adresse</label>
    <input id="email" name="email" type="email" required autofocus autocomplete="username" class="input" value="<?= e($email ?? '') ?>" maxlength="190">
  </div>
  <div>
    <div class="flex items-center justify-between">
      <label for="password" class="label">Passwort</label>
      <a href="<?= e(url('forgot')) ?>" class="text-xs font-medium text-accent hover:underline">Passwort vergessen?</a>
    </div>
    <input id="password" name="password" type="password" required autocomplete="current-password" class="input" maxlength="500">
  </div>
  <button type="submit" class="btn btn-primary w-full">Anmelden</button>
</form>

<?php if (\App\Services\Oidc::enabled()): ?>
  <div class="my-5 flex items-center gap-3 text-xs text-slate-400"><span class="h-px flex-1 bg-slate-200 dark:bg-neutral-700"></span>oder<span class="h-px flex-1 bg-slate-200 dark:bg-neutral-700"></span></div>
  <a class="btn btn-secondary w-full" href="<?= e(url('login/sso' . (!empty($next) ? '?next=' . rawurlencode($next) : ''))) ?>"><i class="bx bx-log-in-circle text-lg" aria-hidden="true"></i><?= e(\App\Services\Settings::get('oidc_button') ?: 'Mit Firmenkonto anmelden') ?></a>
<?php endif; ?>

<?php if (!empty($unverified)): ?>
  <form method="post" action="<?= e(url('verify-resend')) ?>" class="mt-4 text-center">
    <?= Csrf::field() ?>
    <input type="hidden" name="email" value="<?= e($email ?? '') ?>">
    <button type="submit" class="text-sm font-medium text-accent hover:underline">Bestätigungs-Mail erneut senden</button>
  </form>
<?php endif; ?>

<?php if (!empty($registration)): ?>
  <p class="mt-6 text-center text-sm text-slate-500 dark:text-neutral-400">Noch kein Konto? <a class="font-medium text-accent hover:underline" href="<?= e(url('register')) ?>">Registrieren</a></p>
<?php endif; ?>
