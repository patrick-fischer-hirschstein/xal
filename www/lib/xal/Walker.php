<?php

declare(strict_types=1);

namespace Xal;

/**
 * Walker — Sprachimplementierung nach ADR 0001.
 *
 * Eine Instanz pro Walk (erzeugen, benutzen, wegwerfen):
 * kein Restzustand zwischen Läufen, paralleler Walk zweier
 * Components (Liga/Pokal) ist damit trivial korrekt.
 *
 * Zustand: genau zwei Referenzen — Wurzel (fix) und Kontext
 * (wandert als Parameter durch die Rekursion).
 *
 * Verarbeitungsreihenfolge je Element:
 *   xal:remove → xal:if → xal:foreach → xal:text → Attribut-Bindungen → Kinder
 * Kombinationsregel: if + foreach am selben Element → if wird ignoriert.
 *
 * Benötigt PHP >= 8.4 (Dom\HTMLDocument, HTML5-Parser).
 * Der HTML-Parser kennt keine XML-Namensräume: "xal:text" ist
 * schlicht ein Attributname mit Doppelpunkt — genau richtig so.
 *
 * Abweichungen von Dom\HTMLDocument gegenüber dem alten DOMDocument
 * (im Praxistest gefunden, hier berücksichtigt):
 *   - getAttribute() liefert null statt "" für fehlende Attribute
 *   - Dom\HTMLElement hat keine ->children-Eigenschaft
 */
final class Walker
{
    private const DIRECTIVES = ['xal:remove', 'xal:if', 'xal:foreach', 'xal:text'];

    /** Slot-Attribute gehören dem Assembler (ADR 0002) — der Walker
     *  fasst sie nicht an, weder als Direktive noch als Bindung.
     *  Ohne diese Ausnahme frisst die generische Attribut-Bindung sie auf. */
    private const SLOTS = ['xal:region', 'xal:component'];

    public function __construct(
        private readonly array $root,
    ) {
    }

    /** Transformiert das Dokument in-place. */
    public function walk(\Dom\HTMLDocument $doc): void
    {
        if ($doc->body !== null) {
            $this->walkChildren($doc->body, $this->root);
        }
        if ($doc->head !== null) {
            // head kann Bindungen tragen (z. B. <title xal:text="/meta/titel">)
            $this->walkChildren($doc->head, $this->root);
        }
    }

    private function walkChildren(\Dom\Element $parent, array $context): void
    {
        // Schnappschuss: die Liste ändert sich während der Verarbeitung
        // (foreach klont, Omit entfernt) — nie über eine Live-Liste laufen.
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof \Dom\Element) {
                $this->processElement($child, $context);
            }
        }
    }

    private function processElement(\Dom\Element $el, array $context): void
    {
        /* ---- xal:remove ---------------------------------------------- */
        // Reine Vorschau-Elemente (Dummy-Zeilen, Demo-Menüpunkte):
        // boolesch per Anwesenheit, kein Pfad, gewinnt gegen alles.
        if ($el->hasAttribute('xal:remove')) {
            $el->remove();
            return;
        }

        /* ---- Kombinationsregel --------------------------------------- */
        // foreach ist "if mit Schleife": steht beides am Element,
        // zählt nur foreach — das if wird ignoriert (ADR 0001).
        // Bedingtheit einer Schleife kommt aus den Daten (leere Liste).
        if ($el->hasAttribute('xal:foreach')) {
            $el->removeAttribute('xal:if');
        }

        /* ---- xal:if -------------------------------------------------- */
        if ($el->hasAttribute('xal:if')) {
            $value = Resolver::resolve($el->getAttribute('xal:if'), $context, $this->root);
            if (!$value) {
                $el->remove();
                return;
            }
            $el->removeAttribute('xal:if');
        }

        /* ---- xal:foreach --------------------------------------------- */
        if ($el->hasAttribute('xal:foreach')) {
            $path = $el->getAttribute('xal:foreach');
            // Attribut VOR dem Klonen entfernen: Klone dürfen die
            // Schleife nicht erneut auslösen.
            $el->removeAttribute('xal:foreach');

            $items = Resolver::resolve($path, $context, $this->root);
            if (!is_array($items) || $items === []) {
                // Auto-Omit: keine Daten, kein Element (ADR 0001).
                // Leermeldungen liefert die Datenaufbereitung als
                // eigenen Wert (z. B. xal:text auf einer Meldung).
                $el->remove();
                return;
            }

            foreach ($items as $item) {
                // Renderform-Regel: Einträge MÜSSEN Arrays sein.
                // Stilles Überspringen würde Datenfehler verstecken.
                if (!is_array($item)) {
                    throw new \RuntimeException(
                        "xal:foreach '{$path}': Eintrag ist kein Array — "
                        . 'die Datenaufbereitung liefert die Renderform'
                    );
                }
                $clone = $el->cloneNode(true);
                $el->parentNode?->insertBefore($clone, $el);
                // Cursor zeigt auf den Eintrag — alles darunter kontextrelativ.
                $this->processElement($clone, $item);
            }
            $el->remove(); // Vorlagenelement selbst verschwindet
            return;
        }

        /* ---- xal:text ------------------------------------------------ */
        if ($el->hasAttribute('xal:text')) {
            $path = $el->getAttribute('xal:text');
            $keep = str_ends_with($path, '?'); // Opt-out: Element bleibt leer
            if ($keep) {
                $path = substr($path, 0, -1);
            }
            $el->removeAttribute('xal:text');

            $value = Resolver::resolve($path, $context, $this->root);
            if ($value === null) {
                if (!$keep) {
                    $el->remove(); // Auto-Omit auf Element-Ebene
                    return;
                }
                $el->textContent = '';
            } else {
                $el->textContent = (string) $value; // textContent escaped implizit
            }
        }

        /* ---- generische Attribut-Bindungen --------------------------- */
        // Schnappschuss der Attributnamen: wir entfernen währenddessen.
        $names = [];
        foreach ($el->attributes as $attr) {
            $names[] = $attr->name;
        }
        foreach ($names as $name) {
            if (!str_starts_with($name, 'xal:')
                || in_array($name, self::DIRECTIVES, true)
                || in_array($name, self::SLOTS, true)) {
                continue;
            }
            $target = substr($name, 4);
            $value  = Resolver::resolve($el->getAttribute($name), $context, $this->root);
            $el->removeAttribute($name);

            if ($target === 'class') {
                // Additiv, nie ersetzend, leer → unangetastet (ADR 0001).
                if (is_string($value) && trim($value) !== '') {
                    // getAttribute() liefert bei Dom\HTMLDocument null, wenn das
                    // Attribut fehlt — nicht "" wie beim alten DOMDocument.
                    $existing = preg_split('/\s+/', trim($el->getAttribute('class') ?? ''), -1, PREG_SPLIT_NO_EMPTY);
                    $added    = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
                    $el->setAttribute('class', implode(' ', array_unique([...$existing, ...$added])));
                }
                continue;
            }

            if (!$value) {
                continue; // falsy → Attribut entfällt (wurde bereits entfernt)
            }
            // true → Anwesenheits-Attribut (disabled etc.), sonst Stringwert.
            $el->setAttribute($target, $value === true ? '' : (string) $value);
        }

        /* ---- Abstieg ------------------------------------------------- */
        $this->walkChildren($el, $context);
    }
}
