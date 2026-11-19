<?php

namespace Modules\ReportPack\Includes;

use DateTimeImmutable,
	DateTimeZone;

/**
 * Turns Zabbix maintenance definitions into the time windows they cover inside a report period.
 *
 * Follows the Zabbix maintenance model: a maintenance is active between active_since and active_till,
 * and inside that range its time periods (one time, daily, weekly, monthly) open windows of "period"
 * seconds that start at "start_time" seconds after midnight. Calendar arithmetic uses the PHP time
 * zone, which in the frontend is the user's; the Zabbix server applies its own, so the two should match.
 *
 * Pure: no Zabbix calls, so it can be tested on its own.
 */
class Maintenance {

	public const ONE_TIME = 0;
	public const DAILY = 2;
	public const WEEKLY = 3;
	public const MONTHLY = 4;

	/**
	 * Windows of one maintenance inside [$from, $till], merged.
	 *
	 * @param array $maintenance  ['active_since', 'active_till', 'timeperiods' => [...]] as maintenance.get returns it.
	 */
	public static function windows(array $maintenance, int $from, int $till): array {
		$active_since = (int) $maintenance['active_since'];
		$active_till = (int) $maintenance['active_till'];

		$from = max($from, $active_since);
		$till = min($till, $active_till);

		if ($till <= $from) {
			return [];
		}

		$windows = [];

		foreach ($maintenance['timeperiods'] ?? [] as $timeperiod) {
			foreach (self::timeperiodWindows($timeperiod, $active_since, $from, $till) as $window) {
				$start = max($window[0], $from);
				$end = min($window[1], $till);

				if ($end > $start) {
					$windows[] = [$start, $end];
				}
			}
		}

		return Availability::union($windows);
	}

	/**
	 * Raw windows of one time period that may overlap [$from, $till]; the caller clips them.
	 */
	private static function timeperiodWindows(array $timeperiod, int $active_since, int $from, int $till): array {
		$type = (int) $timeperiod['timeperiod_type'];
		$period = max(0, (int) $timeperiod['period']);
		$start_time = (int) ($timeperiod['start_time'] ?? 0);
		$every = max(1, (int) ($timeperiod['every'] ?? 1));

		if ($period === 0) {
			return [];
		}

		if ($type === self::ONE_TIME) {
			$start = (int) $timeperiod['start_date'];

			return [[$start, $start + $period]];
		}

		$zone = new DateTimeZone(date_default_timezone_get());
		$windows = [];

		// A window that started before $from can still reach into it.
		$scan_from = $from - $period;

		switch ($type) {
			case self::DAILY:
				$day = self::midnight($active_since, $zone);

				// Jump close to the scan start instead of walking from active_since day by day.
				$skip = intdiv(max(0, $scan_from - $day->getTimestamp()), 86400 * $every);

				if ($skip > 1) {
					$day = $day->modify('+'.(($skip - 1) * $every).' days');
				}

				for (; $day->getTimestamp() <= $till; $day = $day->modify('+'.$every.' days')) {
					$start = $day->getTimestamp() + $start_time;

					if ($start + $period > $scan_from && $start < $till) {
						$windows[] = [$start, $start + $period];
					}
				}
				break;

			case self::WEEKLY:
				$days = (int) $timeperiod['dayofweek'];
				$monday = self::midnight($active_since, $zone);
				$monday = $monday->modify('-'.((int) $monday->format('N') - 1).' days');

				$skip = intdiv(max(0, $scan_from - $monday->getTimestamp()), 7 * 86400 * $every);

				if ($skip > 1) {
					$monday = $monday->modify('+'.(($skip - 1) * $every * 7).' days');
				}

				for (; $monday->getTimestamp() <= $till; $monday = $monday->modify('+'.(7 * $every).' days')) {
					for ($weekday = 0; $weekday < 7; $weekday++) {
						if (($days & (1 << $weekday)) === 0) {
							continue;
						}

						$start = $monday->modify('+'.$weekday.' days')->getTimestamp() + $start_time;

						if ($start + $period > $scan_from && $start < $till) {
							$windows[] = [$start, $start + $period];
						}
					}
				}
				break;

			case self::MONTHLY:
				$months = (int) $timeperiod['month'];
				$day_of_month = (int) ($timeperiod['day'] ?? 0);
				$days = (int) ($timeperiod['dayofweek'] ?? 0);
				$week = (int) ($timeperiod['every'] ?? 1);

				$month = self::midnight(max($scan_from, $active_since), $zone)->modify('first day of this month');

				for (; $month->getTimestamp() <= $till; $month = $month->modify('first day of next month')) {
					if (($months & (1 << ((int) $month->format('n') - 1))) === 0) {
						continue;
					}

					foreach (self::monthlyDays($month, $day_of_month, $days, $week) as $day) {
						$start = $day->getTimestamp() + $start_time;

						if ($start + $period > $scan_from && $start < $till) {
							$windows[] = [$start, $start + $period];
						}
					}
				}
				break;
		}

		return $windows;
	}

	/**
	 * Days of a month on which a monthly time period starts: a day of the month, or the n-th
	 * (5 = last) occurrence of each selected weekday.
	 *
	 * @return DateTimeImmutable[]
	 */
	private static function monthlyDays(DateTimeImmutable $month, int $day_of_month, int $days, int $week): array {
		$length = (int) $month->format('t');

		if ($day_of_month > 0) {
			// Zabbix skips months that do not have the day (a period on the 31st does not run in April).
			return $day_of_month <= $length ? [$month->modify('+'.($day_of_month - 1).' days')] : [];
		}

		$result = [];

		for ($weekday = 0; $weekday < 7; $weekday++) {
			if (($days & (1 << $weekday)) === 0) {
				continue;
			}

			// First occurrence of this weekday (0 = Monday) in the month.
			$offset = ($weekday - ((int) $month->format('N') - 1) + 7) % 7;
			$day = 1 + $offset + 7 * (min($week, 5) - 1);

			if ($week >= 5 || $day > $length) {
				// "Last": step back by weeks until inside the month.
				$day = 1 + $offset + 7 * 4;

				while ($day > $length) {
					$day -= 7;
				}
			}

			$result[] = $month->modify('+'.($day - 1).' days');
		}

		return $result;
	}

	private static function midnight(int $timestamp, DateTimeZone $zone): DateTimeImmutable {
		return (new DateTimeImmutable('@'.$timestamp))->setTimezone($zone)->setTime(0, 0);
	}
}
