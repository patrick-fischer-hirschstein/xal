<?php

declare(strict_types=1);

namespace Xal;

/**
 * Resolver — Pfadauflösung nach ADR 0001.
 *
 * Binäre Grammatik:
 *   - blanker Name        → kontextrelativ  ("name", "verein/name")
 *   - führendes "/"       → wurzelrelativ   ("/seitentitel")
 *   - kein ".", kein ".." → existiert nicht, wird nicht geparst
 *
 * Fehlender Pfad / Nicht-Array unterwegs → null.
 * Zustandslos: reine Funktion in Klassenform.
 */
final class Resolver
{
    public static function resolve(string $path, array $context, array $root): mixed
    {
        if ($path === '' || $path === '/') {
            return null;
        }

        if ($path[0] === '/') {
            $base = $root;
            $path = substr($path, 1);
        } else {
            $base = $context;
        }

        $value = $base;
        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                return null; // "a//b" oder Trailing Slash: ungültig
            }
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
