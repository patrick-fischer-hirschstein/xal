<?php

declare(strict_types=1);

/**
 * autoload.php — PSR-4 über zwei Namensraum-Präfixe.
 *
 * Bewusst ohne Composer: zwei Einträge, keine Abhängigkeit,
 * kein vendor/-Verzeichnis im Baum.
 *
 *   Xal\Assembler        → lib/xal/Assembler.php
 *   tsv\Database         → lib/tsv/Database.php
 *   tsv\Region\Spieltag  → lib/tsv/Region/Spieltag.php
 *
 * Die Verzeichnisse der Präfixe sind kleingeschrieben, alles
 * darunter trägt die Schreibweise des Klassennamens. Der
 * Präfixvergleich ist Groß-/Kleinschreibung-empfindlich —
 * Klassen sind immer exakt so zu schreiben wie deklariert.
 *
 * Templates werden NICHT autogeladen: unter template/ liegt
 * kein PHP. Das ist die tragende Regel der Verzeichnisstruktur
 * (lib/ = Code, template/ = Markup und Styles) und der Grund,
 * warum der ausgelieferte Baum keine deny-Regel braucht.
 */
spl_autoload_register(static function (string $class): void {
    static $map = [
        'Xal\\' => __DIR__ . '/xal/',
        'tsv\\' => __DIR__ . '/tsv/',
    ];

    foreach ($map as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = substr($class, strlen($prefix));
        $file     = $baseDir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
        // Präfix getroffen: keine weitere Wurzel probieren. Fehlt die
        // Datei, soll der "class not found" der Aufrufstelle stehen —
        // ein eigener Fehler hier würde nur die Ursache verdecken.
        return;
    }
});
