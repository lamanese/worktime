# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/),
und dieses Projekt folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

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

## [0.3.0] - 2026-03-30

## [0.2.0] - 2026-03-08

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
