<?php

declare(strict_types=1);

namespace Xal;

/**
 * Assembler — Kompositionsschicht nach ADR 0002 / 0003.
 *
 * Drei-Pass fester Tiefe (keine Rekursion):
 *   Page → Regionen in [xal:region] → Components in [xal:component]
 *
 * Regeln:
 *   - Slot-Elemente gehören dem Gerüst: Injektion ersetzt nur
 *     deren KINDER, nie das Element selbst.
 *   - Geerntet werden ausschließlich CSS-Dateien (<link>),
 *     dedupliziert über den normalisierten Pfad. <style>-Blöcke
 *     sind Vorschau und werden aus der Ausgabe entfernt;
 *     Dev-Diagnose injiziert der Assembler selbst.
 *   - Ausgabe-Hygiene: kein xal:* und kein xmlns:xal im Ergebnis.
 *
 * Pfadwissen liegt AUSSCHLIESSLICH hier. Bausteine kennen nur
 * ihren Namen ("spieltag"); der Assembler kennt die Stufe und
 * baut daraus template/<projekt>/region/spieltag.html.
 *
 * Fehlermodell (ADR 0003):
 *   - Produktion (Standard): Besucher sehen IMMER die Seite.
 *     Fehlende/defekte Bausteine → Log + Degradation:
 *     Region → Entschuldigungs-Platzhalter im Slot,
 *     Component → Slot wird geleert. Verdrahtungsfehler
 *     (überzählige Einträge, unbekannte Regionsnamen) → nur Log.
 *   - Dev-Modus (explizit): Fail-Fast — echte Fehler werfen.
 *     Unverdrahtete Slots sind KEIN Fehler, sondern Arbeitsstand:
 *     sie behalten Dummy + Slot-Attribut und werden per
 *     injiziertem Dev-CSS sichtbar markiert.
 *   - Einziger Totalausfall: die Page-Vorlage selbst fehlt —
 *     das wirft immer; die Notseite baut der Aufrufer.
 */
final class Assembler
{
    private const APOLOGY =
        'Dieser Bereich steht gerade leider nicht zur Verfügung. Wir arbeiten daran.';

    /** Die drei Ebenen als Verzeichnisnamen unterhalb von templateDir. */
    private const STAGE_PAGE      = 'page';
    private const STAGE_REGION    = 'region';
    private const STAGE_COMPONENT = 'component';

    /** @var array<string, true> je Baustein nur einmal ernten */
    private array $harvested = [];

    /** @var array<string, true> Dedup über den normalisierten href */
    private array $linkHrefs = [];

    /**
     * @param string $rootDir     Dateisystem-Wurzel = Docroot (dort liegt index.php)
     * @param string $templateDir Vorlagenwurzel RELATIV zum Docroot, z. B. 'template/tsv'.
     *                            Doppelt genutzt: als Dateisystem- und als URL-Teilpfad —
     *                            das ist die Voraussetzung dafür, dass die
     *                            href-Normalisierung ohne Konfiguration auskommt.
     */
    public function __construct(
        private readonly string $rootDir,
        private readonly string $templateDir,
        private readonly bool $devMode = false,
    ) {
    }

    /**
     * $composition = [
     *   'page'    => 'standard',
     *   'data'    => [... Wurzelkontext der Page, inkl. 'meta' ...],
     *   'regions' => [
     *     'content' => ['name' => 'spieltag', 'data' => …, 'components' => [
     *        ['name' => 'tabelle', 'data' => …],
     *     ]],
     *   ],
     * ];
     */
    public function assemble(array $composition): string
    {
        // Page-Vorlage: der eine nicht degradierbare Fall — wirft.
        $page = $this->loadAndWalk(
            self::STAGE_PAGE,
            (string) ($composition['page'] ?? ''),
            $composition['data'] ?? []
        );

        // Die Page BLEIBT das Ausgabedokument — ihre eigenen hrefs
        // werden deshalb an Ort und Stelle umgeschrieben, nicht geerntet.
        $this->normalizeOwnLinks($page);

        $usedRegions = [];
        foreach ($this->findSlots($page, 'xal:region') as $slot) {
            $slotName = $slot->getAttribute('xal:region');
            $spec     = $composition['regions'][$slotName] ?? null;

            if ($spec === null) {
                // Dev: Arbeitsstand, sichtbar lassen. Produktion: Log + Entschuldigung.
                if (!$this->devMode) {
                    Log::error("region:{$slotName}", 'nicht verdrahtet');
                    $this->degrade($slot, $page, self::APOLOGY);
                }
                continue;
            }
            $usedRegions[$slotName] = true;
            $slot->removeAttribute('xal:region');

            try {
                $name   = (string) $spec['name'];
                $region = $this->loadAndWalk(self::STAGE_REGION, $name, $spec['data'] ?? []);
                $this->fillComponentSlots($region, $spec['components'] ?? [], $page);
                $this->harvestHead($region, self::STAGE_REGION, $name, $page);
                $this->inject($slot, $region);
            } catch (\Throwable $e) {
                if ($this->devMode) {
                    throw $e;
                }
                Log::exception("region:{$slotName}", $e);
                $this->degrade($slot, $page, self::APOLOGY);
            }
        }

        // Verdrahtungsfehler: Einträge ohne Slot (typisch: Tippfehler).
        $unused = array_diff_key($composition['regions'] ?? [], $usedRegions);
        if ($unused !== []) {
            $message = 'Komposition enthält Regionen ohne Slot: '
                . implode(', ', array_keys($unused));
            if ($this->devMode) {
                throw new \RuntimeException($message);
            }
            Log::error('komposition', $message);
        }

        $page->documentElement?->removeAttribute('xmlns:xal');
        $this->finalizeStyles($page);

        return $page->saveHtml();
    }

    private function fillComponentSlots(
        \Dom\HTMLDocument $region,
        array $entries,
        \Dom\HTMLDocument $page,
    ): void {
        $index = 0;
        foreach ($this->findSlots($region, 'xal:component') as $slot) {
            $type  = $slot->getAttribute('xal:component');
            $entry = $entries[$index++] ?? null;

            if ($entry === null) {
                // Dev: Arbeitsstand. Produktion: Log + stilles Weglassen
                // (Component ohne Entschuldigung — ADR 0003).
                if (!$this->devMode) {
                    Log::error("component:{$type}", 'Slot #' . ($index - 1) . ' nicht verdrahtet');
                    $this->degrade($slot, $page, null);
                }
                continue;
            }
            $slot->removeAttribute('xal:component');

            try {
                $name      = (string) $entry['name'];
                $component = $this->loadAndWalk(self::STAGE_COMPONENT, $name, $entry['data'] ?? []);
                $this->harvestHead($component, self::STAGE_COMPONENT, $name, $page);
                $this->inject($slot, $component);
            } catch (\Throwable $e) {
                if ($this->devMode) {
                    throw $e;
                }
                Log::exception("component:{$type}", $e);
                $this->degrade($slot, $page, null);
            }
        }

        if (count($entries) > $index) {
            $message = (count($entries) - $index) . ' Component-Eintrag/Einträge ohne Slot';
            if ($this->devMode) {
                throw new \RuntimeException($message);
            }
            Log::error('komposition', $message);
        }
    }

    /* ---- Pfade ----------------------------------------------------- */

    /** Dateisystempfad einer Vorlage. */
    private function templateFile(string $stage, string $name): string
    {
        if ($name === '' || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $name)) {
            throw new \RuntimeException("Ungültiger Bausteinname: '{$name}'");
        }
        return $this->rootDir . '/' . $this->templateDir . '/' . $stage . '/' . $name . '.html';
    }

    /** Wurzelabsolutes Web-Verzeichnis einer Stufe, ohne Schrägstrich am Ende. */
    private function templateWebDir(string $stage): string
    {
        return '/' . trim($this->templateDir, '/') . '/' . $stage;
    }

    /**
     * Löst einen href aus einer Vorlage gegen das Web-Verzeichnis
     * dieser Vorlage auf und liefert ihn wurzelabsolut.
     *
     * Der Grund: Vorlagen verlinken RELATIV, damit sie per file://
     * standalone funktionieren. Der Browser rechnet einen geernteten
     * href aber gegen die SEITEN-URL, nicht gegen die Herkunft der
     * Vorlage — ohne Umrechnung zeigt "tabelle.css" auf /tabelle.css.
     *
     *   /template/tsv/component + "tabelle.css" → /template/tsv/component/tabelle.css
     *   /template/tsv/component + "../base.css" → /template/tsv/base.css
     *
     * Absolute hrefs und solche mit Schema bleiben unangetastet.
     */
    private function resolveHref(string $webDir, string $href): string
    {
        $href = trim($href);
        if ($href === ''
            || $href[0] === '/'
            || str_contains($href, '://')
            || str_starts_with($href, 'data:')) {
            return $href;
        }

        $segments = [];
        foreach (explode('/', $webDir . '/' . $href) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                // Über die Wurzel hinaus verfällt — wie im Browser.
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return '/' . implode('/', $segments);
    }

    /* ---- Bausteine ------------------------------------------------- */

    /**
     * Degradation: Slot-Kinder ersetzen, Slot-Attribut strippen.
     * $text = null → leerer Slot (Component), sonst Platzhaltertext
     * im .hinweis-Muster (Region).
     */
    private function degrade(\Dom\Element $slot, \Dom\HTMLDocument $page, ?string $text): void
    {
        $slot->removeAttribute('xal:region');
        $slot->removeAttribute('xal:component');
        while ($slot->firstChild !== null) {
            $slot->firstChild->remove();
        }
        if ($text !== null) {
            $p = $page->createElement('p');
            $p->setAttribute('class', 'hinweis');
            $p->textContent = $text;
            $slot->appendChild($p);
        }
    }

    private function loadAndWalk(string $stage, string $name, array $data): \Dom\HTMLDocument
    {
        $file = $this->templateFile($stage, $name);
        $html = @file_get_contents($file);
        if ($html === false) {
            throw new \RuntimeException("Vorlage nicht lesbar: {$stage}/{$name}.html");
        }
        $doc = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        (new Walker($data))->walk($doc);
        return $doc;
    }

    /** @return list<\Dom\Element> in Dokumentreihenfolge */
    private function findSlots(\Dom\HTMLDocument $doc, string $attribute): array
    {
        $matches = [];
        $search = function (\Dom\Element $el) use (&$search, &$matches, $attribute): void {
            if ($el->hasAttribute($attribute)) {
                $matches[] = $el;
                return; // feste Tiefe: unterhalb eines Slots keine weiteren Slots
            }
            // Dom\HTMLElement hat kein ->children — über childNodes filtern.
            foreach ($el->childNodes as $child) {
                if ($child instanceof \Dom\Element) {
                    $search($child);
                }
            }
        };
        if ($doc->body !== null) {
            $search($doc->body);
        }
        return $matches;
    }

    /**
     * Injektion: ersetzt nur die Kinder des Slot-Elements.
     *
     * Das Zieldokument ergibt sich aus dem Slot selbst und wird
     * deshalb nicht übergeben: Bei Component-Slots gehört der Slot
     * dem Regions-, nicht dem Seitendokument. Ein Import ins
     * falsche Dokument quittiert Dom\HTMLDocument mit
     * "Wrong Document Error".
     */
    private function inject(
        \Dom\Element $slot,
        \Dom\HTMLDocument $source,
    ): void {
        while ($slot->firstChild !== null) {
            $slot->firstChild->remove();
        }
        if ($source->body === null) {
            return;
        }
        $owner = $slot->ownerDocument;
        foreach (iterator_to_array($source->body->childNodes) as $node) {
            $slot->appendChild($owner->importNode($node, true));
        }
    }

    /**
     * Die hrefs der Page selbst: umschreiben statt ernten, weil die
     * Elemente bereits im Ausgabedokument stehen. Registriert sie
     * zugleich für die Dedup — base.css kommt aus jedem Baustein.
     */
    private function normalizeOwnLinks(\Dom\HTMLDocument $page): void
    {
        if ($page->head === null) {
            return;
        }
        $webDir = $this->templateWebDir(self::STAGE_PAGE);
        foreach ($page->head->querySelectorAll('link[href]') as $link) {
            $href = $this->resolveHref($webDir, $link->getAttribute('href') ?? '');
            if ($href === '') {
                continue;
            }
            $link->setAttribute('href', $href);
            $this->linkHrefs[$href] = true;
        }
    }

    /**
     * Ernte: nur CSS-Dateien (<link>) — <style>-Blöcke sind Vorschau.
     * Ziel ist stets der Seiten-head; anders als bei inject() gibt es
     * hier keine Dokument-Mehrdeutigkeit.
     *
     * Dedup-Schlüssel ist der NORMALISIERTE href: nur so greift die
     * base.css-Entdopplung auch dann, wenn Page und Component
     * unterschiedlich tief liegen und deshalb verschieden verlinken.
     */
    private function harvestHead(
        \Dom\HTMLDocument $source,
        string $stage,
        string $name,
        \Dom\HTMLDocument $target,
    ): void {
        $key = $stage . '/' . $name;
        if (isset($this->harvested[$key])) {
            return; // Mehrfachverwendung: CSS einmal, body mehrfach
        }
        $this->harvested[$key] = true;

        if ($source->head === null || $target->head === null) {
            return;
        }
        $webDir = $this->templateWebDir($stage);
        foreach ($source->head->querySelectorAll('link[href]') as $link) {
            $href = $this->resolveHref($webDir, $link->getAttribute('href') ?? '');
            if ($href === '' || isset($this->linkHrefs[$href])) {
                continue;
            }
            $this->linkHrefs[$href] = true;
            $copy = $target->importNode($link, true);
            $copy->setAttribute('href', $href);
            $target->head->appendChild($copy);
        }
    }

    /**
     * Style-Blöcke gelten ausschließlich der Standalone-Vorschau —
     * alle <style>-Elemente werden entfernt. Im Dev-Modus injiziert
     * der Assembler danach seine eigene Slot-Diagnose.
     */
    private function finalizeStyles(\Dom\HTMLDocument $page): void
    {
        foreach (iterator_to_array($page->querySelectorAll('style')) as $style) {
            $style->remove();
        }
        if ($this->devMode && $page->head !== null) {
            $dev = $page->createElement('style');
            $dev->textContent = <<<CSS
                [xal\\:region], [xal\\:component] {
                  outline: 2px dashed #b00;
                  outline-offset: -2px;
                  background: repeating-linear-gradient(45deg,
                    #fdecec 0, #fdecec 12px, #fad9d9 12px, #fad9d9 24px);
                }
                [xal\\:region]::before { content: "unverdrahtet — region: " attr(xal:region); }
                [xal\\:component]::before { content: "unverdrahtet — component: " attr(xal:component); }
                [xal\\:region]::before, [xal\\:component]::before {
                  display: block; width: max-content; padding: .2rem .5rem;
                  font: 600 .72rem ui-monospace, monospace; color: #fff; background: #b00;
                }
                CSS;
            $page->head->appendChild($dev);
        }
    }
}
