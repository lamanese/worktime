# Zeitwerk

Nextcloud App zur Arbeitszeiterfassung für Unternehmen.

> **Hinweis:** Zeitwerk ist ein eigenständig gepflegter Fork der App WorkTime
> ([cpcMomentum/worktime](https://github.com/cpcMomentum/worktime),
> Original von Axel Deffner, AGPL-3.0-or-later) mit eigener App-ID und
> eigenem Datenbankschema. Der Fork erweitert das Original u. a. um
> Außendienst-Spesen, Extern-Kilometer und persönliche Standard-Vorgaben;
> alle Änderungen sind im [CHANGELOG](CHANGELOG.md) dokumentiert.

## Features

- **Zeiterfassung**: Tägliche Arbeitszeiten mit Start, Ende und Pause erfassen
- **Pausenberechnung**: Automatischer Vorschlag gemäß §4 ArbZG (deutsches Arbeitszeitgesetz)
- **Projekterfassung**: Zeiteinträge optional Projekten zuordnen
- **Monatsübersicht**: Soll/Ist-Vergleich mit Überstundenberechnung
- **PDF-Export**: Monatsbericht als PDF herunterladen
- **Abwesenheitsverwaltung**: Urlaub, Krankheit, Sonderurlaub etc.
- **Urlaubskonto**: Automatische Berechnung verbleibender Urlaubstage
- **Feiertage**: Automatische Generierung deutscher Feiertage pro Bundesland
- **Team-Übersicht**: Vorgesetzte sehen Statistiken ihrer Teammitglieder
- **Genehmigungsworkflow**: Optionale Freigabe von Zeiteinträgen und Abwesenheiten
- **Außendienst-Spesen**: Konfigurierbare Tagespauschale ab Stundenschwelle auf Außendienst-Projekten
- **Extern-Kilometer**: Tageweise Kilometer-Erfassung an externen Tagen mit konfigurierbarem Satz
- **Persönliche Standard-Vorgaben**: Standard-Arbeitszeiten sowie — nach Admin-Freigabe — Standard-Projekt und -Beschreibung als Vorbelegung

## Voraussetzungen

- Nextcloud 32+
- PHP 8.2+
- MySQL/MariaDB oder PostgreSQL

## Installation

```bash
# In Nextcloud apps Verzeichnis
cd /var/www/nextcloud/apps
# Ordnername muss der App-ID entsprechen (zeitwerk)
git clone https://github.com/lamanese/worktime.git zeitwerk
cd zeitwerk

# PHP Dependencies
composer install --no-dev

# Frontend bauen
npm install
npm run build
```

App aktivieren:
```bash
php occ app:enable zeitwerk
```

## Konfiguration

### Ersteinrichtung

1. Als Admin die App öffnen → Einstellungen
2. Firmennamen und Standard-Bundesland setzen
3. Feiertage für das aktuelle/nächste Jahr generieren
4. Mitarbeiter anlegen (Employees)

### Berechtigungssystem

| Rolle | Beschreibung |
|-------|--------------|
| **Admin** | Voller Zugriff auf alle Funktionen |
| **HR Manager** | Kann Mitarbeiter, Projekte und Feiertage verwalten |
| **Vorgesetzter** | Kann Zeiteinträge/Abwesenheiten seines Teams genehmigen |
| **Mitarbeiter** | Kann eigene Zeiten und Abwesenheiten erfassen |

### Pausenregelung (§4 ArbZG)

Die App schlägt automatisch Pausenzeiten vor:
- **≤6h Arbeitszeit**: Keine Pause erforderlich
- **>6h bis 9h**: 30 Minuten Pause
- **>9h**: 45 Minuten Pause

Die Werte sind in den Einstellungen konfigurierbar.

## Entwicklung

```bash
# Dependencies installieren
composer install
npm install

# Frontend im Watch-Modus
npm run watch

# Tests ausführen
./vendor/bin/phpunit
```

## Datenbank-Tabellen

| Tabelle | Beschreibung |
|---------|--------------|
| `zw_employees` | Mitarbeiter mit Wochenstunden, Urlaubstagen, Bundesland |
| `zw_time_entries` | Zeiteinträge mit Status (draft/submitted/approved/rejected) |
| `zw_absences` | Abwesenheiten (Urlaub, Krankheit etc.) |
| `zw_holidays` | Feiertage pro Bundesland |
| `zw_projects` | Projekte für Zeiterfassung |
| `zw_audit_logs` | Änderungsprotokoll |
| `zw_company_settings` | App-Einstellungen |

## API Endpoints

### Zeiteinträge
- `GET /api/time-entries` - Liste (Filter: year, month)
- `POST /api/time-entries` - Erstellen
- `PUT /api/time-entries/{id}` - Bearbeiten
- `DELETE /api/time-entries/{id}` - Löschen
- `POST /api/time-entries/suggest-break` - Pausenvorschlag

### Abwesenheiten
- `GET /api/absences` - Liste
- `POST /api/absences` - Erstellen
- `PUT /api/absences/{id}` - Bearbeiten
- `DELETE /api/absences/{id}` - Löschen

### Berichte
- `GET /api/reports/monthly` - Monatsstatistik
- `GET /api/reports/pdf` - PDF-Download
- `GET /api/reports/team` - Team-Übersicht

### Administration
- `GET /api/employees` - Mitarbeiter
- `GET /api/projects` - Projekte
- `POST /api/holidays/generate` - Feiertage generieren
- `GET /api/settings` - Einstellungen

## Lizenz

AGPL-3.0-or-later
