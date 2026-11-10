<?php

declare(strict_types=1);

namespace tsv\Region;

/**
 * Header — Site-Kopf (Vereinsname + Hauptmenü).
 *
 * Erste Nicht-Content-Region: validiert den schmalen
 * Regionsvertrag. Kein metadata() — die Methode existiert
 * auf dieser Ebene nicht (Typgrenze, ADR 0003).
 *
 * Das Hauptmenü ist genuiner Region-Inhalt, keine Component
 * (fällt durch den Component-Test aus ADR 0002): Die Bindung
 * liegt direkt im Region-Template, components() bleibt leer.
 *
 * Der aktive Menüpunkt wird in der Aufbereitung aus dem
 * spa-Seitentyp markiert — das Template kennt nur die
 * fertige Renderform ('aktiv' als Klassen-Token oder null).
 */
final class Header extends \tsv\Region
{
    private array $navigation;

    public function __construct(array $spa)
    {
        parent::__construct($spa);
        $this->navigation = $this->buildNavigation($spa['seitentyp'] ?? '');
    }

    public function name(): string
    {
        return 'header';
    }

    public function data(): array
    {
        return [
            'verein'     => 'SV Riesa',
            'navigation' => $this->navigation,
        ];
    }

    public function components(): array
    {
        return []; // Region ohne Components — der Vertrag trägt auch das.
    }

    /**
     * Menüstruktur. Hier als Stub hartkodiert — produktiv käme sie
     * per $this->db() aus der Datenbank. Das ist kein Sonderstatus
     * dieser Region: db() steht jeder tsv-Region offen.
     */
    private function buildNavigation(string $pageType): array
    {
        $items = [
            ['url' => '/',              'text' => 'Start',         'typ' => 'start'],
            ['url' => '/spieltag',      'text' => 'Spieltag',      'typ' => 'spieltag'],
            ['url' => '/spielberichte', 'text' => 'Spielberichte', 'typ' => 'spielberichte'],
            ['url' => '/verein',        'text' => 'Verein',        'typ' => 'verein'],
        ];
        foreach ($items as &$item) {
            // Renderform: xal:class-taugliches Token oder null (→ Omit).
            $item['aktiv'] = $item['typ'] === $pageType ? 'aktiv' : null;
            unset($item['typ']); // interner Schlüssel, nicht Teil des Vertrags
        }
        return $items;
    }
}
