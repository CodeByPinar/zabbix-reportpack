<?php

namespace Modules\ReportPack\Includes;

use DateTimeImmutable,
	DateTimeZone;

/**
 * Scheduled reports: their settings, checks, and when each one is due.
 *
 * Schedules are kept in the module configuration (Super admins edit them in the frontend); the
 * runner reads them through the API and sends the reports by e-mail. Pure apart from storage.
 */
class Schedule {

	public const FORMATS = ['pdf', 'xlsx', 'csv'];
	public const FREQUENCIES = ['daily', 'weekly', 'monthly'];
	public const PERIODS = [Period::LAST_MONTH, Period::THIS_MONTH, Period::LAST_7_DAYS, Period::LAST_30_DAYS];

	public const DEFAULTS = [
		'id' => '',
		'name' => '',
		'enabled' => true,
		'report' => 'availability',
		'groupid' => '',
		'period' => Period::LAST_MONTH,
		'min_severity' => 3,
		'sla' => '99.9',
		'limit' => 25,
		'prepared_for' => '',
		'maintenance' => 'exclude',
		'format' => 'pdf',
		'frequency' => 'monthly',
		'weekday' => 1,
		'monthday' => 1,
		'hour' => 7,
		'recipients' => '',
		'subject' => '{report} – {prepared_for} – {period}',
		'message' => "Hello,\n\nattached is the {report} for {prepared_for}, {period}.\n\nThis message was sent automatically by Zabbix."
	];

	/**
	 * Fills in defaults and normalises types.
	 */
	public static function normalise(array $schedule): array {
		$schedule = array_merge(self::DEFAULTS, array_intersect_key($schedule, self::DEFAULTS));

		$schedule['enabled'] = (bool) $schedule['enabled'];
		$schedule['min_severity'] = (int) $schedule['min_severity'];
		$schedule['limit'] = (int) $schedule['limit'];
		$schedule['weekday'] = (int) $schedule['weekday'];
		$schedule['monthday'] = (int) $schedule['monthday'];
		$schedule['hour'] = (int) $schedule['hour'];
		$schedule['groupid'] = (string) $schedule['groupid'];

		return $schedule;
	}

	/**
	 * @return string[]  Problems with the schedule; empty when it can be saved.
	 */
	public static function validate(array $schedule): array {
		$errors = [];

		if (trim($schedule['name']) === '') {
			$errors[] = 'Name cannot be empty.';
		}

		if ($schedule['groupid'] === '' || !ctype_digit($schedule['groupid'])) {
			$errors[] = 'Select a host group.';
		}

		if (!in_array($schedule['report'], [PdfReport::AVAILABILITY, PdfReport::TOP_PROBLEMS], true)) {
			$errors[] = 'Unknown report type.';
		}

		if (!in_array($schedule['period'], self::PERIODS, true)) {
			$errors[] = 'Unknown period.';
		}

		if (!in_array($schedule['format'], self::FORMATS, true)) {
			$errors[] = 'Unknown file format.';
		}

		if (!in_array($schedule['frequency'], self::FREQUENCIES, true)) {
			$errors[] = 'Unknown frequency.';
		}

		$sla = str_replace(',', '.', (string) $schedule['sla']);

		if (!is_numeric($sla) || (float) $sla < 0 || (float) $sla > 100) {
			$errors[] = 'SLA target must be a number from 0 to 100.';
		}

		if ($schedule['min_severity'] < 0 || $schedule['min_severity'] > 5) {
			$errors[] = 'Unknown minimum severity.';
		}

		if ($schedule['limit'] < 1 || $schedule['limit'] > 1000) {
			$errors[] = 'Rows must be from 1 to 1000.';
		}

		if ($schedule['hour'] < 0 || $schedule['hour'] > 23) {
			$errors[] = 'Hour must be from 0 to 23.';
		}

		if ($schedule['weekday'] < 1 || $schedule['weekday'] > 7) {
			$errors[] = 'Unknown weekday.';
		}

		// Days 29–31 do not exist in every month.
		if ($schedule['monthday'] < 1 || $schedule['monthday'] > 28) {
			$errors[] = 'Day of month must be from 1 to 28.';
		}

		$recipients = self::recipients($schedule['recipients']);

		if (!$recipients) {
			$errors[] = 'Enter at least one recipient e-mail address.';
		}

		foreach ($recipients as $address) {
			if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
				$errors[] = 'Not a valid e-mail address: '.$address;
			}
		}

		if (count($recipients) > 50) {
			$errors[] = 'At most 50 recipients per schedule.';
		}

		if (preg_match('/[\r\n]/', $schedule['subject'])) {
			$errors[] = 'Subject must be a single line.';
		}

		return $errors;
	}

	/**
	 * E-mail addresses from a comma, semicolon, space or line separated list.
	 *
	 * @return string[]
	 */
	public static function recipients(string $list): array {
		$addresses = preg_split('/[\s,;]+/', trim($list), -1, PREG_SPLIT_NO_EMPTY);

		return array_values(array_unique($addresses));
	}

	/**
	 * The most recent moment, at or before $now, at which the schedule was due.
	 */
	public static function lastDue(array $schedule, int $now, ?DateTimeZone $zone = null): int {
		$zone = $zone ?? new DateTimeZone(date_default_timezone_get());
		$local = (new DateTimeImmutable('@'.$now))->setTimezone($zone);
		$hour = (int) $schedule['hour'];

		switch ($schedule['frequency']) {
			case 'daily':
				$due = $local->setTime($hour, 0);

				if ($due->getTimestamp() > $now) {
					$due = $due->modify('-1 day');
				}
				break;

			case 'weekly':
				$weekday = (int) $schedule['weekday'];
				$due = $local->setTime($hour, 0)->modify('-'.(((int) $local->format('N') - $weekday + 7) % 7).' days');

				if ($due->getTimestamp() > $now) {
					$due = $due->modify('-7 days');
				}
				break;

			default:
				$day = (int) $schedule['monthday'];
				$due = $local->setDate((int) $local->format('Y'), (int) $local->format('n'), $day)->setTime($hour, 0);

				if ($due->getTimestamp() > $now) {
					$previous = $local->modify('first day of previous month');
					$due = $previous->setDate((int) $previous->format('Y'), (int) $previous->format('n'), $day)
						->setTime($hour, 0);
				}
				break;
		}

		return $due->getTimestamp();
	}

	/**
	 * The next moment after $now at which the schedule is due.
	 */
	public static function nextDue(array $schedule, int $now, ?DateTimeZone $zone = null): int {
		$zone = $zone ?? new DateTimeZone(date_default_timezone_get());
		$step = ['daily' => 86400 + 7200, 'weekly' => 7 * 86400 + 7200, 'monthly' => 32 * 86400][$schedule['frequency']]
			?? 32 * 86400;

		// The last due time a full step ahead is the next one after now.
		$next = self::lastDue($schedule, $now + $step, $zone);

		while ($next > $now) {
			$earlier = self::lastDue($schedule, $next - 1, $zone);

			if ($earlier <= $now) {
				break;
			}

			$next = $earlier;
		}

		return $next;
	}

	/**
	 * Whether the runner should send the schedule now.
	 *
	 * @param int|null $last_sent  When it was last sent or skipped; null when the runner has not seen it yet.
	 * @param int      $grace      A schedule seen for the first time is sent only if it fell due this recently,
	 *                             so a new schedule does not send a report for a time slot long gone.
	 */
	public static function isDue(array $schedule, int $now, ?int $last_sent, int $grace = 3600,
			?DateTimeZone $zone = null): bool {
		if (!$schedule['enabled']) {
			return false;
		}

		$due = self::lastDue($schedule, $now, $zone);

		if ($last_sent === null) {
			return $now - $due <= $grace;
		}

		return $due > $last_sent;
	}

	/**
	 * Replaces {report}, {prepared_for}, {period}, {group} and {from}/{till} in a subject or message.
	 */
	public static function expand(string $template, array $values): string {
		return strtr($template, [
			'{report}' => $values['report'] ?? '',
			'{prepared_for}' => $values['prepared_for'] ?? '',
			'{period}' => $values['period'] ?? '',
			'{group}' => $values['group'] ?? '',
			'{from}' => $values['from'] ?? '',
			'{till}' => $values['till'] ?? ''
		]);
	}

	public static function newId(): string {
		return bin2hex(random_bytes(6));
	}

	/**
	 * Plain-language summary such as "Monthly, day 1 at 07:00".
	 */
	public static function describe(array $schedule): string {
		$time = sprintf('%02d:00', $schedule['hour']);

		switch ($schedule['frequency']) {
			case 'daily':
				return 'Daily at '.$time;

			case 'weekly':
				$days = [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

				return 'Weekly on '.($days[$schedule['weekday']] ?? '?').' at '.$time;

			default:
				return 'Monthly on day '.$schedule['monthday'].' at '.$time;
		}
	}
}
