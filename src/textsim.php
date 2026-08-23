<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function normalize_text(string $text): string
{
    if (function_exists('normalizer_normalize')) {
        $text = normalizer_normalize($text, Normalizer::FORM_C);
    }
    $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{2060}]/u', '', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function to_codepoints(string $s): array
{
    if ($s === '') {
        return [];
    }
    $parts = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
    return $parts === false ? str_split($s) : $parts;
}

function levenshtein_utf8(string $a, string $b, int $max = PHP_INT_MAX): int
{
    if ($a === $b) {
        return 0;
    }
    $a = to_codepoints($a);
    $b = to_codepoints($b);
    $m = count($a);
    $n = count($b);
    if (abs($m - $n) > $max) {
        return $max + 1;
    }
    if ($m === 0) {
        return $n;
    }
    if ($n === 0) {
        return $m;
    }

    $prev = range(0, $n);
    $curr = array_fill(0, $n + 1, 0);

    for ($i = 1; $i <= $m; $i++) {
        $curr[0] = $i;
        $rowMin = $i;
        $ca = $a[$i - 1];
        for ($j = 1; $j <= $n; $j++) {
            $cost = ($ca === $b[$j - 1]) ? 0 : 1;
            $curr[$j] = min($prev[$j] + 1, $curr[$j - 1] + 1, $prev[$j - 1] + $cost);
            if ($curr[$j] < $rowMin) {
                $rowMin = $curr[$j];
            }
        }
        if ($rowMin > $max) {
            return $max + 1;
        }
        [$prev, $curr] = [$curr, $prev];
    }
    return $prev[$n];
}

function tokenize_text(string $text): array
{
    $parts = preg_split("/[^\\p{L}\\p{N}']+/u", mb_strtolower($text)) ?: [];
    $counts = [];
    foreach ($parts as $tok) {
        if ($tok !== '') {
            $counts[$tok] = ($counts[$tok] ?? 0) + 1;
        }
    }
    return $counts;
}

function detect_mutation(string $parent, string $child): array
{
    $p = normalize_text($parent);
    $c = normalize_text($child);

    if ($p === '' || $c === '') {
        return ['isMutation' => false, 'similarity' => 0.0, 'reason' => 'empty'];
    }
    if ($p === $c) {
        return ['isMutation' => false, 'similarity' => 1.0, 'reason' => 'identical'];
    }
    if (min(mb_strlen($p), mb_strlen($c)) < cfg('min_text_length')) {
        return ['isMutation' => false, 'similarity' => 0.0, 'reason' => 'too-short'];
    }

    $pt = tokenize_text($p);
    $ct = tokenize_text($c);
    $shared = 0;
    $childTokens = array_sum($ct);
    foreach ($ct as $tok => $count) {
        $shared += min($count, $pt[$tok] ?? 0);
    }
    $coverage = $childTokens > 0 ? $shared / $childTokens : 0.0;

    if ($shared >= cfg('token_shared_min') && $coverage >= cfg('token_coverage_min')) {
        return ['isMutation' => true, 'similarity' => $coverage, 'reason' => 'mutated'];
    }

    $maxLen = max(mb_strlen($p), mb_strlen($c));
    $bestPossible = min(mb_strlen($p), mb_strlen($c)) / $maxLen;
    if ($bestPossible >= cfg('sim_threshold')) {
        $maxDist = (int) floor((1.0 - cfg('sim_threshold')) * $maxLen);
        $dist = levenshtein_utf8($p, $c, max($maxDist, 0));
        if ($dist <= $maxDist) {
            return ['isMutation' => true, 'similarity' => 1.0 - $dist / $maxLen, 'reason' => 'mutated'];
        }
        return ['isMutation' => false, 'similarity' => 1.0 - $dist / $maxLen, 'reason' => 'too-different'];
    }

    return [
        'isMutation' => false,
        'similarity' => $coverage,
        'reason' => $coverage < cfg('token_coverage_min') ? 'low-coverage' : 'too-different',
    ];
}
