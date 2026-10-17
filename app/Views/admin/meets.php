<?php use App\Core\Csrf; ?>
<div class="space-y-6">
<div class="grid gap-4 sm:grid-cols-3">
  <div class="card p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-neutral-400">Status</p><p class="mt-1 text-2xl font-semibold <?= $enabled && $hasKey ? 'text-emerald-600' : 'text-slate-500' ?>"><?= $enabled && $hasKey ? 'Aktiv' : 'Aus' ?></p></div>
  <div class="card p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-neutral-400">Laufende Meetings</p><p class="mt-1 text-2xl font-semibold"><?= (int) $active ?></p></div>
  <div class="card p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-neutral-400">Meetings insgesamt</p><p class="mt-1 text-2xl font-semibold"><?= (int) $total ?></p></div>
</div>

<section class="card p-5 sm:p-6" aria-labelledby="h-meets">
  <h2 id="h-meets" class="mb-1 text-base font-semibold">Videokonferenzen (TheRedStonee Meets)</h2>
  <p class="mb-4 text-sm text-slate-500 dark:text-neutral-400">Mitarbeitende starten Videomeetings direkt im Chat und nehmen eingebettet im Messenger teil. In Direktchats darf jede Person ein Meeting starten, in Gruppen nur die Moderation; Gäste können beitreten, aber nicht starten. Audio und Video laufen über den Server des Anbieters, im Messenger wird nur gespeichert, wer wann ein Meeting gestartet hat und wie viele Personen teilnehmen. Die Symbole (Kamera in der Chat-Kopfzeile, Meeting-Karten) erscheinen nur, solange die Funktion aktiv ist. Der API-Schlüssel bleibt verschlüsselt auf diesem Server und gelangt nie in den Browser.</p>
  <form method="post" action="<?= e(url('admin/meets/save')) ?>" class="grid gap-4 sm:grid-cols-2">
    <?= Csrf::field() ?>
    <div class="sm:col-span-2"><label class="label" for="m-key">API-Schlüssel</label><input id="m-key" name="api_key" class="input font-mono" maxlength="120" autocomplete="off" spellcheck="false" placeholder="<?= $hasKey ? e($keyHint) . ' (gespeichert, leer lassen zum Beibehalten)' : 'trsm_…' ?>"></div>
    <div class="sm:col-span-2"><label class="label" for="m-sec">Webhook-Geheimnis</label><input id="m-sec" name="webhook_secret" class="input font-mono" maxlength="160" autocomplete="off" spellcheck="false" placeholder="<?= $hasSecret ? 'whsec_… (gespeichert, leer lassen zum Beibehalten)' : 'whsec_…' ?>"><p class="mt-1 text-xs text-slate-500 dark:text-neutral-400">Für die Live-Anzeige der Teilnehmerzahl. Ohne Webhook wird sie beim Öffnen des Chats abgeglichen.</p></div>
 <?php if ($hasKey): ?><div class="sm:col-span-2"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remove_key" value="1" class="h-4 w-4 rounded border-slate-300 text-accent focus:ring-accent"><span>Gespeicherten API-Schlüssel entfernen</span></label><?php if ($keyFromConfig): ?><p class="mt-1 text-xs text-slate-500 dark:text-neutral-400">Der aktuelle Schlüssel stammt aus <code>config/config.php</code>. Ein hier gespeicherter Schlüssel hat Vorrang.</p><?php endif; ?></div><?php endif; ?>
    <?php if ($hasSecret): ?><div class="sm:col-span-2"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remove_secret" value="1" class="h-4 w-4 rounded border-slate-300 text-accent focus:ring-accent"><span>Gespeichertes Webhook-Geheimnis entfernen</span></label><?php if ($secretFromConfig): ?><p class="mt-1 text-xs text-slate-500 dark:text-neutral-400">Das aktuelle Geheimnis stammt aus <code>config/config.php</code>.</p><?php endif; ?></div><?php endif; ?>
    <div class="sm:col-span-2"><label class="flex items-start gap-2 text-sm"><input type="checkbox" name="ring" value="1" <?= $ring ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 rounded border-slate-300 text-accent focus:ring-accent"><span>Beim Start eines Meetings die Geräte der anderen Mitglieder benachrichtigen (Push mit «Annehmen» / «Ablehnen»)<?php if (!$pushOn): ?><br><span class="text-xs text-amber-700 dark:text-amber-400">Push ist noch nicht aktiviert (Betrieb → Push). Ohne Push erscheint nur die Karte im Chat.</span><?php endif; ?></span></label></div>
    <div class="sm:col-span-2"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-accent focus:ring-accent"><span>Videokonferenzen aktivieren</span></label></div>
    <div class="flex flex-wrap gap-2 sm:col-span-2"><button class="btn btn-primary" type="submit">Speichern</button></div>
  </form>
  <form method="post" action="<?= e(url('admin/meets/test')) ?>" class="mt-3"><?= Csrf::field() ?><button class="btn btn-secondary" type="submit" <?= $hasKey ? '' : 'disabled' ?>><i class="bx bx-refresh text-lg" aria-hidden="true"></i>Verbindung testen</button></form>
</section>

<section class="card p-5 sm:p-6" aria-labelledby="h-setup">
  <h2 id="h-setup" class="mb-3 text-base font-semibold">Einrichtung beim Anbieter</h2>
  <ol class="list-decimal space-y-2 pl-5 text-sm">
    <li>Bei TheRedStonee einen Meets-API-Schlüssel anfordern.</li>
    <li>Als erlaubte Einbettungs-Domain (<code>embedOrigins</code>) eintragen lassen: <code class="break-all"><?= e($origin) ?></code></li>
    <li>Als Webhook-Adresse eintragen lassen: <code class="break-all"><?= e($webhookUrl) ?></code> (Ereignisse <code>room.ended</code>, <code>participant.joined</code>, <code>participant.left</code>) und das Webhook-Geheimnis oben speichern.</li>
    <li>Schlüssel speichern, aktivieren und «Verbindung testen» ausführen.</li>
  </ol>
  <p class="mt-4 text-xs text-slate-500 dark:text-neutral-400">Der Browser lädt das Meeting von <code><?= e($embedOrigin) ?></code>. Kamera und Mikrofon werden nur für diese Adresse freigegeben. Datenschutz: Der Anbieter verarbeitet Audio, Video, Anzeigename und IP-Adressen der Teilnehmenden. Bitte den Auftragsverarbeitungsvertrag prüfen und in der Datenschutzerklärung nennen (siehe Abschnitt «Videokonferenzen» dort).</p>
</section>
</div>
