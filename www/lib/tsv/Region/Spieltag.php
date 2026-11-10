<?php

declare(strict_types=1);

namespace tsv\Region;

/**
 * Spieltag — Content-Region des Seitentyps "spieltag".
 *
 * Die Datenaufbereitung (produktiv: SQL gegen MariaDB-Views)
 * läuft vollständig im Konstruktor; metadata() und components()
 * lesen aus demselben abgelegten Ergebnis — ein Datenweg,
 * keine Doppelabfragen.
 */
final class Spieltag extends \tsv\ContentRegion
{
    private array $league;
    private array $cup;

    public function __construct(array $spa)
    {
        parent::__construct($spa);
        // Aufbereitung JETZT — danach ist die Region fertig befragt.
        $this->league = $this->loadTable((int) $spa['liga_id']);
        $this->cup    = $this->loadTable((int) $spa['pokal_id']);
    }

    public function name(): string
    {
        return 'spieltag';
    }

    public function components(): array
    {
        return [
            ['name' => 'tabelle', 'data' => $this->league],
            ['name' => 'tabelle', 'data' => $this->cup],
            // dritter Slot (birthdays): bewusst noch unverdrahtet
        ];
    }

    public function metadata(): array
    {
        // Datenabhängig aus derselben Aufbereitung wie der Content.
        return [
            'titel'        => 'Spieltag — ' . $this->league['titel'] . ' & ' . $this->cup['titel'],
            'beschreibung' => 'Aktuelle Tabellen: ' . $this->league['untertitel']
                            . ' und ' . $this->cup['untertitel'] . '.',
        ];
    }

    /** Produktiv: SQL. Hier: statische Demodaten (Vertrag der Component "tabelle"). */
    private function loadTable(int $competitionId): array
    {
        $demo = [
            7 => [
                'titel'      => 'Tabelle',
                'untertitel' => 'Kreisoberliga · Saison 2026/27',
                'zeilen'     => [
                    ['platz' => 1, 'url' => '/verein/sv-riesa',     'mannschaft' => 'SV Riesa',
                     'spiele' => 4, 'differenz' => '+7',  'punkte' => 12, 'zone' => 'zone-auf'],
                    ['platz' => 2, 'url' => '/verein/tsv-groeditz', 'mannschaft' => 'TSV Gröditz',
                     'spiele' => 4, 'differenz' => '+4',  'punkte' => 9,  'zone' => null],
                    ['platz' => 3, 'url' => '/verein/sc-nuenchritz','mannschaft' => 'SC Nünchritz',
                     'spiele' => 4, 'differenz' => '−1',  'punkte' => 6,  'zone' => null],
                    ['platz' => 4, 'url' => '/verein/bsg-stahl',    'mannschaft' => 'BSG Stahl Nord',
                     'spiele' => 4, 'differenz' => '−10', 'punkte' => 1,  'zone' => 'zone-ab'],
                ],
                'hinweis'    => 'Wertung des Spiels gegen Gröditz unter Vorbehalt.',
            ],
            12 => [
                'titel'      => 'Pokal',
                'untertitel' => 'Kreispokal · Gruppe B',
                'zeilen'     => [
                    ['platz' => 1, 'url' => '/verein/sv-riesa',     'mannschaft' => 'SV Riesa',
                     'spiele' => 2, 'differenz' => '+5', 'punkte' => 6, 'zone' => 'zone-auf'],
                    ['platz' => 2, 'url' => '/verein/sc-nuenchritz','mannschaft' => 'SC Nünchritz',
                     'spiele' => 2, 'differenz' => '−5', 'punkte' => 0, 'zone' => null],
                ],
                // kein 'hinweis' → xal:text-Auto-Omit entfernt den Absatz
            ],
        ];
        return $demo[$competitionId]
            ?? ['titel' => 'Unbekannter Wettbewerb', 'untertitel' => '—', 'zeilen' => []];
    }
}
