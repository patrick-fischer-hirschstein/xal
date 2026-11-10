# xal — XHTML-like Attribute Language

xal ist eine schlanke Template- und Kompositionsschicht für PHP 8.4: TAL-artige Attribut-Syntax auf dem Auswertungsmodell von XSLT, ausgeführt über native PHP-Arrays. Kein Kompilierschritt, kein Cache, keine Build-Pipeline, keine Expression-Language. Jede Vorlage ist ein vollständiges, valides HTML-Dokument, das ohne Engine direkt im Browser aufrufbar ist — die Produktionsdatei *ist* die Vorschaudatei.

Das Projekt entstand als Neuinterpretation zweier bewährter Ideen: der Attribut-Templates von PHPTAL (ohne deren Laufvariablen-Zwang und Compile-Cache) und des Regionensystems aus zwanzig Jahren Shop-Praxis (ohne dessen ungelöste Verschachtelungsfreiheit). Die Entwurfsentscheidungen samt Begründungen und verworfenen Alternativen stehen in den ADRs:

- `0001-xal-sprachdefinition.md` — die Sprache (Direktiven, Pfade, Omit-Regeln)
- `0002-xal-kompositionsarchitektur.md` — die Komposition (Ebenen, Slots, Assets, spa)

Diese Datei erklärt, wie das Ganze *funktioniert*. Die ADRs erklären, *warum* es so ist.

## Grundprinzipien

Das System kauft Garantien mit Freiheitsgraden. Jede Grenze ist absichtlich:

**Alle Daten vor dem Rendern.** Die Datenaufbereitung liefert die vollständige Renderform als PHP-Array, bevor die erste Vorlage angefasst wird. Es gibt kein Lazy Loading, keine Callbacks aus der Vorlage heraus, keinen Datenbankzugriff während der Transformation. Wenn eine Vorlage einen Wert nicht erreicht, ist die Datenaufbereitung falsch — nicht die Vorlage zu schwach.

**Kontext statt Variablen.** Es gibt keine benannten Laufvariablen und keine Ausdruckssprache. Ein beweglicher Cursor zeigt auf die aktuelle Stelle im Datenbaum; Pfade sind relativ zu ihm oder zur Wurzel. Sonst nichts.

**Drei Ebenen, fertig.** Seiten bestehen aus Page (Grundgerüst), Region (Komposition eines Inhaltsbereichs) und Component (Baustein). Eine Component kann keine Component enthalten. Die Tiefe ist Grammatik, nicht Konvention.

**Kein `xal:*` in der Ausgabe.** Alle Steuerattribute werden bei der Verarbeitung entfernt. Das Produktions-HTML ist sauber.

## Die Sprache

Steuerattribute liegen im Präfix `xal:` (deklariert als `xmlns:xal="urn:xal"` im Wurzelelement — der HTML-Parser behandelt sie schlicht als Attributnamen mit Doppelpunkt).

### Pfade

Die Grammatik ist binär: Ein blanker Name ist kontextrelativ (`mannschaft`, `verein/name` mit `/` als Segmenttrenner), ein führendes `/` ist wurzelrelativ (`/seitentitel`). Es gibt kein `.` und kein `..` — Elternzugriff existiert nicht. Wer in einer Schleife einen äußeren Wert braucht, denormalisiert ihn in der Datenaufbereitung in die Einträge hinein; wer einen Globalwert braucht, geht über die Wurzel. Fehlt ein Pfad oder liegt unterwegs kein Array, ist das Ergebnis null.

### Direktiven

Es gibt genau vier, mehr kommen nicht:

| Direktive | Wirkung |
|---|---|
| `xal:text="pfad"` | Ersetzt den Inhalt des Elements durch den Pfadwert. Wert null → Element wird entfernt. Opt-out: `xal:text="pfad?"` — Element bleibt, leer. |
| `xal:foreach="pfad"` | Wiederholt das Element je Listeneintrag; der Cursor zeigt im Element auf den jeweiligen Eintrag. Leere/fehlende Liste → Element wird entfernt. |
| `xal:if="pfad"` | Behält das Element bei truthy Wert, entfernt es sonst. Steht es zusammen mit `xal:foreach` am Element, wird es ignoriert — foreach ist „if mit Schleife", Bedingtheit kommt dort aus den Daten. |
| `xal:remove` | Entfernt das Element immer (boolesch per Anwesenheit, kein Pfad). Für reine Vorschau-Elemente — im Browser sichtbar, beim Walk weg. Gewinnt gegen alle anderen Direktiven. |

### Attribut-Bindung

Jedes andere `xal:`-Attribut bindet das gleichnamige HTML-Attribut: `xal:href="url"` setzt `href`, `xal:datetime="datum"` setzt `datetime`. Bei falsy Wert entfällt nur das Attribut, nie das Element — das deckt Boolean-Attribute (`disabled`, `checked`) ohne Sonderregel mit ab: truthy → Attribut gesetzt, falsy → Attribut fehlt.

Einzige Ausnahme: `xal:class` ist **additiv**. Der Wert wird an ein vorhandenes `class` angehängt (mehrere Tokens per Leerzeichen möglich), ein leerer Wert lässt das Bestehende unangetastet. Grund: `class` ist mengenwertig — statische Strukturklasse und dynamische Zustandsklasse sind zwei Beiträge zu einer Menge, keine Konkurrenten um ein Feld.

### Beispiel

```html
<tr xal:foreach="zeilen" xal:class="zone">
  <td xal:text="platz">1</td>
  <td><a xal:href="url" xal:text="mannschaft" href="#">SV Riesa</a></td>
  <td xal:text="punkte">12</td>
</tr>
```

Die Dummy-Werte (`1`, `SV Riesa`, `12`) machen die Datei im Browser vorschaufähig; beim Walk werden sie durch echte Daten ersetzt. Kein Alias, kein `${…}`, keine Wiederholung eines Variablennamens.

## Die drei Ebenen

| Ebene | Rolle | Slot-Attribut darin | Beispiel |
|---|---|---|---|
| **Page** | Invariantes Grundgerüst: Logo, Menüs, Raster | `xal:region="…"` | `template/tsv/page/standard.html` |
| **Region** | Komposition eines Seitentyps: welche Bausteine | `xal:component="…"` | `template/tsv/region/spieltag.html` |
| **Component** | Wiederverwendbarer, datengetriebener Baustein | keins (Blatt) | `template/tsv/component/tabelle.html` |

Der Ordner unter `template/tsv/` **ist** die Ebene — daraus baut der Assembler den Pfad, die Bausteine kennen nur ihren Namen (`spieltag`). Jede Datei aller drei Ebenen ist ein vollständiges HTML-Dokument mit klarer Arbeitsteilung: Der **`head` ist das Asset-Manifest** — geerntet werden **ausschließlich CSS-Dateien** (`<link>`, dedupliziert über href). `<style>`-Blöcke sind reine Standalone-Vorschau (Slot-Färbung, Demo-Zentrierung) und werden vom Assembler vollständig aus der Ausgabe entfernt; Produktions-Styling lebt nur in CSS-Dateien (`base.css` sowie je Baustein eine gleichnamige Datei neben der Vorlage). Der **`body` ist die Nutzlast** (was eingebettet wird).

**Slot-Elemente gehören dem Gerüst.** Die Injektion ersetzt nur ihre Kinder (den Dummy-Inhalt), nie das Element selbst. Slots tragen die Klasse `.slot` (aus `base.css`), die `container-type: inline-size` etabliert — Components reagieren per Container Query auf ihre Einbettungsbreite, nicht auf den Viewport. Layout und Container-Basis hängen ausschließlich an solchen persistenten Klassen; `[xal\:…]`-Attributselektoren sterben mit dem Attribut-Strip und tragen deshalb nur die Vorschau-Färbung.

**Mehrfachverwendung:** Steht dieselbe Component zweimal in einer Region (Liga- und Pokaltabelle), wird ihr CSS einmal geerntet (Schlüssel = Datei) und ihr Body zweimal — mit je eigenen Daten — gewalkt und injiziert.

## Ablauf eines Requests

1. **URL-Auflösung** liefert das `spa` (SiteParameterArray): ein flaches Map-Array mit Seitentyp und IDs (bestehender Mechanismus, per SQL gegen eine View). Der Seitentyp wählt die Komposition, die IDs parametrisieren die Bausteine. Das spa wird immer als Kopie weitergegeben, nie als Referenz.
2. **Datenaufbereitung** lädt anhand der IDs alles, was die Seite braucht, und formt die Renderform — vollständig, bevor irgendetwas gerendert wird.
3. **Komposition** beschreibt deklarativ: welche Page, welche Region je `xal:region`-Slot, welche Component-Daten je `xal:component`-Slot (in Dokumentreihenfolge).
4. Der **Assembler** arbeitet die drei Pässe ab: Page laden und walken, je Region-Slot die Region laden/walken, darin je Component-Slot die Component mit ihren Daten walken, `head`-Assets nach oben ernten (dedupliziert), Bodies in die Slots injizieren, alle `xal:*`-Attribute strippen, fertiges HTML ausgeben.

Jeder Walk läuft in einer eigenen, wegwerfbaren Walker-Instanz — zwei Tabellen im selben Request können sich nicht gegenseitig beeinflussen.

## Verzeichnisstruktur

```
/
├── index.php                       Einstiegspunkt
├── patterns.html                   Muster-Katalog (wird nie assembliert)
├── logs/                           fehler-YYYY-MM-DD.txt
├── lib/
│   ├── autoload.php                PSR-4 über zwei Präfixe
│   ├── xal/                        Namensraum Xal\  — die Engine
│   │   ├── Assembler.php  Walker.php  Resolver.php
│   │   ├── Region.php     ContentRegion.php
│   │   └── Log.php
│   └── tsv/                        Namensraum tsv\  — das Projekt
│       ├── Database.php            Verbindungshalter
│       ├── Region.php              Projektbasis (ohne Seitenanspruch)
│       ├── ContentRegion.php       Projektbasis (mit Seitenanspruch)
│       ├── Traits/DatabaseAccess.php
│       └── Region/                 Spieltag.php · Header.php
└── template/tsv/
    ├── base.css
    ├── page/                       standard.html · standard.css
    ├── region/                     spieltag.html · spieltag.css
    │                               header.html   · header.css
    └── component/                  tabelle.html  · tabelle.css
```

Die tragende Regel ist **`lib/` = Code, `template/` = Markup und Styles**. Unter `template/` liegt kein PHP — deshalb braucht der ausgelieferte Baum keine `deny`-Regel im Webserver. Die Aufteilung folgt der Frage, die man beim Suchen tatsächlich stellt, nicht dem Namensraum: `tsv\Region\Spieltag` liegt in `lib/`, `spieltag.html` in `template/`.

`lib/xal/` ist projektfrei. Die Engine kennt keinen Datenbankzugriff und keine Vereinsdaten; alles Projektspezifische hängt an `tsv\Region` / `tsv\ContentRegion`, die von den Engine-Basen erben und `db()` mitbringen. Zwei Projektbasen statt einer, weil Seitenanspruch (Typfrage) und Datenzugriff (Projektbelang) unabhängige Achsen sind und PHP keine Mehrfachvererbung kennt — der gemeinsame Anteil steckt im Trait `tsv\Traits\DatabaseAccess`, der bewusst zustandsfrei ist (statische Eigenschaften in einem Trait werden je nutzender Klasse eigenständig angelegt; der Verbindungshalter liegt deshalb in `tsv\Database`).

## Bausteinnamen und CSS-Pfade

Ein Baustein kennt seinen **Namen**, nicht seinen Ablageort: `Spieltag::name()` liefert `'spieltag'`, die Komposition nennt `['name' => 'tabelle', 'data' => …]`. Den Pfad baut allein der Assembler aus Stufe + Name. Ein Umzug des Vorlagenbaums ist damit ein Konstruktorargument, keine Änderung an Regionen.

Die Vorlagen verlinken ihr CSS **relativ** (`href="tabelle.css"`, `href="../base.css"`), damit sie per `file://` standalone funktionieren. Der Browser rechnet einen geernteten `href` aber gegen die *Seiten*-URL, nicht gegen die Herkunft der Vorlage — `tabelle.css` würde in der assemblierten Seite auf `/tabelle.css` zeigen. Der Assembler löst deshalb beim Ernten jeden `href` gegen das Web-Verzeichnis seiner Quellvorlage auf und schreibt ihn wurzelabsolut:

```
component/tabelle.html   href="tabelle.css"   → /template/tsv/component/tabelle.css
component/tabelle.html   href="../base.css"   → /template/tsv/base.css
page/standard.html       href="../base.css"   → /template/tsv/base.css   (dedupliziert)
```

Der Dedup-Schlüssel ist der normalisierte Pfad, nicht der rohe `href` — nur so greift die `base.css`-Entdopplung, obwohl Page und Component verschieden tief liegen. Absolute hrefs und solche mit Schema bleiben unangetastet. Voraussetzung ist, dass `template/tsv` zugleich Dateisystem- und URL-Teilpfad ist; die Normalisierung braucht dadurch keine Konfiguration.

## Die PHP-Klassen

**`Xal\Resolver`** — zustandslose Pfadauflösung: `resolve(path, context, root)`. Die gesamte Pfadgrammatik in einer reinen Funktion.

**`Xal\Walker`** — die Sprachimplementierung auf `Dom\HTMLDocument` (PHP ≥ 8.4). Eine Instanz pro Walk; Zustand sind genau zwei Referenzen (Wurzel und wandernder Kontext). Verarbeitungsreihenfolge je Element: `xal:if` → `xal:foreach` → `xal:text` → Attribut-Bindungen → Kinder.

**`Xal\Assembler`** — die Kompositionsschicht. Kennt Walker und Resolver (nicht die Region-Klassen — Weg A), führt die drei Pässe aus, erntet und dedupliziert CSS-Links, injiziert, strippt. Fehlerverhalten: siehe unten.

**`Xal\Region` / `Xal\ContentRegion`** — abstrakte Basen der Regionen (ADR 0003), je in eigener Datei (der Autoloader bildet Klasse auf Datei ab). Der Konstruktor nimmt das spa und sammelt ALLE Daten; danach sind `name()/data()/components()` (und bei Content-Regionen `metadata()`) reine Getter. `metadata()` existiert nur auf `ContentRegion` und ist dort Pflicht — der Seitenanspruch ist eine Typfrage. Datenzugriff enthalten diese Basen bewusst nicht.

**`tsv\Database` / `tsv\Region` / `tsv\ContentRegion`** — die Projektseite. `Database` hält lazy eine PDO pro Request (`configure()` im Bootstrap; nackte PDO ohne Query-Schicht, es wird nur gelesen), die Basen stellen `db()` bereit. Konkrete Regionen (`tsv\Region\Spieltag`) tragen die Datenaufbereitungs-Logik ihres Seitentyps.

**`Xal\Log`** — Fehlerprotokoll als statische Klasse, tägliche Datei `logs/fehler-YYYY-MM-DD.txt`. Append-only; der eine legitime globale Zugriffspunkt des Systems.

**`index.php`** — der Einstiegspunkt: spa auflösen, Daten laden, Komposition bauen, Assembler laufen lassen. Wenige Zeilen Verdrahtung, kein Container, kein Framework, keine Singletons (PHP Shared-Nothing macht jede Instanz ohnehin request-lokal).

## Fehlerverhalten: Produktion degradiert, Dev wirft

**Produktion (Standard):** Besucher sehen immer die Seite. Defekte oder unverdrahtete Bausteine werden in `logs/fehler-YYYY-MM-DD.txt` protokolliert und degradiert: Eine ausgefallene Region zeigt einen Entschuldigungs-Platzhalter (`.hinweis`-Muster), eine ausgefallene Component wird still weggelassen. Slot-Elemente bleiben stehen (Gerüstregel). Einziger Totalausfall: die Page-Vorlage selbst fehlt — dann Notseite aus `index.php`.

**Dev-Modus (explizit, `devMode: true`):** Fail-Fast — echte Fehler werfen. Unverdrahtete Slots sind kein Fehler, sondern Arbeitsstand: Sie behalten Dummy und Slot-Attribut und werden durch einen vom Assembler injizierten Style-Block sichtbar markiert.

**Metadaten** (Titel, Description) liefert die PHP-Klasse der Content-Region (`metadata()`); die Orchestrierung legt sie unter `meta` in die Page-Daten, die Page bindet sie per `xal:text`/`xal:content`. Region-Templates sind meta-frei — Metadaten sind Werte, kein einbettbarer Inhalt.

## Konventionen beim Entwickeln

**Wurzelselektor.** Ohne Shadow DOM ist das aggregierte CSS eine globale Kaskade. Deshalb stylt jede Baustein-Datei ausschließlich unter einer Typ-Klasse an ihrem Wurzelelement (`.component-tabelle table { … }`). Einzige Ausnahme: Muster-Klassen aus `base.css`.

**Muster statt Mini-Components.** Triviales, Wiederkehrendes (Überschriften, Hinweise) ist kein Baustein, sondern Vokabular: ein Markup-Idiom plus Regeln in `base.css`, verwendet per Kopie. Der lebende Katalog ist `patterns.html` — im Browser aufrufbar, wird nie assembliert. Component wird etwas erst mit eigener Datenparametrisierung, eigenem Asset-Bündel und Existenzberechtigung als Einbettungseinheit.

**Slot-Färbung.** Die Standalone-Vorschau färbt Slots über Attributselektoren (`[xal\:region]`, `[xal\:component]`) in `<style>`-Blöcken und beschriftet sie per `::before` mit ihrem Namen selbst. Da Style-Blöcke nie ausgeliefert werden, existiert in der Produktion davon nichts. Die Dev-Diagnose unverdrahteter Slots übernimmt der Assembler mit einem eigenen injizierten Style-Block — Zuständigkeit beim Werkzeug, nicht in den Templates.

**Vorschau-Zeilen.** Damit ein Baustein standalone realistisch aussieht, tragen zusätzliche Dummy-Einträge das boolesche `xal:remove` — im Browser sichtbar, beim Walk entfernt. Kein reservierter Pfadname, keine Konvention: Die Direktive sagt strukturell, was gemeint ist.

**Datenschlüssel sind deutsch**, Code-Bezeichner englisch: Die Schlüssel (`titel`, `zeilen`, `mannschaft`) gehören der Template-Pfadwelt und müssen exakt zu den `xal:`-Pfaden im Markup passen; Funktions- und Variablennamen im PHP gehören dem Code.

## Aufrufen

Jede Vorlage unter `template/tsv/` und `patterns.html` direkt im Browser öffnen (auch per `file://`) → Standalone-Vorschau. `index.php` auf einem PHP-8.4-Server aufrufen → assemblierte Seite.

## Bewusste Grenzen

Kein Elternzugriff im Pfad, keine fünfte Direktive, keine Verschachtelung von Components, keine Ausdruckssprache, keine Callbacks in den Walker, kein Singleton. Jede dieser Grenzen tauscht einen Freiheitsgrad gegen eine Garantie — und beendet eine Diskussion, die sonst jeder Entwickler für sich selbst führen würde. Wer eine Grenze erweitern will, schreibt ein neues ADR, keinen stillen Umbau.
