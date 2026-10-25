<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace adaptivequizcatmodel_catquiz\local\monitoring;

/**
 * Figures of a long attempt, request by request: percentiles per span, growth per item (adapter #6).
 *
 * A request is an array with item (number of the item the request administered or answered last), requestms,
 * memoryendkb, memorypeakkb, optionally qubaloadms, and - with the trace on - requestid and spans as CATquiz
 * wrote them. Growth is the slope of a least-squares line over the items: KB or ms per item.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class longrun_stats {
    /**
     * Nearest-rank p50 and p95, and the maximum.
     *
     * @param float[] $values
     * @return array n, p50, p95, max
     */
    public static function percentiles(array $values): array {
        $values = array_values(array_filter($values, 'is_numeric'));
        if (!$values) {
            return ['n' => 0, 'p50' => null, 'p95' => null, 'max' => null];
        }
        sort($values);
        $n = count($values);
        $rank = fn(float $p) => (float) $values[max(0, (int) ceil($p * $n) - 1)];
        return ['n' => $n, 'p50' => $rank(0.50), 'p95' => $rank(0.95), 'max' => (float) $values[$n - 1]];
    }

    /**
     * Slope of the least-squares line through the points.
     *
     * @param array $points [x, y] pairs.
     * @return float|null null with fewer than two distinct x.
     */
    public static function slope(array $points): ?float {
        $points = array_values(array_filter($points, fn($p) => is_numeric($p[0]) && is_numeric($p[1])));
        $n = count($points);
        if ($n < 2) {
            return null;
        }
        $meanx = array_sum(array_column($points, 0)) / $n;
        $meany = array_sum(array_column($points, 1)) / $n;
        $sxx = 0.0;
        $sxy = 0.0;
        foreach ($points as [$x, $y]) {
            $sxx += ($x - $meanx) ** 2;
            $sxy += ($x - $meanx) * ($y - $meany);
        }
        return $sxx > 0 ? round($sxy / $sxx, 4) : null;
    }

    /**
     * The figures of a run.
     *
     * @param array $requests
     * @return array
     */
    public static function summarize(array $requests): array {
        $spans = [];
        $open = [];
        $fatals = 0;
        $ids = [];
        $missing = 0;
        foreach ($requests as $request) {
            if (!empty($request['traced'])) {
                if (empty($request['requestid'])) {
                    $missing++;
                } else {
                    $ids[$request['requestid']] = true;
                }
            }
            foreach ($request['spans'] ?? [] as $span) {
                if ($span['name'] === 'request') {
                    $fatals += empty($span['meta']['fatal']) ? 0 : 1;
                    continue;
                }
                if (!empty($span['open']) || !empty($span['meta']['open'])) {
                    $open[$span['name']] = ($open[$span['name']] ?? 0) + 1;
                }
                if (isset($span['ms'])) {
                    $spans[$span['name']][] = (float) $span['ms'];
                }
            }
        }
        ksort($spans);
        ksort($open);

        $points = fn(string $key) => array_map(fn($r) => [$r['item'], $r[$key] ?? null], $requests);
        $qubaload = array_values(array_filter($requests, fn($r) => isset($r['qubaloadms'])));
        $tenth = max(1, (int) floor(count($qubaload) / 10));

        return [
            'requests' => count($requests),
            'items' => $requests ? max(array_column($requests, 'item')) : 0,
            'errors' => count(array_filter($requests, fn($r) => !empty($r['error']))),
            'requestms' => self::percentiles(array_column($requests, 'requestms')),
            'spans' => array_map([self::class, 'percentiles'], $spans),
            'memory' => [
                'endkb_per_item' => self::slope($points('memoryendkb')),
                'peakkb_per_item' => self::slope($points('memorypeakkb')),
                'endkb_first' => $requests[0]['memoryendkb'] ?? null,
                'endkb_last' => $requests ? end($requests)['memoryendkb'] : null,
                'peakkb_max' => $requests ? max(array_column($requests, 'memorypeakkb')) : null,
            ],
            'quba_load' => [
                'ms_per_item' => self::slope($points('qubaloadms')),
                'first_tenth_p50' => self::percentiles(array_column(array_slice($qubaload, 0, $tenth), 'qubaloadms'))['p50'],
                'last_tenth_p50' => self::percentiles(array_column(array_slice($qubaload, -$tenth), 'qubaloadms'))['p50'],
            ],
            'fatals' => $fatals,
            'open_spans' => $open,
            'requestids' => ['distinct' => count($ids), 'missing' => $missing, 'sample' => array_key_first($ids)],
        ];
    }

    /**
     * The figures as text, for the console.
     *
     * @param array $summary From summarize().
     * @return string
     */
    public static function text(array $summary): string {
        $f = fn($v) => $v === null ? '-' : sprintf('%.1f', $v);
        $lines = [];
        $lines[] = sprintf(
            'requests %d, items %d, errors %d, fatals %d',
            $summary['requests'],
            $summary['items'],
            $summary['errors'],
            $summary['fatals']
        );
        $r = $summary['requestms'];
        $lines[] = sprintf('request          p50 %8s ms  p95 %8s ms  max %8s ms', $f($r['p50']), $f($r['p95']), $f($r['max']));
        foreach ($summary['spans'] as $name => $p) {
            $lines[] = sprintf('%-34s n %4d  p50 %8s  p95 %8s ms', $name, $p['n'], $f($p['p50']), $f($p['p95']));
        }
        $m = $summary['memory'];
        $lines[] = sprintf(
            'memory: end %s -> %s KB (%s KB/item), peak max %s KB (%s KB/item)',
            $f($m['endkb_first']),
            $f($m['endkb_last']),
            $m['endkb_per_item'] ?? '-',
            $f($m['peakkb_max']),
            $m['peakkb_per_item'] ?? '-'
        );
        $q = $summary['quba_load'];
        $lines[] = sprintf(
            'quba load: %s ms/item, first tenth p50 %s ms, last tenth p50 %s ms',
            $q['ms_per_item'] ?? '-',
            $f($q['first_tenth_p50']),
            $f($q['last_tenth_p50'])
        );
        $lines[] = 'open spans: ' . ($summary['open_spans'] ? json_encode($summary['open_spans']) : 'none');
        $ids = $summary['requestids'];
        $lines[] = sprintf(
            'request ids: %d distinct, %d missing, e.g. %s',
            $ids['distinct'],
            $ids['missing'],
            $ids['sample'] ?? '-'
        );
        return implode("\n", $lines) . "\n";
    }
}
