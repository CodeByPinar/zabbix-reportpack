<?php

namespace Modules\ReportPack\Includes;

/**
 * Time arithmetic for the availability report. No Zabbix calls here, so it can be tested on its own.
 *
 * An interval is [start, end] in Unix seconds, end not included.
 */
class Availability {

	/**
	 * Problem intervals of one trigger inside a period.
	 *
	 * @param int   $from         Period start.
	 * @param int   $till         Period end.
	 * @param bool  $problem_at_start  Whether the trigger was in problem state when the period began.
	 * @param array $events       Trigger events inside the period, sorted by time:
	 *                            [['clock' => int, 'value' => 0|1], ...]; 1 = problem, 0 = recovery.
	 *
	 * @return array  ['intervals' => [[start, end], ...], 'problems' => number of problems that began in the
	 *                period, 'starts' => their start times]
	 */
	public static function triggerIntervals(int $from, int $till, bool $problem_at_start, array $events): array {
		$intervals = [];
		$problems = 0;
		$starts = [];
		$open = $problem_at_start ? $from : null;

		foreach ($events as $event) {
			$clock = max($from, min($till, (int) $event['clock']));

			if ((int) $event['value'] === 1) {
				// With "multiple problem events" a trigger can fire again while already in problem;
				// that is one more problem but not a new outage.
				$problems++;
				$starts[] = $clock;

				if ($open === null) {
					$open = $clock;
				}
			}
			elseif ($open !== null) {
				if ($clock > $open) {
					$intervals[] = [$open, $clock];
				}

				$open = null;
			}
		}

		if ($open !== null && $till > $open) {
			$intervals[] = [$open, $till];
		}

		return ['intervals' => $intervals, 'problems' => $problems, 'starts' => $starts];
	}

	/**
	 * Merges overlapping and touching intervals, so time with two problems at once is counted once.
	 */
	public static function union(array $intervals): array {
		usort($intervals, static function (array $a, array $b): int {
			return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
		});

		$merged = [];

		foreach ($intervals as [$start, $end]) {
			if ($end <= $start) {
				continue;
			}

			$last = count($merged) - 1;

			if ($last >= 0 && $start <= $merged[$last][1]) {
				$merged[$last][1] = max($merged[$last][1], $end);
			}
			else {
				$merged[] = [$start, $end];
			}
		}

		return $merged;
	}

	/**
	 * The parts of $intervals not covered by $cuts (for example, downtime outside maintenance).
	 */
	public static function subtract(array $intervals, array $cuts): array {
		$cuts = self::union($cuts);
		$result = [];

		foreach (self::union($intervals) as [$start, $end]) {
			foreach ($cuts as [$cut_start, $cut_end]) {
				if ($cut_end <= $start || $cut_start >= $end) {
					continue;
				}

				if ($cut_start > $start) {
					$result[] = [$start, $cut_start];
				}

				$start = max($start, $cut_end);

				if ($start >= $end) {
					break;
				}
			}

			if ($start < $end) {
				$result[] = [$start, $end];
			}
		}

		return $result;
	}

	/**
	 * Whether a moment falls inside any of the intervals.
	 */
	public static function contains(array $intervals, int $moment): bool {
		foreach ($intervals as [$start, $end]) {
			if ($moment >= $start && $moment < $end) {
				return true;
			}
		}

		return false;
	}

	public static function total(array $intervals): int {
		$sum = 0;

		foreach ($intervals as [$start, $end]) {
			$sum += $end - $start;
		}

		return $sum;
	}

	public static function longest(array $intervals): int {
		$max = 0;

		foreach ($intervals as [$start, $end]) {
			$max = max($max, $end - $start);
		}

		return $max;
	}

	/**
	 * Availability in percent, rounded down to 3 decimals so that 99.9996 never shows as 100.
	 */
	public static function percent(int $period_seconds, int $downtime_seconds): float {
		if ($period_seconds <= 0) {
			return 100.0;
		}

		$value = 100 * ($period_seconds - min($downtime_seconds, $period_seconds)) / $period_seconds;

		// round() first: binary floating point turns 99.9 into 99.89999…, which floor() would cut to 99.899.
		return floor(round($value * 1000, 6)) / 1000;
	}

	/**
	 * Whether an availability value meets an SLA target, compared at the 3 decimals that are shown.
	 */
	public static function meets(float $availability, float $sla_percent): bool {
		return round($availability, 3) >= round($sla_percent, 3);
	}

	/**
	 * Downtime allowed in a period for an SLA target, in seconds.
	 */
	public static function allowedDowntime(int $period_seconds, float $sla_percent): int {
		return (int) floor(round($period_seconds * (100 - $sla_percent) / 100, 6));
	}
}
