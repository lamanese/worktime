# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/),
und dieses Projekt folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

## [0.4.3] - 2026-04-12

### Fixed
- TCPDF (vendor/) wieder im Tarball enthalten — war in v0.4.1 und v0.4.2 versehentlich ausgeschlossen, was zu Integritaetsfehlern und nicht funktionierendem PDF-Export fuehrte (#50)

## [0.4.2] - 2026-04-11

### Fixed
- Automatische Bereinigung von Extra-Dateien aus frueheren Releases via RepairStep

## [0.4.1] - 2026-04-11

### Fixed
- Integritaetspruefung: `test-results/` und `appinfo/*.crt` aus Tarball entfernt

## [0.4.0] - 2026-04-11

### Added
- Projektverwaltung UI in den Einstellungen (#41)
- Vollstaendige englische Uebersetzungen und Berechtigungsinfo-Button
- Aufklappbare Soll/Ist-Berechnungsdetails in der Ueberstundenanzeige (#52)
- Abwesenheiten und Feiertage werden in der Tagesliste angezeigt (#53)
- Jahresuebersicht im Dashboard mit 12-Monats-Tabelle (#54)
- Mitarbeiter mit 0 Wochenstunden (Aushilfen auf Abruf) koennen angelegt werden (#61)

### Changed
- Dashboard redesigned: flache Cards, Redundanzen entfernt
- Einheitliches Typografie-System ueber alle Views (15px/13px)
- TCPDF Fonts reduziert (24 MB → 640 KB)
- Neues Arbeitszeitprofil hat heute als Default-Datum

### Fixed
- TCPDF Vendor-Dependency im Release enthalten (#50)

## [0.3.0] - 2026-03-24

### Added
- Arbeitszeitprofile mit Wochenprofil und Stichtag (#39)
- Stunden pro Wochentag individuell konfigurierbar (Mo-So)
- Samstag/Sonntag im Profil-Editor anzeigbar
- Soll-Berechnung nutzt das am jeweiligen Tag gueltige Profil
- Pro-rata Urlaubsberechnung bei Profilwechsel
- Max. Tagesstunden aus Einstellungen als Limit im Profil-Editor
- Feld "Arbeitstage pro Woche" pro Mitarbeiter (manuell, Default 5)
- Kontakt-E-Mail in info.xml

### Fixed
- IDOR-Schutz: update/delete pruefen employeeId-Ownership
- Duplicate-Validierung fuer Profil-Stichtage (valid_from)
- Pausenzeit-Einstellungen werden jetzt korrekt ausgewertet (#43)
- Frontend-Validierung mit visueller Rueckmeldung bei Ueberschreitung der Max-Stunden
- Fehlermeldungen im Profil-Editor zeigen konkrete Validierungsfehler

### Changed
- suggestBreak() und validateBreak() nutzen konfigurierte Werte statt hardcoded 30/45 Min

## [0.2.0] - 2026-03-19

### Added
- Team-Jahresuebersicht mit Ueberstunden, Urlaub und Status pro Mitarbeiter (#32)
- Jahres-Picker Komponente fuer Team-View
- API-Endpoint fuer Jahresberichte (ReportController)

### Fixed
- Korrekte Jahres-Ueberstundenberechnung im Dashboard (#37)
- Beschreibungsspalte in der Zeiteintrags-Ansicht sichtbar (#35)
- Null-Guard fuer employeeId in allen Controllern (#33)
- Verbessertes Onboarding fuer Nutzer ohne Mitarbeiterprofil

## [0.1.1] - 2026-02-23

### Added
- Zeiterfassung mit Start, Ende, Pause
- Automatischer Pausenvorschlag gemaess §4 ArbZG
- Projektbezogene Zeiterfassung
- Monatsuebersicht mit Soll/Ist/Ueberstunden-Berechnung
- PDF-Export fuer Monatsberichte (TCPDF)
- Abwesenheitsverwaltung (Urlaub, Krankheit, Sonderurlaub, etc.)
- Urlaubskonto mit automatischer Berechnung verbleibender Tage
- Deutsche Feiertage pro Bundesland (Gauss-Algorithmus fuer Ostern)
- Team-Uebersicht fuer Vorgesetzte
- Genehmigungsworkflow fuer Zeiteintraege und Abwesenheiten
- Berechtigungssystem (Admin, HR Manager, Supervisor, Employee)
- Vollstaendige deutsche und englische Lokalisierung
- E-Mail-Prefill aus Nextcloud-Profil bei Mitarbeiteranlage
- Nextcloud 32 und 33 Kompatibilitaet

### Fixed
- Webpack chunk filenames shortened to avoid hosting provider issues
