<?php

declare(strict_types=1);

namespace tsv;

use tsv\Traits\DatabaseAccess;

/**
 * tsv\ContentRegion — Projektbasis der Regionen MIT Seitenanspruch.
 *
 * Zweite Basis statt einer: Der Seitenanspruch ist eine Typfrage
 * (Xal\ContentRegion erzwingt metadata()), der Datenbankzugriff
 * ein Projektbelang. Beide Achsen sind unabhängig, PHP kennt
 * keine Mehrfachvererbung — also erbt jede Projektbasis von der
 * passenden Engine-Basis und zieht denselben Trait.
 */
abstract class ContentRegion extends \Xal\ContentRegion
{
    use DatabaseAccess;
}
