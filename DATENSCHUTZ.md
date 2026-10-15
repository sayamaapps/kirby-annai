# Annai: technische Grundlage für den Datenschutztext

(English version: [PRIVACY.md](PRIVACY.md). Optionen heißen `annai.*`; die Namen
stehen im [README](README.md#options).)

Dieser Baustein beschreibt die Implementierung. Rechtsgrundlage, Interessenabwägung
und die Formulierung müssen vor Veröffentlichung für den konkreten Betrieb fachlich
geprüft und ergänzt werden. Der Text ist keine Rechtsberatung.

## Suchmaschinen und KI-Agenten — technischer Textbaustein

Die Angaben für Suchmaschinen und KI-Dienste auf unserer Website (Seitenbeschreibung,
Adresse des Betriebs, Öffnungszeiten, Telefonnummer, Seitenliste) werden von unserem
Webserver aus den von uns gepflegten Inhalten erzeugt und als Text ausgeliefert.
Ihr Browser baut dafür **keine Verbindung zu einem anderen Dienst** auf: Es wird kein
Skript, keine Schriftart, keine Einbettung und kein Bild von einem fremden Server
nachgeladen.

Das eingesetzte Modul setzt **keine Cookies**, verwendet **keinen Browser-Speicher**
(etwa Local Storage oder Session Storage) und erhebt **keine Daten über Besucherinnen
und Besucher** dieser Website. Es liest weder Ihre IP-Adresse noch Ihren Browser-Typ
aus, um die Seite auszuliefern.

Die Daten selbst sind Geschäftsangaben, die wir ohnehin veröffentlichen: Name, Adresse,
Telefonnummer, E-Mail-Adresse, Öffnungszeiten und Schließtage sowie die Seiten dieser
Website. Die strukturierten Daten (JSON-LD nach schema.org) und die Dateien
`robots.txt`, `sitemap.xml` und `llms.txt` enthalten ausschließlich solche öffentlichen
Angaben.

## Öffentliche Dateien

Die Dateien `robots.txt`, `sitemap.xml` und `llms.txt` sind **öffentlich lesbar**.
Sie nennen die Adressen unserer Seiten und die Geschäftsangaben oben. Auch die Pfade in
der Zeile `Disallow:` der `robots.txt` sind öffentlich; sie werden deshalb nicht für
Geheimnisse genutzt. Die Datei `llms.txt` ist für KI-Dienste gedacht, die Antworten zu
unserem Betrieb geben sollen.

Ob einzelne KI-Dienste unsere Inhalte für ihre Modelle nutzen dürfen, legen wir in der
`robots.txt` fest. Die Festlegung gilt nur für Dienste, die sich an die `robots.txt`
halten; bereits abgerufene Inhalte werden dadurch nicht zurückgeholt.

## Hinweise für den Betreiber

- **Keine Übertragung durch das Modul.** Das Modul selbst überträgt keine Daten an
  Dritte. Hosting und die Zugriffsprotokolle des Webservers liegen außerhalb des
  Moduls und sind in der allgemeinen Datenschutzerklärung der Website zu beschreiben.
- **Nur Geschäftsangaben eintragen.** Private Telefonnummern, private Adressen und
  Namen von Mitarbeitenden (etwa bei Urlaub) gehören nicht in die Felder, die das Modul
  ausliest, auch nicht auf der Standortseite.
- **Keine geheimen Pfade.** Pfade in `annai.disallow`, zusätzliche Zeilen der
  `robots.txt` und zusätzliche Einträge der Sitemap sind öffentlich.
- **Fehlerprotokoll.** Konfigurationsfehler (etwa eine unbekannte Zeitzone) werden in
  das Fehlerprotokoll des Servers geschrieben. Die Meldung enthält keine Besucherdaten.
- **Vorschauschutz.** Solange eine Vorschau geschützt ist, trägt jede Seite
  `noindex`. `robots.txt`, Sitemap und `llms.txt` sind dann die regulären Dateien; sie
  liegen hinter dem Schutz. Eine Route, die der Betreiber vom Schutz ausnimmt, ist für
  den dafür freigegebenen Crawler während der Vorschau lesbar, also auch die
  Seitenliste. Mit `annai.noindex => true` werden die Dateien stattdessen geschlossen
  (`Disallow: /`). Der Schutz selbst liegt vor dem Modul.
- **Übergabe der Website.** Wer die Website an einen Kunden übergibt, prüft, ob weitere
  Dienste (Analyse, Karten, Formulare) auf derselben Seite eingebunden sind; für diese
  gilt eine eigene Beschreibung. Dieses Modul gehört nicht dazu.
