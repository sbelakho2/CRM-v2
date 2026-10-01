<?php

/**
 * CI gate: translation completeness across ALL served locales.
 *
 * SYSTEM-AWARE CONTRACT: this CRM operates in Morocco/Tunisia — English is
 * the development locale but FRENCH and ARABIC are the languages users
 * actually read. A key present in messages.en.json but missing from
 * messages.fr.json or messages.ar.json renders the raw key to a real user
 * (or an English fallback silently swapped for their language). The
 * round-8 quote-request queue shipped exactly this way: 18 keys missing
 * from Arabic.
 *
 * Contract: the key sets of fr and ar must EQUAL the en key set — no
 * missing keys (hard failure) and no stray keys that exist only in a
 * non-English locale (hard failure: dead translations drift).
 *
 * Exit 0 = exact tri-locale parity; exit 1 = violations listed.
 */

$root = dirname(__DIR__, 2);

function collectKeys(array $data, string $prefix = ''): array
{
    $keys = [];
    foreach ($data as $key => $value) {
        $full = $prefix === '' ? $key : $prefix . '.' . $key;
        if (is_array($value)) {
            $keys += collectKeys($value, $full);
        } else {
            $keys[$full] = true;
        }
    }

    return $keys;
}

$locales = ['en', 'fr', 'ar'];
$keysets = [];
foreach ($locales as $locale) {
    $path = $root . '/translations/messages.' . $locale . '.json';
    if (!is_file($path)) {
        fwrite(STDERR, "✖ translation gate FAILED: messages.{$locale}.json is missing\n");
        exit(1);
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        fwrite(STDERR, "✖ translation gate FAILED: messages.{$locale}.json is not valid JSON\n");
        exit(1);
    }
    $keysets[$locale] = collectKeys($data);
}

$failures = [];
foreach (['fr', 'ar'] as $locale) {
    $missing = array_diff($keysets['en'], $keysets[$locale]);
    foreach ($missing as $key) {
        $failures[] = "{$locale}: MISSING key '{$key}' (present in en) — translate it, a user is reading the raw key";
    }
    $stray = array_diff($keysets[$locale], $keysets['en']);
    foreach ($stray as $key) {
        $failures[] = "{$locale}: STRAY key '{$key}' (not in en) — dead translation, remove it or add it to en";
    }
}

// Non-technical detail: count EMPTY values in fr/ar where en has content —
// a key that exists but is an empty string is a silent miss.
foreach (['fr', 'ar'] as $locale) {
    $data = json_decode((string) file_get_contents($root . '/translations/messages.' . $locale . '.json'), true);
    $flat = new RecursiveIteratorIterator(new RecursiveArrayIterator($data));
    foreach ($flat as $value) {
        if (is_string($value) && trim($value) === '') {
            $failures[] = "{$locale}: EMPTY translation string found — fill it or remove the key";
            break; // one report per locale is enough detail here
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "✖ translation-parity gate FAILED (" . count($failures) . "):\n");
    foreach (array_slice($failures, 0, 30) as $failure) {
        fwrite(STDERR, '  - ' . $failure . "\n");
    }
    if (count($failures) > 30) {
        fwrite(STDERR, '  … and ' . (count($failures) - 30) . " more\n");
    }
    exit(1);
}

echo sprintf(
    "✓ translation-parity gate passed (en=%d, fr=%d, ar=%d — exact tri-locale key parity)\n",
    count($keysets['en']),
    count($keysets['fr']),
    count($keysets['ar'])
);
