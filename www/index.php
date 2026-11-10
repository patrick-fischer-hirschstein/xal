<?php

declare(strict_types=1);

require __DIR__ . '/lib/autoload.php';

/**
 * index.php — Einstiegspunkt (Prototyp), Zwei-Phasen-Ablauf.
 *
 * Phase 1 (Aufbereitung): spa auflösen, Region-Objekte
 * instanziieren (Konstruktoren sammeln ALLE Daten inkl. DB),
 * Metadaten der Content-Region in die Page-Daten legen,
 * Komposition bauen. Keine Vorlage wurde angefasst, kein Byte
 * ausgegeben — Fehler hier münden sauber in die Notseite.
 *
 * Phase 2 (Rendern): assemble() — reine Transformation.
 * Weg A garantiert die Reihenfolge strukturell: assemble()
 * ist ohne fertige Komposition nicht aufrufbar.
 *
 * Die Komposition nennt nur NAMEN ("standard", "spieltag",
 * "tabelle"). Wo die zugehörigen Vorlagen liegen, weiß allein
 * der Assembler.
 */

\Xal\Log::configure(__DIR__ . '/logs');

// Zugangsdaten zur Live-DB aus der Umgebung (Container-ENV).
// Erst hier gesetzt, verbunden wird lazy beim ersten db()-Aufruf.
\tsv\Database::configure(
    getenv('XAL_DB_DSN')  ?: 'mysql:host=localhost;dbname=tsv;charset=utf8mb4',
    getenv('XAL_DB_USER') ?: '',
    getenv('XAL_DB_PASS') ?: ''
);

/* ---- spa (simuliert; produktiv: bestehender View/SQL) -------- */
$spa = [
    'seitentyp' => 'spieltag',
    'liga_id'   => 7,
    'pokal_id'  => 12,
];

try {
    /* ---- Phase 1: Aufbereitung ---------------------------------- */
    // Der Seitentyp wählt die Content-Region; hier später die
    // Verzweigung je Seitentyp (match auf $spa['seitentyp']).
    $content = new \tsv\Region\Spieltag($spa);
    $header  = new \tsv\Region\Header($spa);

    $pageData = [
        // Fallback-Meta, falls eine Seite ohne Content-Region rendert.
        'meta' => ['titel' => 'SV Riesa', 'beschreibung' => ''],
    ];
    if ($content instanceof \Xal\ContentRegion) {
        $pageData['meta'] = $content->metadata();
    }

    $composition = [
        'page' => 'standard',
        'data' => $pageData,
        'regions' => [
            'header' => [
                'name'       => $header->name(),
                'data'       => $header->data(),
                'components' => $header->components(),
            ],
            'content' => [
                'name'       => $content->name(),
                'data'       => $content->data(),
                'components' => $content->components(),
            ],
        ],
    ];

    /* ---- Phase 2: Rendern ---------------------------------------- */
    // Dev-Modus explizit, weil der birthdays-Slot noch unverdrahtet ist.
    // Produktiv: devMode weglassen — Log + Degradation.
    $assembler = new \Xal\Assembler(__DIR__, 'template/tsv', devMode: true);
    echo $assembler->assemble($composition);

} catch (Throwable $e) {
    // Totalausfall (z. B. Page-Vorlage fehlt): loggen, Notseite.
    \Xal\Log::exception('bootstrap', $e);
    http_response_code(500);
    echo '<!doctype html><html lang="de"><meta charset="utf-8">'
        . '<title>SV Riesa</title><body style="font-family:system-ui;'
        . 'max-width:36rem;margin:4rem auto;padding:0 1rem">'
        . '<h1>Kurze Pause</h1><p>Die Seite steht gerade leider nicht zur '
        . 'Verfügung. Wir arbeiten daran — bitte versuche es gleich noch einmal.</p>'
        . '</body></html>';
}
