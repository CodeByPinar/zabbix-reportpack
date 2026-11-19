<?php
/**
 * Stand-alone tests for the Report Pack time arithmetic (no Zabbix needed).
 *
 *     php tests/report_test.php
 */

require __DIR__.'/../ReportPack/includes/Availability.php';
require __DIR__.'/../ReportPack/includes/Period.php';

use Modules\ReportPack\Includes\Availability;
use Modules\ReportPack\Includes\Period;

date_default_timezone_set('Europe/Berlin');

$failures = 0;

function check(bool $ok, string $what): void {
	global $failures;

	if (!$ok) {
		$failures++;
	}

	echo ($ok ? 'ok   ' : 'FAIL ').$what."\n";
}

function ev(int $clock, int $value): array {
	return ['clock' => $clock, 'value' => $value];
}

// --- One trigger ------------------------------------------------------------------

$r = Availability::triggerIntervals(1000, 2000, false, []);
check($r['intervals'] === [] && $r['problems'] === 0, 'quiet trigger: no downtime');

$r = Availability::triggerIntervals(1000, 2000, true, []);
check($r['intervals'] === [[1000, 2000]] && $r['problems'] === 0, 'in problem all period: whole period, no new problem counted');

$r = Availability::triggerIntervals(1000, 2000, false, [ev(1100, 1), ev(1300, 0)]);
check($r['intervals'] === [[1100, 1300]] && $r['problems'] === 1, 'one problem inside the period');

$r = Availability::triggerIntervals(1000, 2000, true, [ev(1200, 0)]);
check($r['intervals'] === [[1000, 1200]], 'problem from before the period, cut at the period start');

$r = Availability::triggerIntervals(1000, 2000, false, [ev(1900, 1)]);
check($r['intervals'] === [[1900, 2000]], 'unresolved problem cut at the period end');

$r = Availability::triggerIntervals(1000, 2000, false, [ev(1100, 1), ev(1150, 1), ev(1300, 0)]);
check($r['intervals'] === [[1100, 1300]] && $r['problems'] === 2,
	'multiple problem events: two problems, one outage');

$r = Availability::triggerIntervals(1000, 2000, false, [ev(1100, 0)]);
check($r['intervals'] === [], 'recovery without a problem changes nothing');

$r = Availability::triggerIntervals(1000, 2000, false, [ev(1100, 1), ev(1100, 0)]);
check($r['intervals'] === [] && $r['problems'] === 1, 'problem and recovery in the same second: no downtime');

// --- Several triggers on one host -------------------------------------------------

$merged = Availability::union([[1500, 1600], [1100, 1300], [1250, 1400], [1400, 1450]]);
check($merged === [[1100, 1450], [1500, 1600]], 'overlapping and touching intervals are merged');
check(Availability::total($merged) === 450, 'total of merged intervals');
check(Availability::longest($merged) === 350, 'longest merged interval');
check(Availability::union([[5, 5], [7, 6]]) === [], 'empty and reversed intervals are dropped');

// --- Percentages and SLA ----------------------------------------------------------

check(Availability::percent(1000, 0) === 100.0, '100% with no downtime');
check(Availability::percent(1000, 1000) === 0.0, '0% when down all the time');
check(Availability::percent(1000, 5000) === 0.0, 'downtime longer than the period stays at 0%');
check(Availability::percent(2592000, 1) === 99.999, '1 s in 30 days rounds down to 99.999, never up to 100');
check(Availability::percent(0, 0) === 100.0, 'empty period counts as available');
check(Availability::allowedDowntime(2592000, 99.9) === 2592, '99.9% of 30 days allows 43 min 12 s');
check(Availability::percent(1000, 1) === 99.9, '1 s in 1000 s is exactly 99.9, not 99.899');
check(Availability::meets(99.9, 99.9), 'availability equal to the target meets it');
check(!Availability::meets(99.899, 99.9), 'availability just below the target misses it');
check(Availability::meets(100.0, 100.0) && !Availability::meets(99.999, 100.0), '100% target');

// --- Periods ----------------------------------------------------------------------

$now = strtotime('2026-11-17 15:30:00');

$p = Period::resolve(Period::THIS_MONTH, null, null, $now);
check($p['from'] === strtotime('2026-11-01 00:00:00') && $p['till'] === $now, 'this month runs from the 1st to now');

$p = Period::resolve(Period::LAST_MONTH, null, null, $now);
check($p['from'] === strtotime('2026-10-01 00:00:00') && $p['till'] === strtotime('2026-11-01 00:00:00') - 1,
	'last month is the whole of October');
check($p['label'] === 'October 2026', 'last month label');

$p = Period::resolve(Period::LAST_MONTH, null, null, strtotime('2026-03-10 12:00:00'));
check($p['label'] === 'February 2026' && $p['from'] === strtotime('2026-02-01 00:00:00')
	&& $p['till'] === strtotime('2026-03-01 00:00:00') - 1, 'last month in March is February');

$p = Period::resolve(Period::LAST_7_DAYS, null, null, $now);
check($p['till'] - $p['from'] === 7 * 86400, 'last 7 days');

$p = Period::resolve(Period::CUSTOM, '2026-10-01', '2026-10-31', $now);
check($p['from'] === strtotime('2026-10-01 00:00:00') && $p['till'] === strtotime('2026-11-01 00:00:00') - 1,
	'custom dates include the whole end day');

$p = Period::resolve(Period::CUSTOM, '2026-11-01', '2026-12-31', $now);
check($p['till'] === $now, 'custom period in the future stops at now');

check(Period::resolve(Period::CUSTOM, '2026-10-31', '2026-10-01', $now) === null, 'end before start is refused');
check(Period::resolve(Period::CUSTOM, '2026-02-30', '2026-03-01', $now) === null, 'impossible date is refused');
check(Period::resolve(Period::CUSTOM, '01.10.2026', '2026-10-31', $now) === null, 'other date formats are refused');
check(Period::resolve(Period::CUSTOM, '2026-12-01', '2026-12-31', $now) === null, 'period entirely in the future is refused');
check(Period::resolve('yesterday', null, null, $now) === null, 'unknown period is refused');

// --- Interval subtraction -----------------------------------------------------------

check(Availability::subtract([[100, 200]], [[120, 150]]) === [[100, 120], [150, 200]], 'cut in the middle');
check(Availability::subtract([[100, 200]], [[50, 120], [180, 300]]) === [[120, 180]], 'cuts at both ends');
check(Availability::subtract([[100, 200]], [[0, 500]]) === [], 'fully covered');
check(Availability::subtract([[100, 200], [300, 400]], []) === [[100, 200], [300, 400]], 'no cuts');
check(Availability::contains([[100, 200]], 100) && !Availability::contains([[100, 200]], 200), 'contains: start in, end out');

// --- Maintenance windows ------------------------------------------------------------

require_once __DIR__.'/../ReportPack/includes/Maintenance.php';

use Modules\ReportPack\Includes\Maintenance;

$t = static function (string $text): int {
	return strtotime($text);
};

$oct = [$t('2026-10-01 00:00'), $t('2026-11-01 00:00')];
$always = ['active_since' => $t('2026-01-01 00:00'), 'active_till' => $t('2027-01-01 00:00')];

$w = Maintenance::windows($always + ['timeperiods' => [
	['timeperiod_type' => 0, 'start_date' => $t('2026-10-10 22:00'), 'period' => 7200]
]], ...$oct);
check($w === [[$t('2026-10-10 22:00'), $t('2026-10-11 00:00')]], 'one-time window');

$w = Maintenance::windows($always + ['timeperiods' => [
	['timeperiod_type' => 2, 'every' => 1, 'start_time' => 2 * 3600, 'period' => 3600]
]], ...$oct);
check(count($w) === 31 && $w[0] === [$t('2026-10-01 02:00'), $t('2026-10-01 03:00')], 'daily 02:00–03:00: 31 windows in October');
check(Availability::total($w) === 31 * 3600, 'daily: 31 hours in total');

$w = Maintenance::windows(['active_since' => $t('2026-10-01 00:00'), 'active_till' => $t('2027-01-01 00:00'), 'timeperiods' => [
	['timeperiod_type' => 2, 'every' => 2, 'start_time' => 0, 'period' => 600]
]], ...$oct);
check(count($w) === 16 && $w[1][0] === $t('2026-10-03 00:00'), 'every 2 days counted from the active-since day');

// Weekly: Sundays (bit 6) 01:00 for 4 hours.
$w = Maintenance::windows($always + ['timeperiods' => [
	['timeperiod_type' => 3, 'every' => 1, 'dayofweek' => 64, 'start_time' => 3600, 'period' => 4 * 3600]
]], ...$oct);
check(count($w) === 4 && $w[0][0] === $t('2026-10-04 01:00'), 'weekly on Sundays: 4 windows, first on 4 October');

// Window that starts before the period and runs into it.
$w = Maintenance::windows($always + ['timeperiods' => [
	['timeperiod_type' => 2, 'every' => 1, 'start_time' => 23 * 3600, 'period' => 3 * 3600]
]], ...$oct);
check($w[0] === [$t('2026-10-01 00:00'), $t('2026-10-01 02:00')], 'window from the evening before is cut at the period start');

// Monthly: day 15 of October only (month bit 9).
$w = Maintenance::windows($always + ['timeperiods' => [
	['timeperiod_type' => 4, 'month' => 1 << 9, 'day' => 15, 'dayofweek' => 0, 'every' => 1, 'start_time' => 0, 'period' => 3600]
]], ...$oct);
check($w === [[$t('2026-10-15 00:00'), $t('2026-10-15 01:00')]], 'monthly on the 15th');

// Monthly: last Friday (bit 4) of October 2026 is the 30th.
$w = Maintenance::windows($always + ['timeperiods' => [
	['timeperiod_type' => 4, 'month' => 4095, 'day' => 0, 'dayofweek' => 16, 'every' => 5, 'start_time' => 0, 'period' => 3600]
]], ...$oct);
check($w === [[$t('2026-10-30 00:00'), $t('2026-10-30 01:00')]], 'monthly on the last Friday');

// Monthly: second Monday (bit 0) of October 2026 is the 12th.
$w = Maintenance::windows($always + ['timeperiods' => [
	['timeperiod_type' => 4, 'month' => 4095, 'day' => 0, 'dayofweek' => 1, 'every' => 2, 'start_time' => 0, 'period' => 3600]
]], ...$oct);
check($w === [[$t('2026-10-12 00:00'), $t('2026-10-12 01:00')]], 'monthly on the second Monday');

// Day 31 does not exist in November: no window.
$w = Maintenance::windows($always + ['timeperiods' => [
	['timeperiod_type' => 4, 'month' => 1 << 10, 'day' => 31, 'dayofweek' => 0, 'every' => 1, 'start_time' => 0, 'period' => 3600]
]], $t('2026-11-01 00:00'), $t('2026-12-01 00:00'));
check($w === [], 'monthly on the 31st skips November');

// Active range limits the windows.
$w = Maintenance::windows(['active_since' => $t('2026-10-10 00:00'), 'active_till' => $t('2026-10-12 00:00'), 'timeperiods' => [
	['timeperiod_type' => 2, 'every' => 1, 'start_time' => 0, 'period' => 86400]
]], ...$oct);
check($w === [[$t('2026-10-10 00:00'), $t('2026-10-12 00:00')]], 'windows only inside active since/till');

check(Maintenance::windows(['active_since' => $t('2025-01-01'), 'active_till' => $t('2025-02-01'), 'timeperiods' => [
	['timeperiod_type' => 2, 'every' => 1, 'start_time' => 0, 'period' => 3600]
]], ...$oct) === [], 'maintenance that ended before the period has no windows');

// Daily maintenance active for years: still quick.
$started = microtime(true);
$w = Maintenance::windows(['active_since' => $t('2016-01-01'), 'active_till' => $t('2036-01-01'), 'timeperiods' => [
	['timeperiod_type' => 2, 'every' => 1, 'start_time' => 0, 'period' => 60]
]], ...$oct);
check(count($w) === 31 && microtime(true) - $started < 0.5, 'long-running daily maintenance does not walk 10 years day by day');

// --- Schedules ----------------------------------------------------------------------

require_once __DIR__.'/../ReportPack/includes/PdfReport.php';
require_once __DIR__.'/../ReportPack/includes/Schedule.php';

use Modules\ReportPack\Includes\Schedule;

$s = Schedule::normalise(['name' => 'Monthly SLA', 'groupid' => '22', 'recipients' => 'a@example.com, b@example.com;c@example.com',
	'frequency' => 'monthly', 'monthday' => 1, 'hour' => 7]);
check(Schedule::validate($s) === [], 'valid schedule passes');
check(Schedule::recipients($s['recipients']) === ['a@example.com', 'b@example.com', 'c@example.com'], 'recipient list split');
check(count(Schedule::validate(['recipients' => 'not-an-address'] + $s)) === 1, 'bad address refused');
check(count(Schedule::validate(['monthday' => 31] + $s)) === 1, 'day 31 refused');
check(count(Schedule::validate(['subject' => "a\r\nBcc: x@y.z"] + $s)) === 1, 'subject with a line break refused (header injection)');
check(count(Schedule::validate(['name' => '', 'groupid' => ''] + $s)) === 2, 'name and group required');

$now = $t('2026-11-17 10:30');
check(Schedule::lastDue($s, $now) === $t('2026-11-01 07:00'), 'monthly: last due on the 1st at 07:00');
check(Schedule::nextDue($s, $now) === $t('2026-12-01 07:00'), 'monthly: next due on 1 December');
check(Schedule::lastDue(['monthday' => 20] + $s, $now) === $t('2026-10-20 07:00'), 'monthly day 20 not reached yet: previous month');
check(Schedule::lastDue(['monthday' => 20] + $s, $t('2026-03-10 00:00')) === $t('2026-02-20 07:00'), 'monthly across a short month');

$daily = ['frequency' => 'daily', 'hour' => 8] + $s;
check(Schedule::lastDue($daily, $now) === $t('2026-11-17 08:00'), 'daily: today 08:00');
check(Schedule::lastDue($daily, $t('2026-11-17 07:59')) === $t('2026-11-16 08:00'), 'daily before the hour: yesterday');
check(Schedule::nextDue($daily, $now) === $t('2026-11-18 08:00'), 'daily: next tomorrow');

$weekly = ['frequency' => 'weekly', 'weekday' => 1, 'hour' => 6] + $s;
check(Schedule::lastDue($weekly, $now) === $t('2026-11-16 06:00'), 'weekly Monday: 16 November (17th is a Tuesday)');
check(Schedule::lastDue($weekly, $t('2026-11-16 05:00')) === $t('2026-11-09 06:00'), 'weekly before the hour on Monday: a week earlier');
check(Schedule::nextDue($weekly, $now) === $t('2026-11-23 06:00'), 'weekly: next Monday');

check(Schedule::isDue($daily, $t('2026-11-17 08:10'), null), 'first sight shortly after the due time: send');
check(!Schedule::isDue($daily, $t('2026-11-17 12:00'), null), 'first sight hours later: do not send an old slot');
check(Schedule::isDue($daily, $now, $t('2026-11-16 08:05')), 'sent yesterday, due again today');
check(!Schedule::isDue($daily, $now, $t('2026-11-17 08:05')), 'already sent today');
check(!Schedule::isDue(['enabled' => false] + $daily, $t('2026-11-17 08:10'), null), 'disabled schedule never runs');

check(Schedule::expand('{report} – {prepared_for} – {period}', ['report' => 'Availability report',
	'prepared_for' => 'Acme', 'period' => 'October 2026']) === 'Availability report – Acme – October 2026', 'subject template');
check(Schedule::describe($weekly) === 'Weekly on Monday at 06:00', 'describe weekly');

echo $failures === 0 ? "\nALL CHECKS PASSED\n" : "\n$failures CHECK(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
