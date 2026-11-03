<?php
/**
 * Friendlier team search: "Man Utd", "Spurs", "Barca", "Juve", "Gor" and accents or "FC" differences all find the right team.
 * search_terms() returns the search words to try (the original first), each used as LIKE %term%.
 */
function search_alias_map(): array
{
    return [
        'man utd' => 'Manchester United', 'man united' => 'Manchester United', 'mufc' => 'Manchester United', 'red devils' => 'Manchester United',
        'man city' => 'Manchester City', 'mcfc' => 'Manchester City', 'city' => 'Manchester City',
        'spurs' => 'Tottenham', 'thfc' => 'Tottenham', 'wolves' => 'Wolverhampton', 'villa' => 'Aston Villa',
        'gunners' => 'Arsenal', 'reds' => 'Liverpool', 'lfc' => 'Liverpool', 'blues' => 'Chelsea', 'cfc' => 'Chelsea',
        'newcastle utd' => 'Newcastle', 'toon' => 'Newcastle', 'west ham utd' => 'West Ham', 'forest' => 'Nottingham', 'nottm forest' => 'Nottingham',
        'barca' => 'Barcelona', 'fcb' => 'Barcelona', 'atletico' => 'Atl', 'atleti' => 'Atl', 'real' => 'Real Madrid', 'los blancos' => 'Real Madrid',
        'juve' => 'Juventus', 'inter milan' => 'Inter', 'ac milan' => 'Milan', 'napoli' => 'Napoli', 'roma' => 'Roma',
        'bayern' => 'Bayern', 'bvb' => 'Dortmund', 'psg' => 'Paris', 'paris sg' => 'Paris', 'ajax' => 'Ajax',
        'gor' => 'Gor Mahia', 'kogalo' => 'Gor Mahia', "k'ogalo" => 'Gor Mahia', 'afc' => 'AFC Leopards', 'ingwe' => 'AFC Leopards', 'leopards' => 'AFC Leopards',
        'kcb' => 'KCB', 'tusker' => 'Tusker', 'sofapaka' => 'Sofapaka', 'bandari' => 'Bandari', 'ulinzi' => 'Ulinzi', 'kakamega' => 'Kakamega',
        'harambee stars' => 'Kenya', 'stars' => 'Kenya', 'super eagles' => 'Nigeria', 'bafana' => 'South Africa', 'pharaohs' => 'Egypt',
    ];
}

function search_terms(string $q): array
{
    $q = trim($q);
    $terms = [$q];
    $low = mb_strtolower($q);
    $norm = $low;
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $low);
        if (is_string($t) && $t !== '') { $norm = preg_replace('/[^a-z0-9\' ]+/', '', $t); }
    }
    $norm = trim(preg_replace('/\s+/', ' ', (string)$norm));
    $map = search_alias_map();
    if (isset($map[$low])) { $terms[] = $map[$low]; }
    if ($norm !== '' && isset($map[$norm])) { $terms[] = $map[$norm]; }
    $plain = trim(preg_replace('/\b(fc|afc|cf|sc|fk)\b/', '', str_replace(['utd', '.'], ['united', ''], $norm)));
    if ($plain !== '' && $plain !== $low && mb_strlen($plain) >= 2) { $terms[] = $plain; }
    if ($norm !== '' && $norm !== $low && mb_strlen($norm) >= 2) { $terms[] = $norm; }
    return array_slice(array_values(array_unique($terms)), 0, 4);
}

/** "(col LIKE ? OR col LIKE ?)" over the terms for one or more columns; the values are appended to $args. */
function search_like_sql(array $cols, array $terms, array &$args): string
{
    $parts = [];
    foreach ($terms as $t) {
        $like = '%' . addcslashes($t, '%_\\') . '%';
        foreach ($cols as $c) { $parts[] = $c . ' LIKE ?'; $args[] = $like; }
    }
    return '(' . implode(' OR ', $parts) . ')';
}
