# Feiertage in Zeitwerk

Zeitwerk erzeugt gesetzliche Feiertage automatisch pro Region. Eine Region ist ein deutsches Bundesland oder ein Schweizer Kanton, codiert nach ISO 3166-2 mit Laenderpraefix (`DE-BY`, `CH-ZH`). Jeder Mitarbeitende hat eine Region, die Firma eine Standard-Region fuer neue Mitarbeitende. HR kann weitere Feiertage (Brueckentage, regionale Feste) jederzeit manuell anlegen; manuelle Feiertage bleiben bei einer Neugenerierung erhalten.

## Deutschland (16 Bundeslaender)

Bundesweit: Neujahr, Karfreitag, Ostermontag, Tag der Arbeit, Christi Himmelfahrt, Pfingstmontag, Tag der Deutschen Einheit, 1. und 2. Weihnachtstag.

| Feiertag | Regel | Bundeslaender |
|---|---|---|
| Heilige Drei Koenige | 6.1. | BW, BY, ST |
| Internationaler Frauentag | 8.3. | BE (ab 2019), MV (ab 2023) |
| Fronleichnam | Ostern +60 | BW, BY, HE, NW, RP, SL |
| Mariae Himmelfahrt | 15.8. | BY, SL |
| Weltkindertag | 20.9. | TH (ab 2019) |
| Reformationstag | 31.10. | BB, HB, HH, MV, NI, SN, ST, SH, TH |
| Allerheiligen | 1.11. | BW, BY, NW, RP, SL |
| Buss- und Bettag | Mittwoch vor dem 23.11. | SN |

Hinweis: Mariae Himmelfahrt gilt in Bayern nur in ueberwiegend katholischen Gemeinden, Zeitwerk setzt es fuer ganz Bayern. Das Augsburger Friedensfest (8.8., nur Stadt Augsburg) wird nicht erzeugt.

## Schweiz (26 Kantone)

### Einschlussregel

Automatisch erzeugt werden nur kantonsweit arbeitsfreie Tage:

1. Basis ist das Verzeichnis des Bundesamts fuer Justiz «Gesetzliche Feiertage und Tage, die in der Schweiz wie gesetzliche Feiertage behandelt werden» (Stand 1. Januar 2011), Abschnitte «gesetzlich anerkannt» und «wie gesetzliche Feiertage behandelt».
2. Ergaenzt um kantonsweit faktisch arbeitsfreie ganze Tage laut Wikipedia «Feiertage in der Schweiz» (betrifft Neuenburg: Ostermontag, Pfingstmontag).
3. Nur gemeindeweise geltende Feiertage werden nicht erzeugt: Josefstag in Solothurn (7 Gemeinden) und Luzern, Solothurner Patroziniumsfeste, Graubuendner Gemeindefeste, Fronleichnam in Le Landeron (NE).
4. Bezirksausnahmen werden zugunsten der Kantonsmehrheit ignoriert: reformierte Gemeinden im Freiburger Seebezirk, Solothurner Bezirk Buchegg, Appenzell Innerrhoder Bezirk Oberegg.
5. Halbtage nur, wenn gesetzlich: Solothurn 1. Mai (ab 12 Uhr; ganzer Tag, wenn der 1. Mai ein Montag ist). Der Aargauer 1. Mai (Brauch, halbtags) wird nicht erzeugt.
6. Reine Sonntage (Eidgenoessischer Dank-, Buss- und Bettag, Ostersonntag, Pfingstsonntag) werden nicht erzeugt.
7. Der Bundesfeiertag (1. August) gilt in allen Kantonen.
8. Aargau: Fronleichnam, Mariae Himmelfahrt, Allerheiligen und Mariae Empfaengnis werden nach dem BJ-Verzeichnis kantonsweit erzeugt, obwohl sie faktisch nur in katholisch gepraegten Gemeinden frei sind. Betriebe in reformierten Gemeinden loeschen diese Tage bei Bedarf manuell (siehe Bekannte Grenzen).

Braeuche wie Sechselaeuten, Knabenschiessen, Fasnacht oder freie Nachmittage am 24.12. und 31.12. haben in der Schweiz keine kantonale Rechtsgrundlage und werden nicht erzeugt. Heiligabend und Silvester als halbe Tage sind eine Firmeneinstellung und gelten dann fuer alle Regionen beider Laender.

### Feiertage und Datumsregeln

| Kuerzel | Name | Regel |
|---|---|---|
| NJ | Neujahr | 1.1. |
| BT | Berchtoldstag | 2.1. (NE: nur wenn Montag; AG: entfaellt an Dienstag oder Samstag) |
| DK | Heilige Drei Koenige | 6.1. |
| IR | Instauration de la Republique | 1.3. |
| JT | Josefstag | 19.3. |
| KF | Karfreitag | Ostern −2 |
| OM | Ostermontag | Ostern +1 |
| NF | Naefelser Fahrt | 1. Donnerstag im April; faellt er auf den Gruendonnerstag, eine Woche spaeter |
| TA | Tag der Arbeit | 1.5. (SO: halber Tag, ganzer Tag wenn Montag) |
| AU | Auffahrt | Ostern +39 |
| PM | Pfingstmontag | Ostern +50 |
| FL | Fronleichnam | Ostern +60 |
| PJ | Commemoration du plebiscite jurassien | 23.6. |
| PP | Peter und Paul | 29.6. |
| BF | Bundesfeiertag | 1.8. |
| MH | Mariae Himmelfahrt | 15.8. |
| JG | Jeune genevois | Donnerstag nach dem ersten Sonntag im September |
| BM | Bettagsmontag | Montag nach dem dritten Sonntag im September |
| MT | Mauritiustag | 22.9. |
| BK | Bruderklausenfest | 25.9. |
| AH | Allerheiligen | 1.11. |
| ME | Mariae Empfaengnis | 8.12. |
| WT | Weihnachtstag | 25.12. |
| ST | Stephanstag | 26.12. (NE: nur wenn Montag; UR, AR, AI, AG: entfaellt an Dienstag oder Samstag) |
| RR | Restauration de la Republique | 31.12. |

### Feiertage pro Kanton

| Kanton | Feiertage |
|---|---|
| AG Aargau | NJ, BT*, KF, OM, AU, PM, FL, MH, BF, AH, ME, WT, ST* |
| AI Appenzell Innerrhoden | NJ, KF, OM, AU, PM, FL, BF, MH, MT, AH, ME, WT, ST* |
| AR Appenzell Ausserrhoden | NJ, KF, OM, AU, PM, BF, WT, ST* |
| BE Bern | NJ, BT, KF, OM, AU, PM, BF, WT, ST |
| BL Basel-Landschaft | NJ, KF, OM, TA, AU, PM, BF, WT, ST |
| BS Basel-Stadt | NJ, KF, OM, TA, AU, PM, BF, WT, ST |
| FR Freiburg | NJ, BT, KF, OM, AU, PM, FL, BF, MH, AH, ME, WT, ST |
| GE Genf | NJ, KF, OM, AU, PM, BF, JG, WT, RR |
| GL Glarus | NJ, BT, KF, OM, NF, AU, PM, BF, AH, WT, ST |
| GR Graubuenden | NJ, KF, OM, AU, PM, BF, WT, ST |
| JU Jura | NJ, BT, KF, OM, TA, AU, PM, FL, PJ, BF, MH, AH, WT |
| LU Luzern | NJ, BT, KF, OM, AU, PM, FL, BF, MH, AH, ME, WT, ST |
| NE Neuenburg | NJ, BT°, IR, KF, OM, TA, AU, PM, BF, WT, ST° |
| NW Nidwalden | NJ, BT, JT, KF, OM, AU, PM, FL, BF, MH, AH, ME, WT, ST |
| OW Obwalden | NJ, BT, KF, OM, AU, PM, FL, BF, MH, BK, AH, ME, WT, ST |
| SG St. Gallen | NJ, BT, KF, OM, AU, PM, BF, AH, WT, ST |
| SH Schaffhausen | NJ, BT, KF, OM, TA, AU, PM, BF, WT, ST |
| SO Solothurn | NJ, BT, KF, OM, TA (halb), AU, PM, FL, BF, MH, AH, WT, ST |
| SZ Schwyz | NJ, DK, JT, KF, OM, AU, PM, FL, BF, MH, AH, ME, WT, ST |
| TG Thurgau | NJ, BT, KF, OM, TA, AU, PM, BF, WT, ST |
| TI Tessin | NJ, DK, JT, OM, TA, AU, PM, FL, PP, BF, MH, AH, ME, WT, ST |
| UR Uri | NJ, DK, JT, KF, OM, AU, PM, FL, BF, MH, AH, ME, WT, ST* |
| VD Waadt | NJ, BT, KF, OM, AU, PM, BF, BM, WT |
| VS Wallis | NJ, BT, JT, OM, AU, PM, FL, BF, MH, AH, ME, WT, ST |
| ZG Zug | NJ, BT, KF, OM, AU, PM, FL, BF, MH, AH, ME, WT, ST |
| ZH Zuerich | NJ, BT, KF, OM, TA, AU, PM, BF, WT, ST |

`*` entfaellt, wenn der Tag auf einen Dienstag oder Samstag faellt. `°` nur, wenn der Tag ein Montag ist. Gesetzlich in allen 26 Kantonen sind nur vier Tage: Neujahr, Auffahrt, Bundesfeiertag, Weihnachtstag. Nach der Einschlussregel oben erzeugt Zeitwerk zusaetzlich Ostermontag und Pfingstmontag in allen Kantonen (im Wallis als «wie gesetzliche Feiertage behandelt», in Neuenburg als faktisch arbeitsfrei). Tessin und Wallis kennen keinen Karfreitag, Waadt, Genf und Jura keinen Stephanstag.

### Bekannte Grenzen

- Regionale Unterschiede unterhalb der Kantonsebene (Bezirke, Gemeinden, Staedte) bildet Zeitwerk nicht ab. Betroffene Betriebe passen die Feiertage in den Einstellungen manuell an.
- Ein geloeschter automatischer Feiertag kommt beim naechsten «Feiertage neu erstellen» wieder. Fuer dauerhafte Abweichungen den Feiertag stattdessen bearbeiten (Name, Umfang) oder die Regenerierung fuer dieses Jahr nicht mehr ausloesen.
- Das BJ-Verzeichnis traegt den Stand 1. Januar 2011 und wurde vom Bund seither nicht aktualisiert. Aenderungen in kantonalen Ruhetagsgesetzen seit 2011 sind moeglich; Rueckmeldungen bitte als Issue.
- Das Verzeichnis regelt die Fristenberechnung (dies non), nicht Art. 20a des Arbeitsgesetzes. Fuer Zeitwerk relevant ist die Frage «ist der Tag im Kanton arbeitsfrei», dafuer sind beide Quellen zusammen die beste verfuegbare Grundlage.
- Pausenregeln folgen weiterhin §4 ArbZG (Deutschland). Eine Variante nach Art. 15 des Schweizer Arbeitsgesetzes ist ein offenes Thema.

## Migration von Versionen vor 0.18.0

Beim Update werden die Regionscodes automatisch von `BY` nach `DE-BY` umgeschrieben (Mitarbeitende, Feiertage, Standard-Region). Ein Downgrade auf 0.17.x ist danach nicht vorgesehen. Vor dem Update wie immer ein Datenbank-Backup anlegen. Codes, die keinem der 16 deutschen Bundeslaender entsprechen (etwa Tippfehler in frueher manuell angelegten Feiertagen), werden nicht umgeschrieben; die Migration meldet ihre Anzahl als Warnung, HR korrigiert oder loescht diese Eintraege in den Einstellungen.

## Quellen

- Bundesamt fuer Justiz: Gesetzliche Feiertage und Tage, die in der Schweiz wie gesetzliche Feiertage behandelt werden (Stand 1.1.2011), `https://www.bj.admin.ch/dam/bj/de/data/publiservice/service/zivilprozessrecht/kant-feiertage.pdf.download.pdf/kant-feiertage-dfi.pdf`
- Wikipedia: Feiertage in der Schweiz, `https://de.wikipedia.org/wiki/Feiertage_in_der_Schweiz`
- Wikipedia: Gesetzliche Feiertage in Deutschland, `https://de.wikipedia.org/wiki/Gesetzliche_Feiertage_in_Deutschland`
