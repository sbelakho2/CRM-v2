<?php

/**
 * REVIEWED accessibility allowlist.
 *
 * Every entry is a KNOWN, accepted accessibility violation:
 *   file (relative to templates/) => list of violation substrings accepted.
 *
 * RULES:
 * - Adding an entry requires a reviewed reason in the comment column.
 * - FIX the template and REMOVE the entry — new violations always fail.
 * - Entries whose files no longer violate are dead weight; prune freely.
 */
return [
    // Example shape:
    // 'lead/show.html.twig' => ['onclick="doThing()', 'reason: legacy panel, fix scheduled'],
];
