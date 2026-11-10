<?php

declare(strict_types=1);

namespace Xal;

/**
 * Region — struktureller Vertrag aller Regionen (ADR 0003).
 *
 * Der Konstruktor nimmt das spa entgegen (immer als Kopie,
 * nie als Referenz — ADR 0002) und INITIIERT die vollständige
 * Datenaufbereitung: Nach dem Konstruktor ist die Region fertig
 * befragt. name()/data()/components() sind danach reine Getter
 * auf abgelegte Ergebnisse — ohne Seiteneffekte, beliebig oft
 * aufrufbar (harte Wand: alle Daten vor dem Rendern).
 *
 * Diese Basis hat bewusst KEIN metadata(): Regionen ohne
 * Seitenanspruch (Header, Footer) KÖNNEN keine Metadaten
 * setzen — die Methode existiert dort nicht. Der Seitenanspruch
 * ist eine Typfrage (ContentRegion), keine Laufzeitprüfung.
 *
 * Und bewusst KEIN Datenbankzugriff: die Engine weiß nicht,
 * woher die Daten kommen. Ein Projekt legt seine eigene Basis
 * darüber (tsv\Region) und bringt db() dort mit.
 *
 * Vererbungshinweis: Dies ist eine gemeinsame Basis GLEICHRANGIGER
 * Regionen (geteilter Vertrag), nicht die in ADR 0002 verworfene
 * Composite-Vererbung (Page IST Region). Es entsteht keine Tiefe.
 */
abstract class Region
{
    public function __construct(
        protected readonly array $spa,
    ) {
    }

    /**
     * Bausteinname dieser Region — OHNE Pfad und OHNE Endung
     * ("spieltag"). Die Region kennt ihren Namen, nicht ihren
     * Ablageort: den Pfad baut der Assembler aus Stufe + Name.
     */
    abstract public function name(): string;

    /** Wurzelkontext für den Walk der Region-Datei selbst. */
    public function data(): array
    {
        return [];
    }

    /**
     * Component-Einträge in Dokumentreihenfolge der Slots:
     * [ ['name' => 'tabelle', 'data' => [...]], … ]
     */
    abstract public function components(): array;
}
