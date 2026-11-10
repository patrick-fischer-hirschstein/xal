<?php

declare(strict_types=1);

namespace Xal;

/**
 * Log — Fehlerprotokoll als tägliche Textdatei.
 *
 * Statische Klasse statt getInstance()-Singleton: gleiche
 * Garantien (ein Ziel, überall erreichbar), ohne Zeremonie.
 * Legitim als globaler Zugriffspunkt, weil append-only —
 * niemand liest Zustand, niemand verzweigt darauf, die
 * Schreibreihenfolge ist egal.
 *
 * Tägliche Datei entsteht durch den Dateinamen von selbst
 * (fehler-YYYY-MM-DD.txt), keine Rotationslogik nötig.
 */
final class Log
{
    private static string $dir = __DIR__ . '/logs';

    /** Einmal im Bootstrap setzen. */
    public static function configure(string $dir): void
    {
        self::$dir = rtrim($dir, '/');
    }

    /** @param string $unit Baustein-Kennung, z. B. "region:content" */
    public static function error(string $unit, string $message): void
    {
        if (!is_dir(self::$dir)) {
            // @: Wettlauf zweier Requests beim Anlegen ist harmlos.
            @mkdir(self::$dir, 0775, true);
        }
        $line = sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), $unit, $message);
        @file_put_contents(
            self::$dir . '/fehler-' . date('Y-m-d') . '.txt',
            $line,
            FILE_APPEND | LOCK_EX
        );
        // Logging darf selbst nie werfen — wenn das Log nicht
        // schreibbar ist, hat die Seite trotzdem zu erscheinen.
    }

    /** Komfort: Exception mit Kernzeile des Ursprungs protokollieren. */
    public static function exception(string $unit, \Throwable $e): void
    {
        self::error($unit, sprintf(
            '%s: %s (%s:%d)',
            $e::class,
            $e->getMessage(),
            basename($e->getFile()),
            $e->getLine()
        ));
    }
}
