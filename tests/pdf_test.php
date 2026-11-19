<?php
/**
 * Builds sample Report Pack PDFs without Zabbix, for checking the layout and the font embedding.
 *
 *     php tests/pdf_test.php <output folder>
 *
 * Writes availability.pdf (70 hosts, several pages, names in many scripts) and top_problems.pdf,
 * plus expected.txt with strings a PDF reader must be able to extract from them.
 */

foreach (['Availability', 'Period', 'TrueTypeFont', 'Pdf', 'Branding', 'PdfReport'] as $class) {
	require __DIR__.'/../ReportPack/includes/'.$class.'.php';
}

use Modules\ReportPack\Includes\PdfReport;

date_default_timezone_set('UTC');

$out = rtrim($argv[1] ?? sys_get_temp_dir(), '/\\');

// DejaVu Sans: tests/fixtures (not in the repository), $REPORTPACK_FONT, or the usual system places.
$font = null;

foreach ([__DIR__.'/fixtures/DejaVuSans.ttf', (string) getenv('REPORTPACK_FONT'),
		'/usr/share/fonts/dejavu/DejaVuSans.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
		'/usr/share/fonts/dejavu-sans-fonts/DejaVuSans.ttf', '/usr/share/zabbix/assets/fonts/graphfont.ttf']
		as $candidate) {
	if ($candidate !== '' && is_readable($candidate)) {
		$font = $candidate;
		break;
	}
}

if ($font === null) {
	fwrite(STDERR, "DejaVuSans.ttf not found: put it in tests/fixtures or set REPORTPACK_FONT.\n");
	exit(2);
}

if (!is_dir($out) && !mkdir($out, 0777, true)) {
	fwrite(STDERR, "Cannot create the folder $out.\n");
	exit(2);
}

$bold = preg_replace('/(\.ttf)$/i', '-Bold$1', $font);
$fonts = ['regular' => $font, 'bold' => $bold !== $font && is_readable($bold) ? $bold : null];

$names = ['Zürich Core Switch 01', 'Łódź Branch Router', 'İstanbul Şube Güvenlik Duvarı', 'Москва Сервер 1',
	'Αθήνα Printer', 'São Paulo Access Point', 'Kraków Kamera Wejście', 'Ærøskøbing NAS'];

$rows = [];

for ($i = 0; $i < 70; $i++) {
	$downtime = $i < 5 ? 3600 * (5 - $i) : ($i % 7 === 0 ? 125 : 0);
	$availability = floor((100 * (2592000 - $downtime) / 2592000) * 1000) / 1000;
	$rows[] = [
		'hostid' => (string) (10000 + $i),
		'host' => $names[$i % count($names)].($i >= count($names) ? ' '.$i : ''),
		'availability' => $availability,
		'downtime' => $downtime,
		'outages' => $downtime > 0 ? 1 + $i % 3 : 0,
		'longest' => $downtime,
		'problems' => $downtime > 0 ? 2 : 0,
		'sla_met' => $availability >= 99.9
	];
}

$period = ['from' => strtotime('2026-10-01'), 'till' => strtotime('2026-11-01') - 1, 'label' => 'October 2026'];

$availability = [
	'type' => 'availability',
	'group' => 'Customers/Acme Europe',
	'period' => $period,
	'sla' => 99.9,
	'min_severity' => 3,
	'generated' => strtotime('2026-11-17 10:20'),
	'rows' => $rows,
	'daily' => array_map(static function (int $d): array {
		$down = [3 => 7200, 9 => 600, 17 => 18000, 24 => 120][$d] ?? 0;

		return ['day' => strtotime('2026-10-01') + 86400 * $d, 'availability' => floor((100 - $down / 864 / 70) * 1000) / 1000,
			'downtime' => $down];
	}, range(0, 30)),
	'summary' => [
		'exclude_maintenance' => true,
		'maintenance' => 7200,
		'hosts' => 70,
		'triggers' => 120,
		'sla_met' => count(array_filter($rows, static function ($r) { return $r['sla_met']; })),
		'sla_missed' => count(array_filter($rows, static function ($r) { return !$r['sla_met']; })),
		'average' => 99.991,
		'downtime' => array_sum(array_column($rows, 'downtime')),
		'allowed_downtime' => 2592
	]
];

$branding = ['company' => 'Example Managed IT', 'accent' => '#7C3AED', 'footer' => 'Example Managed IT · support@example.com · Confidential'];
$options = [
	'prepared_for' => 'Acme Europe GmbH',
	'severity_names' => [0 => 'Not classified', 1 => 'Information', 2 => 'Warning', 3 => 'Average', 4 => 'High', 5 => 'Disaster'],
	'severity_colors' => [0 => '97AAB3', 1 => '7499FF', 2 => 'FFC859', 3 => 'FFA059', 4 => 'E97659', 5 => 'E45959']
];

$t = microtime(true);
file_put_contents($out.'/availability.pdf', PdfReport::render($availability, $branding, null, $fonts, $options));
$ms_availability = (int) ((microtime(true) - $t) * 1000);

$problems = [];

for ($i = 0; $i < 12; $i++) {
	$problems[] = [
		'triggerid' => (string) (20000 + $i),
		'host' => $names[$i % count($names)],
		'problem' => $i === 0 ? 'Interface Gi0/1(Uplink to Łódź – very long description that has to be cut): Link down' : 'High CPU utilization (over 90% for 5m)',
		'severity' => 5 - $i % 5,
		'problems' => 30 - 2 * $i,
		'duration' => 600 * (12 - $i),
		'last' => strtotime('2026-10-30 08:15') - 3600 * $i
	];
}

$top = [
	'type' => 'top_problems',
	'group' => 'Customers/Acme Europe',
	'period' => $period,
	'sla' => 99.9,
	'min_severity' => 2,
	'generated' => strtotime('2026-11-17 10:20'),
	'rows' => $problems,
	'summary' => ['exclude_maintenance' => false, 'triggers' => 120, 'with_problems' => 12, 'problems' => array_sum(array_column($problems, 'problems')), 'shown' => 12]
];

// No bold font: the fake-bold fallback is used.
file_put_contents($out.'/top_problems.pdf', PdfReport::render($top, ['company' => '', 'accent' => 'not-a-colour', 'footer' => ''],
	null, ['regular' => $fonts['regular'], 'bold' => null], ['prepared_for' => ''] + $options
));

file_put_contents($out.'/expected.txt', implode("\n", [
	'Availability report',
	'Prepared for Acme Europe GmbH',
	'Example Managed IT',
	'İstanbul Şube Güvenlik Duvarı',
	'Москва Сервер 1',
	'Αθήνα Printer',
	'Łódź Branch Router',
	'Page 1 of'
])."\n");

echo 'availability.pdf: '.filesize($out.'/availability.pdf').' bytes in '.$ms_availability." ms\n";
echo 'top_problems.pdf: '.filesize($out.'/top_problems.pdf')." bytes\n";

// Duration formatting.
$cases = [0 => '0', 37 => '37s', 107 => '1m 47s', 3600 => '1h 0m', 3725 => '1h 2m', 90061 => '1d 1h', 2592 => '43m 12s'];
$failed = 0;

foreach ($cases as $seconds => $expected) {
	$got = PdfReport::duration($seconds);

	if ($got !== $expected) {
		$failed++;
		echo "FAIL duration($seconds) = $got, expected $expected\n";
	}
}

echo $failed === 0 ? "durations ok\n" : "$failed duration check(s) failed\n";
exit($failed === 0 ? 0 : 1);
