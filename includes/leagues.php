<?php
/** Leagues grouped by country for the slip builder drop-down (and the app). */
function slip_leagues(): array
{
    return [
        'International' => ['FIFA World Cup', 'UEFA Champions League', 'UEFA Europa League', 'UEFA Conference League', 'UEFA Nations League', 'UEFA European Championship', 'Copa Libertadores', 'Copa America', 'Africa Cup of Nations', 'CAF Champions League', 'CAF Confederation Cup', 'AFC Champions League', 'CONCACAF Champions Cup', 'International Friendly', 'World Cup Qualifiers'],
        'England' => ['Premier League', 'Championship', 'League One', 'League Two', 'FA Cup', 'EFL Cup', 'Community Shield'],
        'Spain' => ['La Liga', 'La Liga 2', 'Copa del Rey', 'Supercopa de Espana'],
        'Italy' => ['Serie A', 'Serie B', 'Coppa Italia', 'Supercoppa Italiana'],
        'Germany' => ['Bundesliga', '2. Bundesliga', 'DFB-Pokal', '3. Liga'],
        'France' => ['Ligue 1', 'Ligue 2', 'Coupe de France'],
        'Netherlands' => ['Eredivisie', 'Eerste Divisie', 'KNVB Beker'],
        'Portugal' => ['Primeira Liga', 'Liga Portugal 2', 'Taca de Portugal'],
        'Scotland' => ['Scottish Premiership', 'Scottish Championship', 'Scottish Cup'],
        'Belgium' => ['Belgian Pro League', 'Belgian Cup'],
        'Turkey' => ['Super Lig', 'Turkish Cup'],
        'Greece' => ['Super League Greece'],
        'Russia' => ['Russian Premier League'],
        'Ukraine' => ['Ukrainian Premier League'],
        'Switzerland' => ['Swiss Super League'],
        'Austria' => ['Austrian Bundesliga'],
        'Denmark' => ['Danish Superliga'],
        'Sweden' => ['Allsvenskan'],
        'Norway' => ['Eliteserien'],
        'Poland' => ['Ekstraklasa'],
        'Czech Republic' => ['Czech First League'],
        'Kenya' => ['Kenyan Premier League', 'FKF Cup', 'National Super League'],
        'Uganda' => ['Uganda Premier League'],
        'Tanzania' => ['NBC Premier League', 'Azam Sports Federation Cup'],
        'Rwanda' => ['Rwanda Premier League'],
        'Ethiopia' => ['Ethiopian Premier League'],
        'Nigeria' => ['NPFL (Nigeria Premier Football League)'],
        'Ghana' => ['Ghana Premier League'],
        'South Africa' => ['South African Premiership (Betway)', 'Nedbank Cup', 'MTN 8'],
        'Egypt' => ['Egyptian Premier League', 'Egypt Cup'],
        'Morocco' => ['Botola Pro'],
        'Algeria' => ['Algerian Ligue 1'],
        'Tunisia' => ['Tunisian Ligue 1'],
        'Zambia' => ['Zambia Super League'],
        'Zimbabwe' => ['Zimbabwe Premier Soccer League'],
        'Cameroon' => ['Elite One'],
        'Ivory Coast' => ['Ligue 1 Ivory Coast'],
        'Senegal' => ['Senegal Premier League'],
        'DR Congo' => ['Linafoot'],
        'Saudi Arabia' => ['Saudi Pro League', 'King Cup'],
        'UAE' => ['UAE Pro League'],
        'Qatar' => ['Qatar Stars League'],
        'Iran' => ['Persian Gulf Pro League'],
        'Japan' => ['J1 League', 'J2 League'],
        'South Korea' => ['K League 1'],
        'China' => ['Chinese Super League'],
        'India' => ['Indian Super League'],
        'Australia' => ['A-League Men'],
        'USA' => ['MLS', 'US Open Cup', 'USL Championship'],
        'Mexico' => ['Liga MX'],
        'Brazil' => ['Brasileirao Serie A', 'Brasileirao Serie B', 'Copa do Brasil'],
        'Argentina' => ['Argentine Primera Division', 'Copa Argentina'],
        'Colombia' => ['Categoria Primera A'],
        'Chile' => ['Chilean Primera Division'],
        'Uruguay' => ['Uruguayan Primera Division'],
        'Other sports' => ['NBA', 'EuroLeague Basketball', 'NFL', 'NHL', 'MLB', 'ATP Tour', 'WTA Tour', 'Cricket', 'Rugby', 'Formula 1', 'UFC', 'Boxing'],
    ];
}

/** <select> with optgroups. $sel is the selected league (an unknown value is kept as its own option). */
function slip_league_select(string $name, string $sel = '', string $cls = ''): string
{
    $o = '<option value="">Choose league (optional)</option>';
    $found = false;
    foreach (slip_leagues() as $country => $list) {
        $o .= '<optgroup label="' . htmlspecialchars($country, ENT_QUOTES) . '">';
        foreach ($list as $l) {
            $isSel = $l === $sel; $found = $found || $isSel;
            $o .= '<option value="' . htmlspecialchars($l, ENT_QUOTES) . '"' . ($isSel ? ' selected' : '') . '>' . htmlspecialchars($l) . '</option>';
        }
        $o .= '</optgroup>';
    }
    if ($sel !== '' && !$found) { $o = str_replace('<option value="">', '<option value="' . htmlspecialchars($sel, ENT_QUOTES) . '" selected>' . htmlspecialchars($sel) . '</option><option value="">', $o); }
    return '<select name="' . $name . '" class="' . $cls . '" aria-label="League">' . $o . '</select>';
}

function slip_markets(): array
{
    return ['Match Result (1X2)', 'Double Chance', 'Draw No Bet', 'Over/Under Goals', 'Both Teams To Score', 'Asian Handicap', 'Correct Score', 'First Half Result', 'Winner', 'Other'];
}

/** Flat list for the app: [{name, country}, ...]. */
function slip_leagues_flat(): array
{
    $o = [];
    foreach (slip_leagues() as $country => $list) { foreach ($list as $l) { $o[] = ['name' => $l, 'country' => $country]; } }
    return $o;
}
