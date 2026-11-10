<?php

declare(strict_types=1);

namespace tsv;

/**
 * Database — Verbindungshalter des Projekts.
 *
 * Zweck: EINE Verbindung pro Request, geteilt von allen Regionen.
 * Bewusst nackte PDO ohne Query-Schicht — es wird nur gelesen,
 * kein Vorbau (ADR 0003).
 *
 * Warum eine eigene Klasse und nicht der statische Zustand direkt
 * in der Regionsbasis: der Zustand muss von tsv\Region UND
 * tsv\ContentRegion aus erreichbar sein. Statische Eigenschaften in
 * einem Trait werden je nutzender Klasse EIGENSTÄNDIG angelegt —
 * configure() auf der einen Basis hätte die andere nicht erreicht.
 * Der Halter liegt deshalb hier, die Basen greifen nur zu.
 */
final class Database
{
    private static ?\PDO $pdo = null;

    /** @var array{dsn:string,user:string,pass:string}|null */
    private static ?array $config = null;

    /** Einmal im Bootstrap setzen (Zugangsdaten aus der Umgebung). */
    public static function configure(string $dsn, string $user, string $pass): void
    {
        self::$config = ['dsn' => $dsn, 'user' => $user, 'pass' => $pass];
    }

    /** Lazy: die Verbindung entsteht beim ersten Aufruf. */
    public static function pdo(): \PDO
    {
        if (self::$pdo === null) {
            if (self::$config === null) {
                throw new \RuntimeException('tsv\Database::configure() wurde nicht aufgerufen');
            }
            self::$pdo = new \PDO(
                self::$config['dsn'],
                self::$config['user'],
                self::$config['pass'],
                [
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        }
        return self::$pdo;
    }
}
