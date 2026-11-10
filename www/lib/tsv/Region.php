<?php

declare(strict_types=1);

namespace tsv;

use tsv\Traits\DatabaseAccess;

/**
 * tsv\Region — Projektbasis der Regionen OHNE Seitenanspruch.
 *
 * Die Engine (Xal\Region) definiert nur den Kompositionsvertrag
 * und weiß nichts über Datenherkunft. Alles Projektspezifische
 * hängt hier: derzeit der Datenbankzugriff, künftig etwa
 * gemeinsame Repositories oder Formatierhilfen.
 *
 * Namensgleich mit dem Unterverzeichnis Region/ — das ist
 * gewollt und in PSR-4 zulässig: tsv\Region liegt in
 * lib/tsv/Region.php, tsv\Region\Spieltag in
 * lib/tsv/Region/Spieltag.php.
 */
abstract class Region extends \Xal\Region
{
    use DatabaseAccess;
}
