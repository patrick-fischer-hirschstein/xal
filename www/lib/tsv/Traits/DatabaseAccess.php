<?php

declare(strict_types=1);

namespace tsv\Traits;

use tsv\Database;

/**
 * DatabaseAccess — reine Bequemlichkeit: $this->db() statt
 * Database::pdo(). Trägt KEINEN eigenen Zustand, deshalb ist
 * die Mehrfachnutzung durch tsv\Region und tsv\ContentRegion
 * unbedenklich.
 */
trait DatabaseAccess
{
    protected function db(): \PDO
    {
        return Database::pdo();
    }
}
