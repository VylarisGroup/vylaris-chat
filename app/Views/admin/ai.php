<?php use App\Core\Csrf; ?>
<div class="space-y-6">
<div class="grid gap-4 sm:grid-cols-3">
  <div class="card p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-neutral-400">Status</p><p class="mt-1 text-2xl font-semibold <?= $enabled ? 'text-emerald-600' : 'text-slate-500' ?>"><?= $enabled ? 'Aktiv' : 'Aus' ?></p></div>
  <div class="card p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-neutral-400">Chats mit KI</p><p class="mt-1 text-2xl font-semibold"><?= (int) $chats ?></p></div>
  <div class="card p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-neutral-400">KI-Antworten</p><p class="mt-1 text-2xl font-semibold"><?= (int) $answers ?></p></div>
</div>

<section class="card p-5 sm:p-6" aria-labelledby="h-ai">
  <h2 id="h-ai" class="mb-1 text-base font-semibold">KI-Assistent (OpenAI-kompatibler Endpunkt)</h2>
  <p class="mb-4 text-sm text-slate-500 dark:text-neutral-400">Es gibt genau eine KI für die ganze Installation. Sie erscheint wie eine Person (@<?= e($username) ?>), kann im Einzelchat angeschrieben und von der Moderation zu Gruppen hinzugefügt werden und antwortet in Gruppen nur, wenn sie erwähnt oder wenn auf ihre Nachricht geantwortet wird. Jede Antwort trägt den Hinweis «KI-Antwort». Gäste können die KI nicht nutzen. Privatchats und Notizen anderer Personen sind für die KI nie sichtbar, sie erhält nur den Verlauf der Chats, in denen sie Mitglied ist.</p>
  <form method="post" action="<?= e(url('admin/ai/save')) ?>" class="grid gap-4 sm:grid-cols-2">
    <?= Csrf::field() ?>
    <div><label class="label" for="a-name">Anzeigename</label><input id="a-name" name="name" class="input" maxlength="80" value="<?= e($name) ?>"></div>
    <div><label class="label" for="a-model">Modell</label><input id="a-model" name="model" class="input font-mono" maxlength="120" value="<?= e($model) ?>" placeholder="z. B. gpt-4o-mini" spellcheck="false"></div>
    <div class="sm:col-span-2"><label class="label" for="a-ep">Endpunkt (Basis-Adresse)</label><input id="a-ep" name="endpoint" class="input font-mono" maxlength="300" value="<?= e($endpoint) ?>" placeholder="https://api.openai.com/v1" spellcheck="false"><p class="mt-1 text-xs text-slate-500 dark:text-neutral-400">Der Messenger sendet <code>POST &lt;Adresse&gt;/chat/completions</code>. Funktioniert mit OpenAI und kompatiblen Diensten (z. B. Azure-kompatible Gateways, Mistral, Groq, OpenRouter, lokale Server wie Ollama oder vLLM).</p></div>
    <div class="sm:col-span-2"><label class="label" for="a-key">API-Schlüssel</label><input id="a-key" name="api_key" type="password" class="input font-mono" maxlength="300" autocomplete="new-password" spellcheck="false" placeholder="<?= $hasKey ? e($keyHint) . ' (gespeichert, leer lassen zum Beibehalten)' : 'sk-… (bei lokalen Servern oft leer)' ?>"></div>
    <?php if ($hasKey): ?><div class="sm:col-span-2"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remove_key" value="1" class="h-4 w-4 rounded border-slate-300 text-accent focus:ring-accent"><span>Gespeicherten API-Schlüssel entfernen</span></label></div><?php endif; ?>
    <div class="sm:col-span-2"><label class="label" for="a-prompt">Systemprompt</label><textarea id="a-prompt" name="prompt" rows="5" maxlength="4000" class="input"><?= e($prompt) ?></textarea><p class="mt-1 text-xs text-slate-500 dark:text-neutral-400">Wird jeder Anfrage vorangestellt. Datum, Chat-Name und Hinweise zur Namensangabe fügt der Messenger automatisch an.</p></div>
    <div><label class="label" for="a-temp">Temperatur (0 bis 2)</label><input id="a-temp" name="temperature" class="input" inputmode="decimal" maxlength="6" value="<?= e($temperature) ?>" placeholder="leer = Standard des Modells"></div>
    <div><label class="label" for="a-out">Maximale Antwortlänge (Tokens)</label><input id="a-out" name="max_out" type="number" min="64" max="16000" class="input" value="<?= (int) $maxOut ?>"></div>
    <div><label class="label" for="a-ctx">Kontext-Limit pro Anfrage (Tokens)</label><input id="a-ctx" name="ctx" type="number" min="500" max="90000" class="input" value="<?= (int) $ctx ?>"><p class="mt-1 text-xs text-slate-500 dark:text-neutral-400">Höchstens 90 000. Gilt für Systemprompt, Verlauf und Antwort zusammen. Die Zählung ist eine Schätzung (ca. 3 Zeichen pro Token).</p></div>
    <div><label class="label" for="a-to">Zeitlimit (Sekunden)</label><input id="a-to" name="timeout" type="number" min="10" max="120" class="input" value="<?= (int) $timeout ?>"></div>
    <div><label class="label" for="a-acc">Wer darf die KI nutzen?</label>
      <select id="a-acc" name="access" class="input">
        <option value="all" <?= $access === 'all' ? 'selected' : '' ?>>Alle Mitglieder (ohne Gäste)</option>
        <option value="admins" <?= $access === 'admins' ? 'selected' : '' ?>>Nur Administratoren</option>
        <option value="list" <?= $access === 'list' ? 'selected' : '' ?>>Administratoren und Liste</option>
      </select></div>
    <div><label class="label" for="a-rate">Anfragen pro Person und Stunde</label><input id="a-rate" name="rate" type="number" min="1" max="1000" class="input" value="<?= (int) $rate ?>"></div>
    <div class="sm:col-span-2"><label class="label" for="a-list">Benutzernamen der Liste</label><input id="a-list" name="allowed" class="input" maxlength="1000" value="<?= e($allowed) ?>" placeholder="anna, bob (nur bei «Administratoren und Liste»)"></div>
    <div class="sm:col-span-2"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="enabled" value="1" <?= $wanted ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-accent focus:ring-accent"><span>KI-Assistent aktivieren</span></label></div>
    <div class="flex flex-wrap gap-2 sm:col-span-2"><button class="btn btn-primary" type="submit">Speichern</button></div>
  </form>
  <form method="post" action="<?= e(url('admin/ai/test')) ?>" class="mt-3"><?= Csrf::field() ?><button class="btn btn-secondary" type="submit"><i class="bx bx-refresh text-lg" aria-hidden="true"></i>Verbindung testen (gespeicherte Werte)</button></form>
</section>

<section class="card p-5 sm:p-6" aria-labelledby="h-ai-priv">
  <h2 id="h-ai-priv" class="mb-3 text-base font-semibold">Datenschutz und Betrieb</h2>
  <ul class="list-disc space-y-2 pl-5 text-sm">
    <li>Bei jeder Anfrage übermittelt der Messenger den Text der auslösenden Nachricht sowie, je nach Einstellung des Chats, die letzten rund 20 Nachrichten oder den gesamten Verlauf seit dem Beitritt der KI (mit Anzeigenamen, ohne E-Mail-Adressen) an den oben eingetragenen Anbieter. Dateien werden nicht übertragen, nur ihre Namen.</li>
    <li>Der Anbieter ist Auftragsverarbeiter bzw. Empfänger. Bitte Vertrag (AVV) und Standort prüfen und ihn in der Datenschutzerklärung unter «Empfänger» nennen. Die Erklärung erwähnt die KI automatisch, solange sie aktiv ist.</li>
    <li>In Gruppen sehen alle Mitglieder per Systemmeldung, dass die KI hinzugefügt wurde. Der Kontext der Gruppe (letzte Nachrichten / ganzer Verlauf / nur Anfrage) wird von der Moderation im Bereich «Details» gewählt.</li>
    <li>Die KI läuft als Systemkonto, kann sich nicht anmelden. Wird sie deaktiviert, bleiben bestehende Chats erhalten, antworten aber nicht mehr.</li>
    <li>Die Antworten werden vollständig geholt und dann als eine Nachricht gesendet (kein Streaming). Während der Wartezeit erscheint «schreibt …».</li>
  </ul>
</section>
</div>
