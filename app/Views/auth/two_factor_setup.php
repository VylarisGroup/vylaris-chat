<?php use App\Core\Csrf; use App\Middleware\SecurityHeaders; ?>
<?php if (!empty($codes)): ?>
  <h1 class="mb-1 text-2xl font-semibold tracking-tight">Zwei-Faktor-Authentifizierung aktiv</h1>
  <p class="mb-4 text-sm text-slate-500 dark:text-neutral-400">Bewahre diese Wiederherstellungscodes sicher auf (z. B. im Passwortmanager). Jeder Code funktioniert genau einmal und ersetzt den App-Code, falls du dein Gerät verlierst. Sie werden nur jetzt angezeigt.</p>
  <ul class="mb-4 grid grid-cols-2 gap-2 rounded-xl border border-slate-200 p-3 font-mono text-sm dark:border-neutral-700">
    <?php foreach ($codes as $c): ?><li><?= e($c) ?></li><?php endforeach; ?>
  </ul>
  <a class="btn btn-primary w-full" href="<?= e(url('')) ?>">Weiter zum Messenger</a>
<?php else: ?>
  <h1 class="mb-1 text-2xl font-semibold tracking-tight">Zwei-Faktor-Authentifizierung einrichten</h1>
  <?php if (!empty($forced)): ?><div class="alert alert-info" role="status"><i class="bx bx-info-circle text-lg" aria-hidden="true"></i><span>Für Administratorkonten ist die Zwei-Faktor-Authentifizierung Pflicht.</span></div><?php endif; ?>
  <ol class="mb-4 list-decimal space-y-1 pl-5 text-sm text-slate-600 dark:text-neutral-300">
    <li>Öffne eine Authenticator-App (z. B. FreeOTP, Aegis, Google Authenticator, Microsoft Authenticator).</li>
    <li>Scanne den QR-Code oder gib den Schlüssel manuell ein.</li>
    <li>Gib unten den angezeigten 6-stelligen Code ein.</li>
  </ol>
  <div class="mb-4 flex flex-col items-center gap-2">
    <canvas id="qr" class="h-48 w-48 rounded-lg bg-white p-2" data-uri="<?= e($uri) ?>" aria-label="QR-Code zum Einrichten"></canvas>
    <p class="text-xs text-slate-500 dark:text-neutral-400">Manueller Schlüssel:</p>
    <code class="break-all rounded bg-slate-100 px-2 py-1 text-xs dark:bg-neutral-800"><?= e(trim(chunk_split($secret, 4, ' '))) ?></code>
  </div>
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><i class="bx bx-error-circle text-lg" aria-hidden="true"></i><span><?= e($error) ?></span></div><?php endif; ?>
  <form method="post" action="<?= e(url('security/2fa')) ?>" class="space-y-4">
    <?= Csrf::field() ?>
    <div><label for="code" class="label">Code aus der App</label><input id="code" name="code" required autofocus inputmode="numeric" autocomplete="one-time-code" class="input tracking-widest" maxlength="10"></div>
    <button class="btn btn-primary w-full" type="submit">Aktivieren</button>
  </form>
  <p class="mt-4 text-center text-sm"><a class="font-medium text-accent hover:underline" href="<?= e(url('')) ?>">Später</a></p>
  <script type="module" src="<?= e(url('assets/js/twofa.js')) ?>"></script>
<?php endif; ?>
