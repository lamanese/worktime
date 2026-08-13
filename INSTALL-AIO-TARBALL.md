# Zeitwerk-Installation auf Nextcloud AIO

Anleitung fuer die Installation von Zeitwerk auf einer Nextcloud-AIO-Instanz.
Gilt fuer Test- und Produktivserver gleichermassen. Ein vorgeschalteter
Nginx Proxy Manager spielt keine Rolle — die App wird direkt im
Nextcloud-Container installiert.

Stand 2026-07-17: Zeitwerk ist im offiziellen App Store
(<https://apps.nextcloud.com/apps/zeitwerk>). Der Store-Install ist der
Normalweg; der Tarball-Weg bleibt fuer Sonderfaelle (Offline-Server,
Versions-Pinning, Vorabtests ungeoeffentlichter Builds).

## Voraussetzungen

- Nextcloud AIO laeuft (Container `nextcloud-aio-nextcloud`), NC 32-34.
- SSH-Zugang auf den AIO-Host, `docker` ohne sudo (User in der `docker`-Gruppe)
  oder eben mit `sudo docker`.

Kurzform fuer alle Befehle:

```bash
CT=nextcloud-aio-nextcloud
NCC="docker exec -u www-data $CT php occ"
```

## Weg A (Normalfall) — Installation aus dem App Store

```bash
$NCC app:install zeitwerk
```

Meldet der Befehl «not found on the appstore», obwohl die App im Store liegt,
ist der lokale Store-Katalog der Instanz veraltet — Cache flushen und erneut
versuchen:

```bash
DATADIR=$($NCC config:system:get datadirectory)
docker exec $CT bash -c "rm -f $DATADIR/appdata_*/appstore/*.json"
$NCC app:install zeitwerk
```

(Der Store cached seine Plattform-Kataloge zusaetzlich serverseitig pro
NC-Version — ein frisch veroeffentlichtes Release kann dort mit etwas
Verzoegerung erscheinen.)

Updates kommen danach ueber die normale App-Update-Funktion von Nextcloud
(`$NCC app:update zeitwerk` bzw. Apps-Verwaltung im Browser).

## Weg B (Sonderfall) — Installation per Release-Tarball

### Schritt 1 — Tarball besorgen und auf den AIO-Host bringen

Offizielle, signierte Tarballs liegen als Asset an den GitHub-Releases:
`https://github.com/lamanese/worktime/releases` → `zeitwerk-<version>.tar.gz`.

```bash
# direkt auf dem AIO-Host
wget https://github.com/lamanese/worktime/releases/download/v0.14.2/zeitwerk-0.14.2.tar.gz
```

Nur wenn bewusst ein ungeoeffentlichter Stand getestet werden soll, selbst
bauen (auf der Dev-Maschine, vom Tag oder Branch):
`git archive <tag> --prefix=zeitwerk/ | gzip -n > zeitwerk-<version>.tar.gz`
— dieser Eigenbau enthaelt dann keine `signature.json` (siehe Hinweise).

### Schritt 2 — Entpacken und in den Container kopieren

```bash
cd ~ && tar -xzf zeitwerk-0.14.2.tar.gz    # ergibt Ordner ~/zeitwerk/

# falls schon eine alte Zeitwerk-Version drin ist: erst entfernen
docker exec "$CT" rm -rf /var/www/html/custom_apps/zeitwerk

# in den Container kopieren und Rechte setzen (docker cp laeuft als root)
docker cp ~/zeitwerk "$CT:/var/www/html/custom_apps/zeitwerk"
docker exec "$CT" chown -R www-data:www-data /var/www/html/custom_apps/zeitwerk
```

### Schritt 3 — App aktivieren

Frischinstallation: kein `occ upgrade` noetig, die Migrationen
(`zw_*`-Tabellen) laufen beim Aktivieren.

```bash
$NCC app:enable zeitwerk
```

Bei einem **Update** einer bestehenden Zeitwerk-Installation stattdessen:
App-Version muss in `appinfo/info.xml` hoeher sein als die installierte, dann

```bash
$NCC upgrade
```

(Vor Updates mit Schema-Migration: DB-Backup, siehe DEPLOY-TESTSERVER.md.)

### Schritt 4 — Aufraeumen

```bash
rm -rf ~/zeitwerk ~/zeitwerk-0.14.2.tar.gz
```

## Verifizieren (beide Wege)

```bash
$NCC app:list | grep -A1 zeitwerk     # App gelistet, richtige Version?
$NCC integrity:check-app zeitwerk     # keine Ausgabe = Signatur/Hashes OK
$NCC log:tail 20                      # Fehler im Log?
```

Dann im Browser: App-Menu → Zeitwerk. Erste Schritte in der App:
Admin-Einstellungen (Bundesland/Feiertage, Firmen-Einstellungen),
Mitarbeiter anlegen, Testbuchung erfassen.

## Hinweise

- **Ordnername muss `zeitwerk` sein** (= App-ID), sonst startet die App nicht.
- **Signierung**: Seit 0.14.2 enthalten die offiziellen Release-Tarballs eine
  gueltige `appinfo/signature.json` (Zeitwerk-Zertifikat der Nextcloud Code
  Signing Authority) — sie gehoert ins Paket und macht
  `occ integrity:check-app zeitwerk` moeglich. Selbstgebaute
  `git archive`-Tarballs sind unsigniert (keine `signature.json`); das ist
  fuer Tests in Ordnung, der Integrity-Check entfaellt dann.
- **Nextcloud-Major-Upgrades**: `info.xml` erlaubt NC 32-34. Ein Upgrade auf
  NC 35 deaktiviert die App, bis eine Version mit gebumpter `max-version`
  installiert wird. Daten bleiben erhalten.
- **AIO-Backup** (Borg) sichert `custom_apps` und die Datenbank mit —
  Zeitwerk ist bei Backup/Restore vollstaendig dabei.
- Tarballs nicht ins Git-Repo committen.
