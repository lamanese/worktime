# Plan: WorkTime-Fork als eigenständige Nextcloud-App

Vorhaben und Empfehlungen, um den modifizierten Fork nicht nur inhouse zu betreiben,
sondern als **eigene, im Nextcloud App Store installierbare App** zu veröffentlichen.

> **Status (2026-07-13): Umbenennung UMGESETZT** (Branch `refactor/app-id-zeitwerk`,
> Version 0.14.0). Entschieden: App-ID `zeitwerk`, Anzeigename «Zeitwerk», Namespace
> `OCA\Zeitwerk`, Tabellen-Prefix `zw_`, frisches Schema ohne Datenimport. Abschnitt 3
> ist damit erledigt; Abschnitt 4-6 (Store-Zertifikat, Release, Automatisierung) sind
> weiterhin offen und werden erst bei einem Store-Release relevant.

## 1. Ziel

- Der aktuelle Fork (`lamanese/worktime`, Feature Aussendienst-Spesen + Extern-Kilometer)
  soll als eigenständige App weitergeführt und über den Nextcloud App Store verteilt werden.
- Nicht als Beitrag zurück an `cpcMomentum/worktime`, sondern als selbst gepflegte App.

## 2. Rechtlicher Rahmen (AGPL-3.0-or-later)

Ein eigener Fork ist erlaubt — die Lizenz gestattet Nutzung, Änderung und (auch
kommerzielle) Weiterverbreitung. Bedingungen, die eingehalten werden müssen:

- **Gleiche Lizenz**: Die abgeleitete App bleibt AGPL-3.0-or-later.
- **Copyright/Attribution erhalten**: Die bestehenden SPDX-Header und Copyright-Vermerke
  des Originalautors (Axel Deffner / cpcMomentum) bleiben in den übernommenen Dateien
  stehen. Eigene Copyright-Zeilen für neue/geänderte Dateien kommen dazu.
- **Quelloffenlegung (AGPL §13)**: Der vollständige Quellcode muss verfügbar sein. Für
  eine App-Store-App ist das erfüllt, weil das Release ein Source-Tarball ist und das
  Repo öffentlich liegt.
- **Änderungen kennzeichnen**: Wesentliche Änderungen dokumentieren (CHANGELOG/Notice).
- **Keine zusätzlichen Restriktionen** aufsetzen.
- **Marken/Name**: Die Lizenz gewährt keine Markenrechte. Die App-ID `worktime` ist im
  Store bereits vergeben — es braucht ohnehin eine **neue, eindeutige ID** und am besten
  einen **eigenen Anzeigenamen**. Kein „Nextcloud" im Namen (Store-Policy).

Empfehlung: Im README/Notice klar vermerken, dass es ein Fork von `cpcMomentum/worktime`
ist, mit Link zum Original und Liste der eigenen Änderungen.

## 3. Technische Umbenennung zur eigenen App

Damit die App eigenständig ist (und neben dem Original auf derselben Instanz koexistieren
kann), muss die App-Identität durchgängig geändert werden. Das ist der aufwändigste Teil —
im Kern ein sorgfältiges, konsistentes Umbenennen:

- **App-ID + Namespace**: neue ID wählen (z.B. `worktimeplus`). Betrifft:
  - `appinfo/info.xml`: `<id>`, `<name>`, `<namespace>`, `<author>`, `<bugs>`, `<repository>`,
    `<screenshot>`-URLs, `<navigations><route>`.
  - PHP-Namespace `OCA\WorkTime` → `OCA\WorkTimeXxx` in `lib/` und `tests/`;
    `composer.json` PSR-4-Autoload; `Application::APP_ID`.
  - `appinfo/routes.php` / `generateUrl`-Aufrufe (App-Name).
- **DB-Tabellen-Prefix**: aktuell `wt_` (z.B. `wt_time_entries`, `wt_daily_km`). Für
  Koexistenz mit dem Original **eigener Prefix** nötig. Entscheidung: neue App = neues
  Schema, kein Datenimport aus dem Original (frische Tabellen). Migrationen entsprechend
  anpassen.
- **Frontend**:
  - JS-Bundle-Name (`webpack.config.js` → `worktime-main.js`) und die Referenz in
    `templates/main.php`.
  - l10n-Domain: alle `t('worktime', ...)` / `n('worktime', ...)` auf die neue ID ändern,
    ebenso die Dateien in `l10n/` (Transifex-Domain).
- **Assets/Icons**: `img/app.svg` ggf. eigenes Icon.
- **Signatur**: neues Zertifikat für die neue App-ID (siehe Punkt 4).

Praktisch: ein kontrolliertes Suchen-und-Ersetzen der App-ID, des Namespaces und der
l10n-Domain, danach `npm run build`, `composer dump-autoload`, und ein voller Testlauf.

## 4. App-ID-Registrierung & Signing-Zertifikat (einmalig)

- Account auf `apps.nextcloud.com`, API-Token holen.
- CSR erzeugen und als PR bei `nextcloud/app-certificate-requests` einreichen. Nach dem
  Merge: `<appid>.crt`; privaten Key `<appid>.key` sicher verwahren
  (z.B. `~/.nextcloud/certificates/`).

## 5. Release-Prozess (pro Version)

- Auf `release/vX.Y.Z`-Branch (von `develop`).
- Version in `appinfo/info.xml` **und** `package.json` synchron (SemVer);
  NC-Kompatibilität `<nextcloud min-version max-version>` setzen.
- `npm ci && npm run build` (kompiliertes `js/`), `composer install --no-dev`.
- `CHANGELOG.md` pflegen.
- Tarball aus `git archive HEAD` (nicht aus dem Worktree — verhindert Leaks). Enthalten:
  `appinfo, lib, js, css, templates, l10n, img, vendor, composer.json, package.json,
  package-lock.json, webpack.config.js, CHANGELOG.md, README.md, LICENSE`.
- Keine `.htaccess`/`.user.ini` im Tarball.
- Signieren: `occ integrity:sign-app --path=<app> --privateKey=<key> --certificate=<crt>`
  erzeugt `appinfo/signature.json`. Tarball-Signatur:
  `openssl dgst -sha512 -sign <key> <app>.tar.gz | openssl base64`.
- Upload: Tarball öffentlich hosten (GitHub Release Asset), dann
  `POST https://apps.nextcloud.com/api/v1/apps/releases` mit `download`-URL + `signature`
  und `Authorization: Token <token>`.
- Neue App: einmaliger manueller Store-Review (Schema, Lizenz, Naming, nur `OCP\*`-APIs).

## 6. Automatisierung & laufende Releases

- Jede Version = Tag + GitHub Release + Store-POST. Per GitHub Action automatisierbar
  (Signing-Key als verschlüsseltes Secret). Tag-Push → Release.
- Upgrade-Test vor jedem Release: Vorversion installieren, neue drüberziehen,
  `occ upgrade`, Integrität prüfen.

## 7. Offene Entscheidungen / Risiken

- **Neue App-ID und Anzeigename** festlegen.
- **Tabellen-Prefix**: bestätigen, dass kein Datenimport aus dem Original nötig ist.
- **Umfang der Umbenennung**: Namespace-Rename ist invasiv und berührt fast jede Datei —
  danach vollständiger Regressionslauf (aktuell 223 Unit-Tests) zwingend.
- **Feature-Reife**: Vor dem ersten Store-Release sollten die noch offenen Punkte des
  aktuellen Features (PDF, Projektauswertung, l10n) und ein finaler Review abgeschlossen
  sein.
- **Wartungslast**: Eigener Fork heisst, Upstream-Fixes selbst nachziehen
  (`git fetch upstream && merge`).

## 8. Empfohlene Reihenfolge

1. Aktuelles Feature fertigstellen (PDF/Projektauswertung/l10n) + Review + Commit.
2. App-ID + Name + Prefix entscheiden.
3. Umbenennung in einem eigenen Branch, danach voller Build + Testlauf.
4. Store-Account + Zertifikat (einmalig).
5. Erstes Release bauen, signieren, hochladen; Store-Review abwarten.
6. Release-Automatisierung einrichten.
