<?php

declare(strict_types=1);

namespace Xal;

/**
 * ContentRegion — Region MIT Seitenanspruch.
 *
 * Genau eine pro Seite. metadata() ist abstrakt und
 * nicht-nullable: Eine Content-Region MUSS Titel & Co.
 * liefern — der Compiler erzwingt, was sonst Konvention wäre.
 * Erwartete Schlüssel: 'titel', 'beschreibung' (erweiterbar).
 *
 * Eigene Datei (nicht mehr bei Region), weil der Autoloader
 * Klasse und Datei eins zu eins abbildet.
 */
abstract class ContentRegion extends Region
{
    abstract public function metadata(): array;
}
