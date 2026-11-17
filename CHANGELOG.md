# Änderungsprotokoll

Alle nennenswerten Änderungen an Kladory. Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), Versionsnummern nach [Semantic Versioning](https://semver.org/lang/de/).

## [0.8.4] - 2026-11-16

### Geändert
- Angaben für den Nextcloud App Store: englische und deutsche Beschreibung, Projekt- und Fehlermelde-Links, Bildschirmfotos, Admin-Dokumentation. Keine Änderungen am Programm.

## [0.8.3] - 2026-11-16

### Behoben
- Nextclouds Standard-Stile für bearbeitbare Felder (feste Breite, Rahmen, dunkler Hintergrund) haben Textfelder und das Pencil-Schreibfeld zusammengedrückt; schreibgeschützte Seiten wurden halb durchsichtig angezeigt.

## [0.8.2] - 2026-11-16

### Geändert
- „Stift → Text“: Das Schreibfeld bleibt immer groß und leer. Erkannter Text wandert nach einer kurzen Pause in ein Textfeld darüber. Neue Knöpfe „Neuer Absatz“ und „Neues Textfeld“.

## [0.8.1] - 2026-11-16

### Geändert
- Rückgängig/Wiederholen auch im Textmodus sichtbar; dort wirken sie auf das gerade bearbeitete Textfeld.

## [0.8.0] - 2026-11-16

### Neu
- Auswahlwerkzeug im Zeichenmodus: umkreisen oder antippen, verschieben, an den Ecken skalieren, duplizieren, löschen.
- Wiederholen (Redo); Rückgängig/Wiederholen fest neben dem Modus-Umschalter.
- Knopf zum Einklappen von Notizbuch- und Seitenleiste für mehr Schreibfläche.

## [0.7.1] - 2026-11-15

### Neu
- Speicher-Anzeige mit Uhrzeit („Gespeichert · vor 2 Min.“), Hinweis bei fehlender Verbindung, automatisches erneutes Speichern.

## [0.7.0] - 2026-11-15

### Geändert
- Deutlich weniger Datenverkehr: Stiftstriche werden verdichtet und kompakt gespeichert, Speichern komprimiert (gzip), keine Inhalte mehr in der Antwort.
- Weniger Datenbankabfragen beim Laden; Zwischenspeicher für LDAP-Gruppen.

### Neu
- Lasttest-Werkzeug `tools/kladory-lasttest.py`.

### Behoben
- Ein Strich, der genau während des automatischen Speicherns gezeichnet wurde, konnte unvollständig gespeichert werden.

## [0.6.0] - 2026-11-15

### Neu
- Export/Import von Notizbüchern (Download oder Ablage in den Nextcloud-Dateien), für Lehrkräfte inklusive kompletter Kursnotizbücher.
- `occ kladory:transfer`, `kladory:export`, `kladory:import`.

## [0.5.0] - 2026-11-15

### Neu
- Zeichenmodus mit Stift und Finger, Textmarker, Radierer; „Stift → Text“ über Apple Scribble.

## [0.4.0] - 2026-11-15

### Neu
- Kursnotizbücher mit Inhaltsbibliothek, gemeinsamem Bereich, Lehrkräfte-Bereich und Schülerbereichen; Seiten an alle verteilen.

## [0.3.0] - 2026-11-15

### Neu
- Bilder, Volltextsuche, Tags.

## [0.2.0] - 2026-11-15

### Neu
- Freie Leinwand mit Textfeldern; Papierkorb mit Aufbewahrungszeit.

## [0.1.0] - 2026-11-14

### Neu
- Erste Version: Notizbücher, Abschnitte, Seiten.
