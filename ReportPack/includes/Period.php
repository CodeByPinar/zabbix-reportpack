<?php

namespace Modules\ReportPack\Includes;

/**
 * Turns a period choice from the form into a start and end time.
 *
 * Times are Unix timestamps; calendar periods follow the time zone PHP is set to, which in the
 * Zabbix frontend is the time zone of the signed-in user. The end of a period that is still
 * running (this month, the last 7 days) is "now", so the report never counts the future.
 */
class Period {

	public const THIS_MONTH = 'this_month';
	public const LAST_MONTH = 'last_month';
	public const LAST_7_DAYS = 'last_7_days';
	public const LAST_30_DAYS = 'last_30_days';
	public const CUSTOM = 'custom';

	public const ALL = [self::THIS_MONTH, self::LAST_MONTH, self::LAST_7_DAYS, self::LAST_30_DAYS, self::CUSTOM];

	/**
	 * @param string      $period  One of the constants above.
	 * @param string|null $from    Custom start date, YYYY-MM-DD (first day included).
	 * @param string|null $to      Custom end date, YYYY-MM-DD (last day included).
	 * @param int|null    $now     For tests.
	 *
	 * @return array|null  ['from' => int, 'till' => int, 'label' => string], or null when a custom date is invalid.
	 */
	public static function resolve(string $period, ?string $from = null, ?string $to = null, ?int $now = null): ?array {
		$now = $now ?? time();

		switch ($period) {
			case self::THIS_MONTH:
				$start = strtotime(date('Y-m-01 00:00:00', $now));
				return self::make($start, $now, date('F Y', $start));

			case self::LAST_MONTH:
				$this_month = strtotime(date('Y-m-01 00:00:00', $now));
				$start = strtotime('-1 month', $this_month);
				return self::make($start, $this_month - 1, date('F Y', $start));

			case self::LAST_7_DAYS:
				return self::make($now - 7 * 86400, $now, 'Last 7 days');

			case self::LAST_30_DAYS:
				return self::make($now - 30 * 86400, $now, 'Last 30 days');

			case self::CUSTOM:
				$start = self::parseDate($from);
				$end = self::parseDate($to);

				if ($start === null || $end === null || $end < $start) {
					return null;
				}

				// The end date is included in full.
				$till = min(strtotime('+1 day', $end) - 1, $now);

				if ($till <= $start) {
					return null;
				}

				return self::make($start, $till, date('Y-m-d', $start).' – '.date('Y-m-d', $end));
		}

		return null;
	}

	private static function make(int $from, int $till, string $label): array {
		return ['from' => $from, 'till' => $till, 'label' => $label];
	}

	private static function parseDate(?string $value): ?int {
		if ($value === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
			return null;
		}

		[$year, $month, $day] = array_map('intval', explode('-', $value));

		if (!checkdate($month, $day, $year)) {
			return null;
		}

		return strtotime($value.' 00:00:00');
	}
}
