# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/),
und dieses Projekt folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

## [0.7.1] - 2026-05-17

### Fixed
- **Audit-Log Kontrast (#162)**: Schrift in der Änderungsspalte war kaum lesbar (blasse NC CSS-Variablen). Alle Farben durch explizite Hex-Werte ersetzt (`#b91c1c`, `#15803d`, `#555`).
- **Audit-Log Diff-Anzeige (#163)**: Änderungsspalte zeigte bei `update`-Aktionen den kompletten Objekt-Dump. Jetzt werden nur tatsächlich geänderte Felder als `Feld: alt → neu` angezeigt. Interne Felder (id, employeeId, createdAt, updatedAt) werden ausgeblendet.
- **Monatsübergreifende Abwesenheiten in Genehmigungsansicht (#164)**: Eine Abwesenheit die z.B. vom 27.04–08.05 läuft, zeigte im April-View den gesamten Zeitraum. Jetzt wird der Zeitraum auf den angezeigten Monat geclipt (April: 27.04–30.04, Mai: 01.05–08.05).

## [0.7.0] - 2026-05-17

### Added
- **Audit-Log View (#91)**: Neue Ansicht für Admin und HR-Manager mit vollständigem Änderungsprotokoll. Filterbar nach Monat und Mitarbeiter. Farbige Action-Badges (erstellt, aktualisiert, gelöscht) mit Old→New-Diff-Anzeige.
- **Jahresübertrag für Überstunden und Urlaubstage (#100)**: Offene Überstunden und nicht genommene Urlaubstage aus dem Vorjahr werden automatisch ins neue Jahr übertragen. Konfigurierbar in Admin-Einstellungen. Dashboard und Jahresübersicht zeigen Übertragswerte an.
- **Jahresübertrag UX-Überarbeitung (#144)**: Übertragsstatus in Übersicht, manuelle Korrekturmöglichkeit, verbesserter Workflow für HR-Manager.

### Fixed
- **Genehmigungsansicht zeigt jetzt auch Abwesenheiten (#158)**: In der aufgeklappten Detailzeile werden Urlaub, Krankheit etc. neben Zeiteinträgen angezeigt, sortiert nach Datum mit farbigem Typ-Badge.
- **Urlaubsquoten-Validierung (#147)**: Urlaubsantrag wird beim Erstellen und Bearbeiten gegen das verfügbare Kontingent geprüft. Überschreitung zeigt Warnung im Formular.
- **Eintrittsdatum wird bei Sollberechnung berücksichtigt (#145)**: Monate vor dem Eintrittsdatum liefern 0-Stats statt falscher Minusstunden.
- **FZA-Stunden reduzieren Soll nicht mehr (#149)**: Freizeitausgleich wurde fälschlicherweise vom Monatssoll abgezogen.
- **Pausenvalidierung als Toast (#151)**: Blockierende UI-Sperre bei Pausenverstoß durch informativen Toast-Hinweis ersetzt.
- **Warnhinweise mit lesbarem Kontrast (#146)**: Warntexte nutzen jetzt `--color-main-text` statt kaum lesbarer NC-Standardfarbe.

### Changed
- **Batch-Loading für Team-Abfragen**: Team- und Jahresübersicht laden Daten jetzt in einem Batch-Request mit DB-Indizes statt N+1 Queries.

## [0.6.4] - 2026-05-04

### Fixed
- **v0.6.3 war nicht installierbar**: Tarball enthielt `__MACOSX/`-Ordner (macOS-Metadaten). NC verweigert Installation bei mehr als einem Top-Level-Ordner. v0.6.3 wurde aus dem App Store entfernt. Dieses Release ist inhaltlich identisch mit v0.6.3, aber mit korrektem Tarball.

## [0.6.3] - 2026-05-04 [ZURÜCKGEZOGEN]

### Fixed
- **Release v0.6.2 war nicht installierbar**: Kompilierte JS-Dateien enthielten Git-Merge-Conflict-Marker aus dem manuellen Release-Prozess. v0.6.2 wurde aus dem App Store entfernt. Dieses Release ersetzt es mit sauber gebauten Dateien.

### Changed
- **InfoIcon Wrapper-Komponente (#127)**: Shared `InfoIcon.vue` extrahiert. NcPopover+Icon+CSS an einer Stelle statt 9x dupliziert. ~230 Zeilen CSS-Duplizierung entfernt.
- **Pre-commit Hook erweitert**: Blockiert jetzt auch Conflict-Marker in allen Dateitypen (nicht nur OC\*-Check in PHP).

## [0.6.2] - 2026-05-03 [ZURÜCKGEZOGEN]

### Added
- **Kontextuelle Hilfe (#114, #116)**: Info-Icons (ⓘ) mit Popover-Erklaerungen an 32 Stellen in der App. Dashboard (Soll, Noch offen), Jahresuebersicht (Ueberstunden), Zeiterfassung (Minusstunden, Pausenvorschlag), Admin-Settings (13 Einstellungen), Mitarbeiter-Formular (8 Felder), Genehmigungsuebersicht, Monatsübersicht und User-Settings.
- **Abwesenheitstyp-Legende (#97)**: Legende unter der Abwesenheitstabelle mit Farbpunkten und Erklaerungstexten. Farbpunkte neben Typ-Namen in der Tabelle.

### Fixed
- **Timezone-Bug in Datumsvergleich (#113)**: `toISOString().split('T')[0]` durch `formatDateISO()` ersetzt — UTC-Konvertierung konnte Datum um einen Tag verschieben.
- **Info-Icons einheitlich (#122)**: Alle 32 Icons nutzen identisches Popover-Pattern. Kein Fragezeichen-Cursor mehr beim Hover. HR-Manager Rollen-Info von NcNoteCard-Toggle auf Popover umgebaut.

### Changed
- **getStatusLabel() zentralisiert (#117)**: Duplizierte Methode aus TimeEntryRow und AbsenceRow entfernt, zentrale Funktion aus formatters.js verwendet.
- **ABSENCE_TYPE_LABELS() gecacht (#118)**: Label-Lookup wird einmal pro Abwesenheit aufgerufen statt einmal pro expandiertem Tag.

## [0.6.1] - 2026-04-29

### Fixed
- **Uebersetzungen funktionieren jetzt (#103)**: Fehlende `l10n/*.js`-Dateien ergaenzt (NC laedt nur `.js`, nicht `.json`). Alle hardcoded deutschen Strings durch `t()`-Aufrufe ersetzt. Hardcoded `de-DE` Locale durch NC-Locale ersetzt. 51 fehlende Uebersetzungs-Keys ergaenzt (390 Keys gesamt).
- **Dashboard zeigt korrekte Minusstunden (#98)**: Fuer den aktuellen Monat wird jetzt das proportionale Soll (bis heute) statt des vollen Monatssolls angezeigt. Kein irregulaeres Defizit mehr am Morgen.
- **Stornierte Abwesenheiten in Zeiterfassung (#108)**: Stornierte Abwesenheiten (z.B. zurueckgenommener Freizeitausgleich) werden in der Zeiterfassungsliste nicht mehr angezeigt. In der Abwesenheitsuebersicht bleiben sie mit Status "Storniert" sichtbar.

## [0.6.0] - 2026-04-14

### Fixed
- **KRITISCH (#88)**: App-Update und `occ upgrade` stuerzten auf Nextcloud 33 ab, weil der Repair-Step die seit NC 11 deprecated und in NC 33 entfernte `OC_App::getAppPath()` nutzte. Betroffene User konnten ihre Nextcloud-Instanz nicht mehr aktualisieren. Fix nutzt jetzt die OCP-API `IAppManager::getAppPath()`.
- Abwesenheits-Timeline: jede Abwesenheitsart hat jetzt eine eigene, deutlich unterscheidbare Farbe (#87)
- Irrefuehrender Dialog-Text beim Einreichen des Monats: enthielt "keine Aenderungen moeglich", obwohl Nachtraege durchaus eingereicht werden koennen
- Yes/No-Buttons im Bestaetigungsdialog werden jetzt auf Deutsch angezeigt

### Added
- **Genehmigungsansicht (#68)**: Aufklappbare Detailzeile pro Mitarbeiter mit Datum, Beginn/Ende, Pause, Arbeitszeit, Projekt, Beschreibung und Status. PDF-Monatsbericht direkt aus der Detailansicht herunterladbar.
- **Auto-Genehmigung fuer Krankheit und Kind krank (#74)**: Krankmeldungen gehen ohne Genehmigungsworkflow direkt auf "genehmigt". Vorgesetzte sehen sie als "Zur Kenntnisnahme" in der Genehmigungsuebersicht.
- Benachrichtigungs-Flow fuer Krankmeldungen und stornierte Krankmeldungen

### Changed
- **UI-Konsistenz (#69)**: Team-, Genehmigungs- und Abwesenheitsuebersicht nutzen jetzt einheitliche Typografie, Padding und Kartenstil wie die etablierten Referenz-Views (Dashboard, Zeiterfassung, Meine Einstellungen)
- Icon-Unifikation: Entfernen-Buttons nutzen durchgaengig das Close-Icon (statt gemischt Close/Delete)

## [0.5.1] - 2026-04-12

### Fixed
- Header "Abwesenheitsuebersicht" wurde vom Sidebar-Toggle ueberlagert (padding-left: 50px ergaenzt)
- Monat-Navigation im MonthPicker funktionierte nicht (falsches Event-Binding)

### Changed
- Admin/HR/Supervisor sehen in der Abwesenheitsuebersicht die vollstaendige Typ-Legende

## [0.5.0] - 2026-04-12

### Added
- **Abwesenheitsuebersicht** (#3): Neue Timeline-Ansicht, farbige Balken pro Person
- **Datenschutz-Einstellungen** pro Mitarbeiter: Sichtbarkeit (Alle/Team/Niemand) + Detailgrad (Detailliert/Nur abwesend)
- Auto-Save fuer Einstellungen in "Meine Einstellungen"

### Fixed
- **KRITISCH**: Inkonsistenz zwischen v0.4.2 und v0.4.3/v0.4.4 behoben. In v0.4.2 war die `absence_visibility`-DB-Spalte angelegt worden, v0.4.3/v0.4.4 haben den zugehoerigen Code aber wieder entfernt — was zu "Interner Serverfehler" beim Oeffnen der App fuehrte. Dieses Release stellt den konsistenten Zustand her.

## [0.4.4] - 2026-04-12

### Fixed
- `.htaccess` aus TCPDF vendor/ entfernt — NC entfernt diese Datei bei der Installation automatisch (FilenameValidator), was zu FILE_MISSING Integritaetsfehler seit v0.4.0 fuehrte

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
