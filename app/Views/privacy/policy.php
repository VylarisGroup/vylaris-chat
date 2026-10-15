<?php
/** @var array $s Einstellungen */
$scope = $s['privacy_scope'] ?? 'both';
$ch = in_array($scope, ['ch', 'both'], true);
$eu = in_array($scope, ['eu', 'both'], true);
$company = $s['company_name'];
$val = static function (string $key, string $fallback = 'Angabe noch nicht hinterlegt') use ($s): string {
    $v = trim((string) ($s[$key] ?? ''));
    return $v !== '' ? nl2br(e($v), false) : '<em class="text-amber-700 dark:text-amber-400">' . e($fallback) . '</em>';
};
$mail = trim((string) ($s['privacy_contact_email'] ?? ''));
$mailHtml = $mail !== '' ? '<a class="font-medium text-accent hover:underline" href="mailto:' . e($mail) . '">' . e($mail) . '</a>' : '<em class="text-amber-700 dark:text-amber-400">Kontaktadresse noch nicht hinterlegt</em>';
$days = static fn (string $k): int => (int) ($s[$k] ?? 0);
$msgDays = $days('retention_days_messages');
$auditDays = $days('retention_days_audit');
$sso = ($s['oidc_enabled'] ?? '0') === '1';
$push = ($s['push_enabled'] ?? '0') === '1';
$backupKeep = max(1, (int) ($s['backup_keep'] ?? 7));
$updated = trim((string) ($s['privacy_updated_at'] ?? ''));
$h2 = 'mt-10 scroll-mt-4 text-xl font-semibold tracking-tight';
$p = 'mt-3 text-sm leading-relaxed text-slate-700 dark:text-neutral-300';
$ul = 'mt-3 list-disc space-y-1.5 pl-5 text-sm leading-relaxed text-slate-700 dark:text-neutral-300';
$th = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-neutral-400';
$td = 'px-3 py-2.5 align-top';
$toc = [
    ['verantwortlich', 'Verantwortlicher und Kontakt'],
    ['geltung', 'Geltungsbereich und anwendbares Recht'],
    ['daten', 'Welche Daten wir bearbeiten'],
    ['zwecke', 'Zwecke und Rechtsgrundlagen'],
    ['zugriff', 'Wer Zugriff auf deine Daten hat'],
    ['ausland', 'Bekanntgabe ins Ausland'],
    ['dauer', 'Aufbewahrung und Löschung'],
    ['sicherheit', 'Datensicherheit'],
    ['cookies', 'Cookies und lokale Speicherung'],
    ['rechte', 'Deine Rechte'],
    ['ausueben', 'So übst du deine Rechte aus'],
    ['beschwerde', 'Beschwerde bei der Aufsichtsbehörde'],
    ['aenderungen', 'Änderungen dieser Erklärung'],
];
?>
<article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-soft sm:p-10 dark:border-neutral-800 dark:bg-neutral-900">
  <p class="text-xs font-semibold uppercase tracking-wide text-accent">Datenschutz</p>
  <h1 class="mt-1 text-3xl font-semibold tracking-tight">Datenschutzerklärung</h1>
  <p class="<?= $p ?>">Diese Erklärung informiert dich darüber, welche Personendaten <?= e($company) ?> beim Betrieb des internen Messengers bearbeitet, wozu und auf welcher Grundlage das geschieht, wer darauf Zugriff hat und welche Rechte du hast.
    <?= $updated !== '' ? 'Stand: ' . e($updated) . '.' : '' ?></p>

  <nav aria-label="Inhalt" class="mt-6 rounded-xl bg-slate-50 p-4 dark:bg-neutral-800/60">
    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-neutral-400">Inhalt</p>
    <ol class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
      <?php foreach ($toc as $i => [$id, $label]): ?>
        <li><a class="text-accent hover:underline" href="#<?= e($id) ?>"><?= $i + 1 ?>. <?= e($label) ?></a></li>
      <?php endforeach; ?>
    </ol>
  </nav>

  <h2 id="verantwortlich" class="<?= $h2 ?>">1. Verantwortlicher und Kontakt</h2>
  <p class="<?= $p ?>">Verantwortlich für die Datenbearbeitung ist:</p>
  <p class="mt-3 rounded-xl border border-slate-200 p-4 text-sm leading-relaxed dark:border-neutral-700"><?= $val('privacy_controller') ?></p>
  <p class="<?= $p ?>">Für Fragen zum Datenschutz und zur Ausübung deiner Rechte erreichst du uns unter: <?= $mailHtml ?>.</p>
  <?php if (trim((string) ($s['privacy_dpo'] ?? '')) !== ''): ?>
    <p class="<?= $p ?>"><strong>Datenschutzberatung bzw. Datenschutzbeauftragte(r):</strong><br><?= $val('privacy_dpo') ?></p>
  <?php endif; ?>
  <?php if (trim((string) ($s['privacy_representative'] ?? '')) !== ''): ?>
    <p class="<?= $p ?>"><strong>Vertretung<?= $ch && $eu ? ' (Art. 14 DSG bzw. Art. 27 DSGVO)' : ($ch ? ' (Art. 14 DSG)' : ' (Art. 27 DSGVO)') ?>:</strong><br><?= $val('privacy_representative') ?></p>
  <?php endif; ?>

  <h2 id="geltung" class="<?= $h2 ?>">2. Geltungsbereich und anwendbares Recht</h2>
  <p class="<?= $p ?>">Der Messenger ist eine Anwendung für die interne Kommunikation. Er wird auf einem Server betrieben, den der Verantwortliche kontrolliert oder bei einem Hosting-Anbieter mietet (siehe Ziffer 6). Die Bearbeitung richtet sich nach
    <?php if ($ch && $eu): ?>dem schweizerischen Bundesgesetz über den Datenschutz (<abbr title="Datenschutzgesetz">DSG</abbr>, SR 235.1) und, soweit es auf Personen im Europäischen Wirtschaftsraum Anwendung findet, nach der EU-Datenschutz-Grundverordnung (<abbr title="Datenschutz-Grundverordnung">DSGVO</abbr>, Verordnung (EU) 2016/679).
    <?php elseif ($ch): ?>dem schweizerischen Bundesgesetz über den Datenschutz (<abbr title="Datenschutzgesetz">DSG</abbr>, SR 235.1).
    <?php else: ?>der EU-Datenschutz-Grundverordnung (<abbr title="Datenschutz-Grundverordnung">DSGVO</abbr>, Verordnung (EU) 2016/679) und dem ergänzenden nationalen Recht.
    <?php endif; ?>
    Wir bearbeiten Personendaten nach den Grundsätzen der Rechtmässigkeit, von Treu und Glauben, der Verhältnismässigkeit, der Zweckbindung, der Transparenz und der Richtigkeit<?= $ch ? ' (Art. 6 DSG' . ($eu ? ', Art. 5 DSGVO' : '') . ')' : ' (Art. 5 DSGVO)' ?>.</p>

  <h2 id="daten" class="<?= $h2 ?>">3. Welche Daten wir bearbeiten</h2>
  <div class="mt-3 overflow-x-auto rounded-xl border border-slate-200 dark:border-neutral-700">
    <table class="w-full min-w-[640px] text-sm">
      <caption class="sr-only">Kategorien bearbeiteter Personendaten</caption>
      <thead class="border-b border-slate-200 bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800/60"><tr><th scope="col" class="<?= $th ?>">Kategorie</th><th scope="col" class="<?= $th ?>">Daten</th><th scope="col" class="<?= $th ?>">Quelle</th></tr></thead>
      <tbody class="divide-y divide-slate-100 dark:divide-neutral-800">
        <tr><th scope="row" class="<?= $td ?> text-left font-medium">Kontodaten</th><td class="<?= $td ?>">E-Mail-Adresse, Benutzername, Anzeigename, Rolle (Mitglied oder Administrator), Passwort (nur als nicht umkehrbarer Hash), Zeitpunkte der Erstellung, E-Mail-Bestätigung, letzten Anmeldung und Passwortänderung</td><td class="<?= $td ?>">von dir, bzw. von Administratoren bei der Kontoerstellung</td></tr>
        <tr><th scope="row" class="<?= $td ?> text-left font-medium">Profil und Einstellungen</th><td class="<?= $td ?>">Statusnachricht, Profilbild, Präsenz-Einstellung, Benachrichtigungs-, Darstellungs- und Datenschutz-Einstellungen</td><td class="<?= $td ?>">von dir</td></tr>
        <tr><th scope="row" class="<?= $td ?> text-left font-medium">Kommunikationsinhalte</th><td class="<?= $td ?>">Nachrichten, Antworten, Weiterleitungen, Reaktionen, Erwähnungen, hochgeladene Dateien und Bilder samt Dateiname, Typ und Grösse, Gruppen und deren Beschreibung</td><td class="<?= $td ?>">von dir und den Gesprächspartnern</td></tr>
        <tr><th scope="row" class="<?= $td ?> text-left font-medium">Nutzungs- und Verbindungsdaten</th><td class="<?= $td ?>">Mitgliedschaften in Unterhaltungen, Zustellung und Lesestatus von Nachrichten (sofern nicht in deinen Einstellungen abgeschaltet), „schreibt gerade“-Anzeige (nur flüchtig), „zuletzt gesehen“, Online-Status</td><td class="<?= $td ?>">bei der Nutzung automatisch</td></tr>
        <tr><th scope="row" class="<?= $td ?> text-left font-medium">Sitzungs- und Sicherheitsdaten</th><td class="<?= $td ?>">IP-Adresse, Browser-Kennung (User-Agent) und Zeitpunkte der angemeldeten Geräte, Protokoll sicherheitsrelevanter Ereignisse (z. B. Anmeldung, fehlgeschlagene Anmeldung, Passwortänderung, administrative Eingriffe), Zähler zur Missbrauchsabwehr (Rate-Limits)</td><td class="<?= $td ?>">bei der Nutzung automatisch</td></tr>
        <tr><th scope="row" class="<?= $td ?> text-left font-medium">Zwei-Faktor-Authentifizierung</th><td class="<?= $td ?>">Nur wenn du sie aktivierst: ein Geheimnis für die Authenticator-App (auf dem Server verschlüsselt gespeichert), Zeitpunkt der Aktivierung und Wiederherstellungscodes (nur als Hash)</td><td class="<?= $td ?>">von dir</td></tr>
<?php if ($sso): ?>        <tr><th scope="row" class="<?= $td ?> text-left font-medium">Einmalanmeldung (SSO)</th><td class="<?= $td ?>">Vom Identitätsanbieter der Organisation übermittelt: eindeutige Kennung (als Hash gespeichert), E-Mail-Adresse und Name</td><td class="<?= $td ?>">Identitätsanbieter der Organisation</td></tr>
<?php endif; ?><?php if ($push): ?>        <tr><th scope="row" class="<?= $td ?> text-left font-medium">Push-Registrierung</th><td class="<?= $td ?>">Nur wenn du Push einschaltest: die Adresse (Endpunkt) deines Geräts beim Push-Dienst des Browsers und Browser-Kennung</td><td class="<?= $td ?>">von dir</td></tr>
<?php endif; ?>        <tr><th scope="row" class="<?= $td ?> text-left font-medium">Betriebsdaten</th><td class="<?= $td ?>">Fehlerprotokoll der Anwendung (technische Meldungen, ohne Nachrichteninhalte), Hintergrundaufträge (z. B. Datenexport) und Datensicherungen des gesamten Datenbestands</td><td class="<?= $td ?>">automatisch</td></tr>
        <tr><th scope="row" class="<?= $td ?> text-left font-medium">Meldungen</th><td class="<?= $td ?>">Wer welche Nachricht aus welchem Grund gemeldet hat, und die Bearbeitung durch Administratoren</td><td class="<?= $td ?>">von der meldenden Person</td></tr>
        <tr><th scope="row" class="<?= $td ?> text-left font-medium">Benachrichtigungen und E-Mails</th><td class="<?= $td ?>">In-App-Benachrichtigungen; E-Mail-Adresse und Name für Einladungen, Bestätigungs- und Passwort-Links sowie optionale Hinweis-Mails (diese enthalten nie Nachrichteninhalte)</td><td class="<?= $td ?>">automatisch</td></tr>
        <tr><th scope="row" class="<?= $td ?> text-left font-medium">Datenschutzanfragen</th><td class="<?= $td ?>">Deine Anfragen zu deinen Rechten, die Antwort und die Bearbeitungsdaten (Nachweis der fristgerechten Erledigung)</td><td class="<?= $td ?>">von dir</td></tr>
      </tbody>
    </table>
  </div>
  <p class="<?= $p ?>">Wir bearbeiten keine Personendaten aus Drittquellen und führen keine Profilbildung oder automatisierten Einzelentscheidungen durch<?= $ch ? ' (vgl. Art. 21 DSG' . ($eu ? ', Art. 22 DSGVO' : '') . ')' : ' (vgl. Art. 22 DSGVO)' ?>. Nachrichten können je nach Inhalt auch besonders schützenswerte Personendaten enthalten, wenn du solche selbst mitteilst. Wir werten Inhalte nicht aus.</p>

  <h2 id="zwecke" class="<?= $h2 ?>">4. Zwecke und Rechtsgrundlagen</h2>
  <ul class="<?= $ul ?>">
    <li><strong>Bereitstellung des Messengers</strong> (Konto, Nachrichtenversand, Dateien, Benachrichtigungen, Profil): erforderlich zur Abwicklung des Arbeits- bzw. Vertragsverhältnisses und zur Zusammenarbeit im Unternehmen
      <?= $ch && $eu ? '(Art. 31 Abs. 2 lit. a DSG; Art. 6 Abs. 1 lit. b DSGVO; für Arbeitnehmende in der Schweiz zudem Art. 328b OR)' : ($ch ? '(Art. 31 Abs. 2 lit. a DSG; für Arbeitnehmende zudem Art. 328b OR)' : '(Art. 6 Abs. 1 lit. b DSGVO, ggf. i. V. m. dem nationalen Beschäftigtendatenschutzrecht)') ?>.</li>
    <li><strong>Sicherheit, Missbrauchsabwehr und Nachvollziehbarkeit</strong> (Sitzungsverwaltung, Protokoll, Rate-Limits, Bearbeitung von Meldungen): berechtigtes Interesse an einem sicheren und störungsfreien Betrieb
      <?= $ch && $eu ? '(Art. 31 Abs. 1 DSG; Art. 6 Abs. 1 lit. f DSGVO)' : ($ch ? '(Art. 31 Abs. 1 DSG, überwiegendes privates Interesse)' : '(Art. 6 Abs. 1 lit. f DSGVO)') ?>; die Datensicherheit selbst ist zudem gesetzlich geboten
      <?= $ch && $eu ? '(Art. 8 DSG; Art. 32 DSGVO)' : ($ch ? '(Art. 8 DSG)' : '(Art. 32 DSGVO)') ?>.</li>
    <li><strong>Erfüllung rechtlicher Pflichten</strong> (z. B. Beantwortung von Auskunftsbegehren, Aufbewahrungs- und Herausgabepflichten gegenüber Behörden): <?= $ch && $eu ? 'Art. 31 Abs. 1 DSG; Art. 6 Abs. 1 lit. c DSGVO' : ($ch ? 'Art. 31 Abs. 1 DSG' : 'Art. 6 Abs. 1 lit. c DSGVO') ?>.</li>
    <li><strong>Optionale Funktionen</strong>, die du selbst einschaltest (z. B. Browser- und Push-Benachrichtigungen, Zwei-Faktor-Authentifizierung): deine Einwilligung, die du jederzeit mit Wirkung für die Zukunft widerrufen kannst, indem du die Funktion in den Einstellungen wieder ausschaltest.</li>
  </ul>
  <p class="<?= $p ?>">Wir verwenden die Daten nicht zu Werbezwecken, verkaufen sie nicht und verwenden sie nicht für andere, mit diesen Zwecken unvereinbare Zwecke. Der Messenger ist keine Leistungs- oder Verhaltenskontrolle; eine solche ist nicht vorgesehen und nicht Zweck der Bearbeitung.</p>

  <h2 id="zugriff" class="<?= $h2 ?>">5. Wer Zugriff auf deine Daten hat</h2>
  <ul class="<?= $ul ?>">
    <li><strong>Gesprächsteilnehmende:</strong> Nachrichten und Dateien sehen die Mitglieder der jeweiligen Unterhaltung. Dein Profil (Name, Bild, Status, Online-Status) ist für andere Mitglieder des Messengers sichtbar; „zuletzt gesehen“ und Lesebestätigungen kannst du in den Einstellungen abschalten.</li>
    <li><strong>Administratoren:</strong> Sie verwalten Konten, Gruppen und Einstellungen und sehen Kontodaten, Gruppennamen und das Protokoll. Sie haben <strong>keinen Zugriff auf den Inhalt privater Unterhaltungen</strong>. Eingesehen werden kann ausschliesslich eine einzelne Nachricht, die ein Mitglied meldet; diese Einsicht wird protokolliert. Auch bei Auskunfts- und Herausgabebegehren erhalten Administratoren keinen Zugriff auf Nachrichteninhalte.</li>
    <li><strong>Technischer Betrieb:</strong> Nachrichten werden verschlüsselt übertragen (HTTPS), liegen auf dem Server aber <strong>nicht Ende-zu-Ende-verschlüsselt</strong> in der Datenbank. Personen mit Server- oder Datenbankzugriff (Betreiber, Hosting-Anbieter) hätten deshalb technisch die Möglichkeit, Inhalte zu lesen. Dies ist organisatorisch und vertraglich zu beschränken.</li>
    <li><strong>Dienstleister (Auftragsbearbeiter):</strong>
      <?php if (trim((string) ($s['privacy_recipients'] ?? '')) !== ''): ?><br><?= $val('privacy_recipients') ?>
      <?php else: ?>Hosting-Anbieter und E-Mail-Versanddienst (SMTP). <em class="text-amber-700 dark:text-amber-400">Konkrete Anbieter noch nicht hinterlegt.</em><?php endif; ?>
      Mit Dienstleistern bestehen Verträge, die sie zur Einhaltung des Datenschutzes verpflichten<?= $ch && $eu ? ' (Art. 9 DSG; Art. 28 DSGVO)' : ($ch ? ' (Art. 9 DSG)' : ' (Art. 28 DSGVO)') ?>. Der E-Mail-Versanddienst erhält E-Mail-Adresse, Name und den Inhalt der Systemmail (nie Nachrichteninhalte).</li>
<?php if ($push): ?>    <li><strong>Push-Dienste der Browser-Hersteller</strong> (Google, Mozilla, Apple, Microsoft): nur wenn du Push-Benachrichtigungen auf einem Gerät einschaltest. Sie erhalten ein <em>leeres</em> Signal und die Geräteadresse, aber weder Absender noch Nachrichtentext; der Hinweistext wird danach direkt von unserem Server abgerufen.</li>
<?php endif; ?><?php if ($sso): ?>    <li><strong>Identitätsanbieter (SSO):</strong> Bei der Anmeldung per Einmalanmeldung tauschen wir E-Mail-Adresse, Name und eine Kennung mit dem Identitätsanbieter deiner Organisation aus; dessen Datenschutzerklärung gilt für seine Bearbeitung.</li>
<?php endif; ?>    <li><strong>Lizenzserver:</strong> Zur Prüfung der Lizenz und der Anzahl zugelassener Konten übermittelt dieser Dienst den Lizenzschlüssel der Organisation, eine zufällige, nicht rückrechenbare Platzkennung je aktivem Konto sowie die IP-Adresse des Servers. Namen, E-Mail-Adressen und Nachrichten werden nicht übermittelt.</li>
    <li><strong>Behörden und Gerichte:</strong> nur, soweit wir gesetzlich dazu verpflichtet sind.</li>
  </ul>
  <p class="<?= $p ?>">Wir binden keine Werbe-, Analyse- oder Tracking-Dienste ein. Schriften, Symbole und Skripte werden vom eigenen Server geladen; es werden keine Daten an Dritte wie CDNs oder Soziale Netzwerke übermittelt.</p>

  <h2 id="ausland" class="<?= $h2 ?>">6. Bekanntgabe ins Ausland</h2>
  <p class="<?= $p ?>"><strong>Hosting und Serverstandort:</strong> <?= $val('privacy_hosting', 'Serverstandort noch nicht hinterlegt') ?></p>
  <p class="<?= $p ?>">Werden Personendaten in ein Land ohne angemessenen Datenschutz übermittelt, geschieht dies nur mit geeigneten Garantien, insbesondere Standarddatenschutzklauseln<?= $ch && $eu ? ' (Art. 16 und 17 DSG; Art. 44 ff. DSGVO)' : ($ch ? ' (Art. 16 und 17 DSG)' : ' (Art. 44 ff. DSGVO)') ?>. Der Bundesrat bzw. die EU-Kommission führt Listen der Staaten mit angemessenem Schutzniveau.</p>

  <h2 id="dauer" class="<?= $h2 ?>">7. Aufbewahrung und Löschung</h2>
  <p class="<?= $p ?>">Wir speichern Personendaten nur so lange, wie es für die genannten Zwecke erforderlich ist oder Pflichten es verlangen.</p>
  <ul class="<?= $ul ?>">
    <li><strong>Konto, Profil, Einstellungen:</strong> bis zur Löschung des Kontos. Beim Löschen werden Name, E-Mail-Adresse, Profilbild, Status, Einstellungen, Sitzungen, Benachrichtigungen und Lesestatus entfernt bzw. anonymisiert.</li>
    <li><strong>Nachrichten und Dateien:</strong> <?= $msgDays > 0 ? 'automatisch gelöscht nach ' . $msgDays . ' Tagen' : 'keine automatische Löschfrist; sie bleiben bestehen, bis sie gelöscht werden' ?>. Du kannst eigene Nachrichten jederzeit löschen (der Inhalt wird dabei endgültig entfernt). Beim Löschen deines Kontos kannst du wählen, ob auch alle deine Nachrichten und Dateien endgültig gelöscht werden; sonst bleiben sie für die Gesprächspartner ohne Namensbezug („Gelöschter Benutzer“) erhalten.</li>
    <li><strong>Sitzungsdaten (IP, Browser):</strong> bis zur Abmeldung bzw. längstens 30 Tage nach Ablauf der Sitzung.</li>
    <li><strong>Sicherheitsprotokoll:</strong> <?= $auditDays > 0 ? $auditDays . ' Tage' : 'unbefristet (Empfehlung: eine Frist festlegen)' ?>. Bei Kontolöschung werden IP-Adresse und Browser-Kennung aus den Protokolleinträgen entfernt.</li>
    <li><strong>Benachrichtigungen:</strong> gelesene Hinweise nach 30 Tagen; Zähler zur Missbrauchsabwehr und „schreibt gerade“-Anzeigen nach wenigen Minuten bis Stunden.</li>
    <li><strong>Datenexporte:</strong> Die für dich erstellte Exportdatei steht 7 Tage zum Download bereit und wird danach gelöscht. <strong>Fehlerprotokoll:</strong> wird ab einer bestimmten Grösse rotiert und nach wenigen Generationen überschrieben.<?= $push ? ' <strong>Push-Registrierungen:</strong> bis du Push ausschaltest oder das Gerät nicht mehr erreichbar ist.' : '' ?></li>
    <li><strong>Datenschutzanfragen und Antworten:</strong> bis drei Jahre nach Abschluss, als Nachweis der gesetzmässigen Bearbeitung.</li>
  </ul>
  <p class="<?= $p ?>">Datensicherungen (Backups) enthalten den gesamten Datenbestand. Es werden die jeweils letzten <?= $backupKeep ?> Backups aufbewahrt, ältere werden automatisch gelöscht. Gelöschte Daten können deshalb bis zu dieser Frist noch in Backups vorhanden sein; sie werden nicht wiederhergestellt, ausser bei einem Datenverlust (und dann erneut gelöscht, sobald die Löschung bekannt ist).</p>

  <h2 id="sicherheit" class="<?= $h2 ?>">8. Datensicherheit</h2>
  <p class="<?= $p ?>">Wir treffen angemessene technische und organisatorische Massnahmen<?= $ch && $eu ? ' (Art. 8 DSG; Art. 32 DSGVO)' : ($ch ? ' (Art. 8 DSG)' : ' (Art. 32 DSGVO)') ?>, unter anderem: verschlüsselte Übertragung (HTTPS/TLS), Passwort-Hashing, Schutz vor typischen Webangriffen (CSRF, XSS, SQL-Injection), Zugriffskontrolle je Unterhaltung, widerrufbare Sitzungen, Begrenzung von Anmeldeversuchen mit Kontosperre, optionale Zwei-Faktor-Authentifizierung (bei Administratorkonten ggf. vorgeschrieben), regelmässige Datensicherung und eine Überwachung von Fehlern, und geschützte Dateiablage. Bei einer Verletzung der Datensicherheit mit voraussichtlich hohem Risiko melden wir diese der zuständigen Aufsichtsbehörde<?= $ch && $eu ? ' (Art. 24 DSG: so rasch als möglich; Art. 33 DSGVO: binnen 72 Stunden)' : ($ch ? ' (Art. 24 DSG: so rasch als möglich)' : ' (Art. 33 DSGVO: binnen 72 Stunden)') ?> und informieren betroffene Personen, wenn es zu ihrem Schutz erforderlich ist.</p>

  <h2 id="cookies" class="<?= $h2 ?>">9. Cookies und lokale Speicherung</h2>
  <p class="<?= $p ?>">Wir verwenden ausschliesslich technisch notwendige Speicherung: ein Sitzungs-Cookie (<code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs dark:bg-neutral-800">msgr_sid</code>), das dich angemeldet hält, und im Browser deine Darstellungswahl (hell/dunkel). Wenn du Push-Benachrichtigungen einschaltest oder die App installierst, richtet dein Browser einen Service Worker ein, der ausschliesslich Hinweise anzeigt. Es gibt keine Tracking- oder Werbe-Cookies. Eine Einwilligung ist dafür nicht erforderlich.</p>

  <h2 id="rechte" class="<?= $h2 ?>">10. Deine Rechte</h2>
  <p class="<?= $p ?>">Du hast gegenüber dem Verantwortlichen folgende Rechte. Die Ausübung ist grundsätzlich kostenlos. Wir antworten innert 30 Tagen<?= $eu ? ' (DSGVO: ein Monat, in komplexen Fällen verlängerbar um bis zu zwei weitere Monate, wir informieren dich dann rechtzeitig)' : '' ?>. Zur Identitätsprüfung können wir Angaben nachfordern.</p>
  <div class="mt-3 overflow-x-auto rounded-xl border border-slate-200 dark:border-neutral-700">
    <table class="w-full min-w-[560px] text-sm">
      <caption class="sr-only">Betroffenenrechte</caption>
      <thead class="border-b border-slate-200 bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800/60"><tr><th scope="col" class="<?= $th ?>">Recht</th><th scope="col" class="<?= $th ?>">Inhalt</th><th scope="col" class="<?= $th ?>">Grundlage</th></tr></thead>
      <tbody class="divide-y divide-slate-100 dark:divide-neutral-800">
        <?php
        $rights = [
            ['Auskunft', 'Du erfährst, ob und welche Personendaten wir über dich bearbeiten, und erhältst eine Kopie samt Informationen zu Zweck, Herkunft, Empfängern und Aufbewahrung.', 'Art. 25 DSG', 'Art. 15 DSGVO'],
            ['Datenherausgabe und -übertragung', 'Du erhältst die Daten, die du uns bereitgestellt hast, in einem gängigen, maschinenlesbaren Format (JSON, ZIP) oder kannst ihre Übertragung an Dritte verlangen.', 'Art. 28 DSG', 'Art. 20 DSGVO'],
            ['Berichtigung', 'Unrichtige oder unvollständige Daten werden korrigiert. Name, Status und Bild änderst du selbst in den Einstellungen.', 'Art. 32 DSG', 'Art. 16 DSGVO'],
            ['Löschung', 'Deine Daten werden gelöscht, soweit keine Pflicht oder kein überwiegendes Interesse zur Aufbewahrung besteht.', 'Art. 32 DSG', 'Art. 17 DSGVO'],
            ['Einschränkung / Sperrung', 'Die Bearbeitung wird eingeschränkt, etwa solange die Richtigkeit oder ein Widerspruch geprüft wird. Deine Daten bleiben gespeichert, du kannst aber nichts mehr senden.', 'Art. 32 DSG', 'Art. 18 DSGVO'],
            ['Widerspruch', 'Du kannst einer Bearbeitung widersprechen, die auf berechtigtem Interesse beruht.', 'Art. 30 und 32 DSG', 'Art. 21 DSGVO'],
            ['Widerruf einer Einwilligung', 'Eine erteilte Einwilligung kannst du jederzeit für die Zukunft widerrufen.', 'allgemeine Grundsätze', 'Art. 7 Abs. 3 DSGVO'],
            ['Beschwerde', 'Du kannst dich bei der Aufsichtsbehörde beschweren (Ziffer 12).', 'Art. 49 DSG', 'Art. 77 DSGVO'],
        ];
        foreach ($rights as [$name, $text, $dsg, $gdpr]): ?>
          <tr><th scope="row" class="<?= $td ?> text-left font-medium"><?= e($name) ?></th><td class="<?= $td ?>"><?= e($text) ?></td><td class="<?= $td ?> whitespace-nowrap text-xs text-slate-600 dark:text-neutral-300"><?= $ch ? e($dsg) : '' ?><?= $ch && $eu ? '<br>' : '' ?><?= $eu ? e($gdpr) : '' ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="<?= $p ?>">Grenzen: Nachrichten und Dateien anderer Personen können wir nicht herausgeben, weil dies deren Rechte verletzen würde. Löschung und Auskunft können eingeschränkt sein, soweit gesetzliche Pflichten, überwiegende Interessen Dritter oder die Geltendmachung von Rechtsansprüchen dem entgegenstehen<?= $ch && $eu ? ' (Art. 26 und 29 DSG; Art. 17 Abs. 3 und Art. 23 DSGVO)' : ($ch ? ' (Art. 26 und 29 DSG)' : ' (Art. 17 Abs. 3 und Art. 23 DSGVO)') ?>. Wir teilen dir in diesem Fall mit, weshalb.</p>

  <h2 id="ausueben" class="<?= $h2 ?>">11. So übst du deine Rechte aus</h2>
  <ul class="<?= $ul ?>">
    <li><strong>Direkt im Messenger</strong> (Einstellungen &rarr; <em>Meine Daten</em>): Daten herunterladen (Auskunft und Herausgabe inklusive deiner eigenen Nachrichten und Dateien), Profil berichtigen, Anfragen zu Berichtigung, Löschung, Einschränkung oder Widerspruch stellen und deren Stand verfolgen, Konto löschen (wahlweise mit endgültiger Löschung aller deiner Nachrichten und Dateien).</li>
    <li><strong>Per E-Mail:</strong> <?= $mailHtml ?>. Auch Personen ohne aktives Konto (z. B. frühere Mitarbeitende) können so Auskunft verlangen; wir erfassen die Anfrage dann manuell und prüfen deine Identität.</li>
  </ul>
  <p class="<?= $p ?>">Administratoren können Auszüge deiner Konto- und Verbindungsdaten erstellen, deine Angaben berichtigen, die Bearbeitung einschränken oder dein Konto löschen. Sie erhalten dabei keinen Zugriff auf Nachrichteninhalte.</p>

  <h2 id="beschwerde" class="<?= $h2 ?>">12. Beschwerde bei der Aufsichtsbehörde</h2>
  <?php if ($ch): ?>
    <p class="<?= $p ?>"><strong>Schweiz:</strong> Eidgenössischer Datenschutz- und Öffentlichkeitsbeauftragter (EDÖB), Feldeggweg 1, 3003 Bern, <a class="font-medium text-accent hover:underline" href="https://www.edoeb.admin.ch" target="_blank" rel="noopener noreferrer">www.edoeb.admin.ch</a>. Du kannst dort Anzeige erstatten (Art. 49 DSG). Daneben stehen dir zivilrechtliche Ansprüche vor Gericht offen (Art. 32 DSG).</p>
  <?php endif; ?>
  <?php if ($eu): ?>
    <p class="<?= $p ?>"><strong>EU / EWR:</strong> Du hast das Recht, dich bei einer Datenschutz-Aufsichtsbehörde zu beschweren, insbesondere in dem Mitgliedstaat, in dem du dich gewöhnlich aufhältst, arbeitest oder in dem der mutmassliche Verstoss stattfand (Art. 77 DSGVO).
      <?php if (trim((string) ($s['privacy_authority_eu'] ?? '')) !== ''): ?>Zuständig für uns: <?= $val('privacy_authority_eu') ?>.<?php endif; ?></p>
  <?php endif; ?>
  <p class="<?= $p ?>">Wir bitten dich, uns vorab zu kontaktieren, damit wir dein Anliegen rasch klären können.</p>

  <h2 id="aenderungen" class="<?= $h2 ?>">13. Änderungen dieser Erklärung</h2>
  <p class="<?= $p ?>">Wir passen diese Erklärung an, wenn sich der Messenger, die Datenbearbeitung oder die Rechtslage ändern. Es gilt die jeweils hier veröffentlichte Fassung.</p>
  <?php if (trim((string) ($s['privacy_extra'] ?? '')) !== ''): ?>
    <h2 class="<?= $h2 ?>">Zusätzliche Hinweise</h2>
    <p class="<?= $p ?>"><?= $val('privacy_extra') ?></p>
  <?php endif; ?>
  <?php if ($updated !== ''): ?><p class="mt-10 text-xs text-slate-500 dark:text-neutral-400">Stand: <?= e($updated) ?></p><?php endif; ?>
</article>
