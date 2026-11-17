# Kladory – Betrieb und Administration

Notizen-App für Nextcloud 35: Notizbücher → farbige Abschnitte → Seiten.

## Installation (Nextcloud im offiziellen Docker-Image)

```bash
# 1. Archiv entpacken (ergibt den Ordner "kladory")
tar xzf kladory-0.8.4.tar.gz

# 2. In den Container kopieren (Containername anpassen)
docker cp kladory nextcloud:/var/www/html/custom_apps/
docker exec nextcloud chown -R www-data:www-data /var/www/html/custom_apps/kladory
# linuxserver.io-Image: Pfad /app/www/public/apps, Benutzer abc:abc

# 3. Aktivieren (legt die Datenbanktabellen an)
docker exec -u www-data nextcloud php occ app:enable kladory
```

Ohne Docker: Ordner nach `<nextcloud>/custom_apps/` (bzw. `apps/`) kopieren,
Besitzer auf den Webserver-Benutzer setzen, dann `sudo -u www-data php occ app:enable kladory`.

## Update auf eine neue Version

```bash
docker exec nextcloud rm -rf /var/www/html/apps/kladory
docker cp kladory nextcloud:/var/www/html/apps/
docker exec nextcloud chown -R www-data:www-data /var/www/html/apps/kladory
docker exec -u www-data nextcloud php occ upgrade
```

`occ upgrade` führt neue Datenbank-Migrationen aus (z. B. 0.1 → 0.2).
Pfad `apps/` bzw. `custom_apps/` je nach Installation.

## Kursnotizbücher

Aufbau:

| Bereich | Lehrkräfte | Schüler |
|---|---|---|
| Inhaltsbibliothek | lesen + schreiben | nur lesen |
| Gemeinsamer Bereich | lesen + schreiben | lesen + schreiben |
| Nur Lehrkräfte | lesen + schreiben | unsichtbar |
| Schülerbereich (pro Schüler) | lesen + schreiben | nur der eigene |

- Mitglieder: einzelne Benutzer oder ganze Nextcloud-Gruppen (z. B. Klassengruppe), jeweils als Schüler oder Lehrkraft.
- „An alle Schüler verteilen“ kopiert eine Seite (inkl. Bilder und Tags) in einen Abschnitt jedes Schülers.
- Nur der Besitzer kann das Kursnotizbuch löschen. Wird sein Konto gelöscht, geht es an eine andere Lehrkraft über.

Wer darf Kursnotizbücher anlegen? Standard: alle. Auf Gruppen beschränken:

```bash
docker exec -u www-data nextcloud php occ config:app:set kladory class_creator_groups --value="Lehrer"
# mehrere Gruppen: --value="Lehrer,Schulleitung"   zurücksetzen: --value=""
```

Wer anlegen darf, kann beim Hinzufügen von Mitgliedern alle Benutzer und Gruppen der Nextcloud suchen.

## Export / Import und Übertragen

- Nutzer: unten links „Export / Import“ → .kladory-Datei herunterladen oder im Ordner „Kladory-Export“
  der Nextcloud-Dateien ablegen; Import von Gerät oder aus diesem Ordner. Lehrkräfte exportieren
  Kursnotizbücher komplett (alle Bereiche, Schülerseiten, Mitglieder).
- Admin, z. B. nach Umbenennung im LDAP (VOR dem Löschen des alten Kontos!):

```bash
docker exec nextcloud occ kladory:transfer alter.name neuer.name --dry-run
docker exec nextcloud occ kladory:transfer alter.name neuer.name
docker exec nextcloud occ kladory:export max.mustermann /tmp/max.kladory --to-files
docker exec nextcloud occ kladory:import max.neu /tmp/max.kladory
```

## Neu in 0.8.3

- **Korrektur:** Nextclouds Standard-Stile für bearbeitbare Felder (feste Breite 130 px, Rahmen, Hintergrund,
  im Dunkelmodus schwarz) haben Kladorys Textfelder und das Pencil-Schreibfeld zusammengedrückt. Kladory setzt
  diese Vorgaben jetzt für seine eigenen Felder zurück.

## Neu in 0.8.2

- **Stift → Text neu:** Das Schreibfeld bleibt immer groß und leer. Erkannter Text wandert nach einer kurzen
  Schreibpause in ein Textfeld darüber. Knöpfe „Neuer Absatz“ und „Neues Textfeld“ im Schreibfeld;
  Stift-Tipp auf eine freie Stelle beginnt dort ein neues Textfeld. Das Schreibfeld selbst wird nie gespeichert.

## Neu in 0.8.0

- **Auswählen im Zeichenmodus:** Gezeichnetes umkreisen oder antippen, dann verschieben, an den Ecken vergrößern
  oder verkleinern, duplizieren oder löschen. Alles lässt sich rückgängig machen.
- **Rückgängig/Wiederholen** stehen fest neben dem Umschalter „Text | Zeichnen“, in beiden Modi (0.8.1). Im Textmodus wirken sie auf das gerade bearbeitete Textfeld.
- **Seitenleisten einklappen:** Knopf links neben dem Seitentitel blendet Notizbücher und Seitenliste aus
  (wird pro Gerät gemerkt).

## Leistung und Lasttest (ab 0.7.0)

- **Speichern:** Stiftstriche werden verdichtet und kompakt gespeichert, der Browser sendet komprimiert (gzip).
  Eine vollgeschriebene Handschrift-Seite: ca. 20 KB pro Speichervorgang statt ca. 300 KB; die Antwort enthält
  keinen Seiteninhalt mehr. Gespeichert wird 2 s nach der letzten Änderung, bei Dauerschreiben spätestens alle 15 s.
  Blockiert ein Proxy die Komprimierung, speichert der Browser automatisch auf dem alten Weg.
- **Speicher-Anzeige (0.7.1):** Unter dem Seitentitel zeigt ein Punkt den Zustand: grün „Gespeichert · vor 2 Min.“
  (Maus drüber = genaue Uhrzeit), orange „Änderungen werden gleich gespeichert“, rot „Keine Verbindung“ bzw.
  „Speichern fehlgeschlagen“. Bei Fehlern versucht Kladory es automatisch erneut (5 s, 10 s, 20 s, dann alle 30 s)
  und speichert sofort, sobald das Gerät wieder online ist.
- **LDAP:** Gruppenzugehörigkeit und Mitgliederlisten werden 5 Minuten zwischengespeichert. Dafür muss in
  Nextcloud ein Memcache eingerichtet sein (`memcache.distributed`, z. B. Redis) – bei gebuchten Instanzen meist
  vorhanden. Folge: Wird ein Schüler in LDAP einer Klasse zugeordnet oder entfernt, wirkt das in Kladory
  spätestens nach 5 Minuten.
- **Datenbank:** Der Notizbuch-Baum lädt mit 4–5 Abfragen statt bisher 25–30.
- **Lasttest:** `tools/kladory-lasttest.py` simuliert eine Klasse, die gleichzeitig mit dem Stift schreibt
  (nur Python 3 nötig, läuft auf jedem Linux-Rechner):

  ```bash
  python3 kladory-lasttest.py --url https://cloud.schule.de --user lasttest --password 'APP-PASSWORT' --clients 35 --minutes 3
  ```
  Härter: `--pace 0.5` (doppelt so oft speichern), mehrere Konten mit `--accounts konten.txt`
  (Zeilen `benutzer:app-passwort`). Das Test-Notizbuch wird am Ende endgültig gelöscht.

## Wo liegen die Daten? (wichtig fürs Backup)

- **Texte, Seiten, Tags:** in der Nextcloud-Datenbank (Tabellen `oc_kladory_*`)
- **Bilder:** im Nextcloud-Datenverzeichnis unter `appdata_<instanz-id>/kladory/page-<seiten-id>/`
  (nicht im Dateibereich der Nutzer, zählt nicht zu deren Speicherplatz)

Ein vollständiges Backup braucht also **Datenbank-Dump + Datenverzeichnis**.
Nicht mehr verwendete Bilder werden vom täglichen Hintergrundjob nach einem Tag entfernt,
Bilder von Seiten im Papierkorb bleiben erhalten, bis die Seite endgültig gelöscht wird.

## Papierkorb

Gelöschte Notizbücher, Abschnitte und Seiten landen im Papierkorb des Nutzers
(Knopf unten in der Notizbuch-Leiste) und können dort wiederhergestellt werden.
Ein täglicher Hintergrundjob löscht Einträge nach Ablauf der Aufbewahrungsdauer endgültig.

```bash
# Aufbewahrungsdauer ändern (Standard 30 Tage, 0 = nie automatisch löschen)
docker exec -u www-data nextcloud php occ config:app:set kladory trash_retention_days --value=60 --type=integer

# Papierkorb eines Schülers ansehen und Eintrag wiederherstellen
docker exec -u www-data nextcloud php occ kladory:trash list max.mustermann
docker exec -u www-data nextcloud php occ kladory:trash restore max.mustermann notebook 12

# Eintrag endgültig löschen / abgelaufene Einträge sofort löschen
docker exec -u www-data nextcloud php occ kladory:trash delete max.mustermann page 34
docker exec -u www-data nextcloud php occ kladory:trash purge
```

Wird ein Nextcloud-Konto gelöscht, entfernt Kladory automatisch auch dessen Notizbücher.

## Vollständig entfernen

```bash
docker exec -u www-data nextcloud php occ app:disable kladory
docker exec nextcloud rm -rf /var/www/html/custom_apps/kladory
```

Die Datenbanktabellen bleiben dabei erhalten. Um sie auch zu löschen
(Präfix `oc_` ggf. anpassen):

```sql
DROP TABLE oc_kladory_pages;
DROP TABLE oc_kladory_sections;
DROP TABLE oc_kladory_notebooks;
DELETE FROM oc_migrations WHERE app = 'kladory';
DELETE FROM oc_appconfig  WHERE appid = 'kladory';
```

## Aufbau

- `appinfo/info.xml` – Metadaten
- `lib/Migration` – Datenbankschema
- `lib/Db` – Entities und Mapper
- `lib/Service/NoteService.php` – Logik und Berechtigungsprüfung
- `lib/Controller` – Seite und JSON-API (`/apps/kladory/api/...`)
- `lib/Service/ContentSanitizer.php` – prüft/bereinigt Seiteninhalte (nur erlaubte Formatierungen)
- `js/kladory-main.js` – Oberfläche (Notizbücher, Seiten, Speichern)
- `js/kladory-editor.js` – Leinwand-Editor
- `css/kladory.css` – Gestaltung (kein Build-Schritt nötig)
