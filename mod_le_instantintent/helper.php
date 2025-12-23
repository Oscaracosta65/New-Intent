<?php
/**
 * mod_le_instantintent helper
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\ParameterType;

final class ModLeInstantintentHelper
{
    /**
     * com_ajax entrypoint:
     * /index.php?option=com_ajax&module=le_instantintent&method=getPicks&format=json
     */
    public static function getAjax(): array
    {
        $app   = Factory::getApplication();
        $input = $app->input;

        // Module id required to load params safely
        $moduleId = $input->getInt('module_id', 0);
        if ($moduleId <= 0) {
            return ['ok' => false, 'error' => 'Missing module_id.'];
        }

        $method = (string) $input->getCmd('pick_method', 'random');
        $lines  = (int) $input->getInt('lines', 5);

        $module = self::loadModuleById($moduleId);
        if (!$module) {
            return ['ok' => false, 'error' => 'Module not found.'];
        }

        $params = new \Joomla\Registry\Registry($module->params);

        $payload = self::buildPicksPayload($params, $method, $lines);
        $payload['ok'] = true;

        return $payload;
    }

    public static function buildPicksPayload(\Joomla\Registry\Registry $params, string $method, int $lines): array
    {
        $method = in_array($method, ['random', 'hotcold', 'skiphit'], true) ? $method : 'random';

        $minLines = (int) $params->get('min_lines', 3);
        $maxLines = (int) $params->get('max_lines', 10);
        $lines    = max($minLines, min($maxLines, $lines));

        $gameKey  = (string) $params->get('game_key', 'game');
        $tz       = (string) $params->get('timezone', 'America/New_York');

        // Game rules
        $mainCount = (int) $params->get('main_count', 5);
        $mainMin   = (int) $params->get('main_min', 1);
        $mainMax   = (int) $params->get('main_max', 69);

        $extraEnabled = (int) $params->get('extra_enabled', 1) === 1;
        $extraCount   = (int) $params->get('extra_count', 1);
        $extraMin     = (int) $params->get('extra_min', 1);
        $extraMax     = (int) $params->get('extra_max', 26);
        $extraLabel   = (string) $params->get('extra_label', 'X');

        // If not a daily digit game, avoid 0 unless explicitly allowed by range
        $excludeZero = !($mainMin === 0 && $mainMax <= 9);

        $sourceMeta = [
            'method' => $method,
            'lines'  => $lines,
            'tz'     => $tz,
            'gameKey'=> $gameKey,
        ];

        // Pools per method
        $pool = null;

        if ($method === 'random') {
            $pool = null; // random draws directly
        } else {
            // For hotcold/skiphit we need history
            $history = self::fetchHistory($params, $mainMin, $mainMax, $excludeZero);
            if ($history['ok'] !== true) {
                // fallback to random if history not available
                $sourceMeta['fallback'] = 'random (history unavailable)';
                $method = 'random';
            } else {
                if ($method === 'hotcold') {
                    $pool = self::buildHotColdPool($history, $mainMin, $mainMax, $excludeZero);
                } else {
                    $pool = self::buildSkipHitPool($history, $mainMin, $mainMax, $excludeZero);
                }
            }
        }

        $picks = [];
        for ($i = 0; $i < $lines; $i++) {
            $main = ($method === 'random')
                ? self::randomUnique($mainCount, $mainMin, $mainMax, $excludeZero)
                : self::sampleFromPoolUnique($mainCount, $pool['main'], $mainMin, $mainMax, $excludeZero);

            sort($main, SORT_NUMERIC);

            $extra = [];
            if ($extraEnabled && $extraCount > 0) {
                // For extra we keep it random by default. (You can extend to history-based extra too.)
                $extra = self::randomUnique($extraCount, $extraMin, $extraMax, $excludeZero = false);
                sort($extra, SORT_NUMERIC);
            }

            $picks[] = [
                'main'  => $main,
                'extra' => $extra,
            ];
        }

        return [
            'ok' => true,
            'meta' => $sourceMeta,
            'rules' => [
                'mainCount' => $mainCount,
                'mainMin'   => $mainMin,
                'mainMax'   => $mainMax,
                'extraEnabled' => $extraEnabled,
                'extraCount'   => $extraCount,
                'extraMin'     => $extraMin,
                'extraMax'     => $extraMax,
                'extraLabel'   => $extraLabel,
            ],
            'picks' => $picks,
            'now' => gmdate('c'),
            'ajaxUrl' => Uri::base() . 'index.php?option=com_ajax&module=le_instantintent&method=getPicks&format=json',
        ];
    }

    /**
     * Pull draw history from your configured table and columns.
     * This is the “shared database” assumption you mentioned.
     */
    private static function fetchHistory(\Joomla\Registry\Registry $params, int $mainMin, int $mainMax, bool $excludeZero): array
    {
        $db = Factory::getDbo();

        $table = trim((string) $params->get('db_table', ''));
        $gameId = trim((string) $params->get('db_game_id', ''));
        $dateCol = trim((string) $params->get('draw_date_col', 'draw_date'));
        $mainCsv = trim((string) $params->get('main_cols_csv', ''));
        $extraCol = trim((string) $params->get('extra_col', ''));
        $limit = (int) $params->get('history_limit', 400);

        if ($table === '' || $gameId === '' || $mainCsv === '') {
            return ['ok' => false, 'error' => 'Missing db_table/db_game_id/main_cols_csv params.'];
        }

        $mainCols = array_filter(array_map('trim', explode(',', $mainCsv)));

        // Select: draw_date + main cols + optional extra
        $select = [$db->quoteName($dateCol)];
        foreach ($mainCols as $c) {
            $select[] = $db->quoteName($c);
        }
        if ($extraCol !== '') {
            $select[] = $db->quoteName($extraCol);
        }

        $q = $db->getQuery(true)
            ->select($select)
            ->from($db->quoteName($table))
            ->where($db->quoteName('game_id') . ' = :gid')
            ->bind(':gid', $gameId, ParameterType::STRING)
            ->order($db->quoteName($dateCol) . ' DESC')
            ->setLimit(max(50, min(5000, $limit)));

        try {
            $db->setQuery($q);
            $rows = $db->loadAssocList();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'DB error: ' . $e->getMessage()];
        }

        if (!$rows) {
            return ['ok' => false, 'error' => 'No draw rows returned.'];
        }

        // Normalize into chronological list of draws (old -> new)
        $draws = [];
        foreach (array_reverse($rows) as $r) {
            $nums = [];
            foreach ($mainCols as $c) {
                if (!array_key_exists($c, $r)) {
                    continue;
                }
                $v = $r[$c];

                // Supports "1,2,3" or "01" tokens
                $tokens = (strpos((string)$v, ',') !== false)
                    ? array_map('trim', explode(',', (string)$v))
                    : [trim((string)$v)];

                foreach ($tokens as $t) {
                    if ($t === '') { continue; }
                    $n = ($t === '0') ? 0 : (int) ltrim($t, '0');
                    if ($excludeZero && $n === 0) { continue; }
                    if ($n < $mainMin || $n > $mainMax) { continue; }
                    $nums[] = $n;
                }
            }

            $nums = array_values(array_unique($nums));
            sort($nums, SORT_NUMERIC);

            if (!$nums) { continue; }

            $draws[] = [
                'date' => (string) $r[$dateCol],
                'numbers' => $nums,
            ];
        }

        if (count($draws) < 20) {
            return ['ok' => false, 'error' => 'Not enough draws after normalization.' ];
        }

        return [
            'ok' => true,
            'draws' => $draws,
        ];
    }

    /**
     * HOT/COLD:
     * - Hot: highest frequency
     * - Cold: lowest frequency among numbers that appeared at least once
     * Pool = hot slice + cold slice (deduped), so picks feel “intentful”.
     */
    private static function buildHotColdPool(array $history, int $min, int $max, bool $excludeZero): array
    {
        $freq = [];
        foreach ($history['draws'] as $d) {
            foreach ($d['numbers'] as $n) {
                $freq[$n] = ($freq[$n] ?? 0) + 1;
            }
        }

        // Ensure all numbers exist in map (so “cold” works)
        for ($n = $min; $n <= $max; $n++) {
            if ($excludeZero && $n === 0) { continue; }
            $freq[$n] = $freq[$n] ?? 0;
        }

        // Hot desc, then n asc
        $hot = $freq;
        uksort($hot, function($a, $b) use ($hot) {
            $da = $hot[$a]; $db = $hot[$b];
            if ($da === $db) { return (int)$a <=> (int)$b; }
            return $db <=> $da;
        });

        // Cold asc, then n asc
        $cold = $freq;
        uksort($cold, function($a, $b) use ($cold) {
            $da = $cold[$a]; $db = $cold[$b];
            if ($da === $db) { return (int)$a <=> (int)$b; }
            return $da <=> $db;
        });

        $hotList  = array_slice(array_keys($hot), 0, 20);
        $coldList = array_slice(array_keys($cold), 0, 20);

        $pool = array_values(array_unique(array_merge($hotList, $coldList)));
        // Keep hot numbers earlier in the pool
        $pool = array_values(array_unique(array_merge($hotList, $pool)));

        return [
            'main' => $pool,
            'meta' => [
                'hotTop' => array_slice($hotList, 0, 10),
                'coldTop'=> array_slice($coldList, 0, 10),
            ],
        ];
    }

    /**
     * SKIP–HIT (heuristic):
     * Computes, from history:
     * - frequency (how often drawn)
     * - current skip (draws since last seen)
     * - “hit-after-skip” empirical probability: P(hit | currentSkip)
     * Score = 0.35*freq + 0.25*(1/(skip+1)) + 0.40*P(hit|skip)
     */
    private static function buildSkipHitPool(array $history, int $min, int $max, bool $excludeZero): array
    {
        $draws = $history['draws'];
        $T = count($draws);

        $prevIndex = [];
        $drawCounts = [];
        $skipHist = []; // [n][skip] = count

        foreach ($draws as $i => $d) {
            foreach ($d['numbers'] as $n) {
                $drawCounts[$n] = ($drawCounts[$n] ?? 0) + 1;

                if (isset($prevIndex[$n])) {
                    $skip = $i - $prevIndex[$n] - 1;
                    $skipHist[$n][$skip] = ($skipHist[$n][$skip] ?? 0) + 1;
                } else {
                    // first time observed
                    $skipHist[$n][0] = ($skipHist[$n][0] ?? 0) + 1;
                }

                $prevIndex[$n] = $i;
            }
        }

        // Fill missing numbers
        for ($n = $min; $n <= $max; $n++) {
            if ($excludeZero && $n === 0) { continue; }
            $drawCounts[$n] = $drawCounts[$n] ?? 0;
            $prevIndex[$n]  = $prevIndex[$n] ?? -1;
            $skipHist[$n]   = $skipHist[$n] ?? [0 => 1];
        }

        // Empirical P(hit | skip)
        $pHitGivenSkip = [];
        foreach ($skipHist as $n => $bins) {
            $total = array_sum($bins) ?: 1;
            foreach ($bins as $s => $c) {
                $pHitGivenSkip[(int)$n][(int)$s] = $c / $total;
            }
        }

        // Current skip
        $currSkip = [];
        foreach ($prevIndex as $n => $i) {
            $currSkip[(int)$n] = ($i >= 0) ? ($T - $i - 1) : $T;
        }

        // Total events ~ draws * ballsPerDraw, but we can normalize by max count observed.
        $maxCount = max(1, (int) max($drawCounts));

        $scores = [];
        foreach ($drawCounts as $n => $count) {
            $n = (int) $n;
            if ($excludeZero && $n === 0) { continue; }

            $freqScore = $count / $maxCount;

            $s = $currSkip[$n] ?? 0;
            if ($s < 0) { $s = 0; }
            $skipScore = 1 / ($s + 1);

            $histScore = $pHitGivenSkip[$n][$s] ?? 0.0;

            $scores[$n] = (0.35 * $freqScore) + (0.25 * $skipScore) + (0.40 * $histScore);
        }

        // Sort: score desc, then number asc
        uksort($scores, function($a, $b) use ($scores) {
            $da = $scores[$a]; $db = $scores[$b];
            if (abs($da - $db) < 1e-12) { return (int)$a <=> (int)$b; }
            return ($db <=> $da);
        });

        $ranked = array_keys($scores);
        $pool = array_slice($ranked, 0, 28); // bigger pool makes lines less repetitive

        return [
            'main' => $pool,
            'meta' => [
                'topRanked' => array_slice($ranked, 0, 20),
            ],
        ];
    }

    private static function randomUnique(int $count, int $min, int $max, bool $excludeZero): array
    {
        // Calculate available pool size
        $poolSize = $max - $min + 1;
        if ($excludeZero && $min <= 0 && $max >= 0) {
            $poolSize--;
        }
        
        // Cannot generate more unique numbers than available in pool
        $count = min($count, $poolSize);
        
        // Early return if count is invalid
        if ($count <= 0 || $poolSize <= 0) {
            return [];
        }
        
        $out = [];
        $guard = 0;
        $maxAttempts = min(20000, $poolSize * 10); // Reasonable attempts based on pool size
        
        while (count($out) < $count && $guard < $maxAttempts) {
            $guard++;
            $n = random_int($min, $max);
            if ($excludeZero && $n === 0) { continue; }
            $out[$n] = true;
        }
        return array_map('intval', array_keys($out));
    }

    private static function sampleFromPoolUnique(int $count, array $pool, int $min, int $max, bool $excludeZero): array
    {
        // Calculate available pool size in the full range
        $rangePoolSize = $max - $min + 1;
        if ($excludeZero && $min <= 0 && $max >= 0) {
            $rangePoolSize--;
        }
        
        // Cannot generate more unique numbers than available
        $count = min($count, $rangePoolSize);
        
        // Early return if count is invalid
        if ($count <= 0 || $rangePoolSize <= 0) {
            return [];
        }
        
        $out = [];
        $pool = array_values(array_unique(array_map('intval', $pool)));
        
        // Filter pool to only include valid numbers
        $pool = array_filter($pool, function($n) use ($min, $max, $excludeZero) {
            if ($n < $min || $n > $max) { return false; }
            if ($excludeZero && $n === 0) { return false; }
            return true;
        });
        $pool = array_values($pool);

        // Reasonable attempt limit based on what we need
        $maxAttempts = min(3000, $count * 50);
        $attempts = 0;
        
        while (count($out) < $count && $attempts < $maxAttempts) {
            $attempts++;

            $poolSize = count($pool);
            if ($poolSize > 0) {
                $idx = random_int(0, $poolSize - 1);
                $n = (int) $pool[$idx];
            } else {
                $n = random_int($min, $max);
            }

            if ($excludeZero && $n === 0) { continue; }
            if ($n < $min || $n > $max) { continue; }
            $out[$n] = true;
        }

        // Backfill with random if needed
        if (count($out) < $count) {
            foreach (self::randomUnique($count - count($out), $min, $max, $excludeZero) as $n) {
                $out[$n] = true;
            }
        }

        return array_map('intval', array_keys($out));
    }

    private static function loadModuleById(int $moduleId): ?object
    {
        $db = Factory::getDbo();
        $q = $db->getQuery(true)
            ->select(['id', 'module', 'params'])
            ->from($db->quoteName('#__modules'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $moduleId, ParameterType::INTEGER)
            ->setLimit(1);

        $db->setQuery($q);
        $row = $db->loadObject();

        if (!$row || (string)$row->module !== 'mod_le_instantintent') {
            return null;
        }

        return $row;
    }
}
