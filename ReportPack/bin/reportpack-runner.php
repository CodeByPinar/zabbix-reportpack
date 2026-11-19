<?php
/**
 * Report Pack scheduled-report runner.
 *
 * Reads the scheduled reports from Zabbix through the API, builds the ones that are due and sends them
 * by e-mail. Run it from cron every 15 minutes:
 *
 *     php reportpack-runner.php --config /etc/zabbix/reportpack.ini
 *
 * Other uses:
 *     --list               show the schedules and when each runs next
 *     --run <id|name>      send one schedule now, whether or not it is due
 *     --output <folder>    write the files to a folder instead of e-mailing them (with --run or alone)
 *     --test-mail <addr>   send a short test message to check the mail settings
 *     --verbose            print the mail server conversation (passwords hidden)
 *
 * Needs PHP 8.0 or later on the command line, with the mbstring and zlib extensions; OpenSSL for HTTPS and
 * encrypted mail. GD is optional: logos are stored as PNG, which is read without it.
 */

if (PHP_SAPI !== 'cli') {
	// Never run from the web server, even if the file is reachable by URL.
	http_response_code(404);
	exit;
}

use Modules\ReportPack\Includes\Branding,
	Modules\ReportPack\Includes\CsvReport,
	Modules\ReportPack\Includes\JsonRpcApi,
	Modules\ReportPack\Includes\Mailer,
	Modules\ReportPack\Includes\PdfReport,
	Modules\ReportPack\Includes\PngImage,
	Modules\ReportPack\Includes\ReportBuilder,
	Modules\ReportPack\Includes\Schedule,
	Modules\ReportPack\Includes\XlsxReport,
	Modules\ReportPack\Includes\ZabbixApi;

spl_autoload_register(static function (string $class): void {
	$prefix = 'Modules\\ReportPack\\Includes\\';

	if (strpos($class, $prefix) === 0) {
		$file = __DIR__.'/../includes/'.substr($class, strlen($prefix)).'.php';

		if (is_file($file)) {
			require $file;
		}
	}
});

const EXIT_OK = 0;
const EXIT_FAILED = 1;
const EXIT_USAGE = 2;

const FONT_CANDIDATES = [
	'/usr/share/zabbix/assets/fonts/graphfont.ttf',
	'/usr/share/zabbix/ui/assets/fonts/graphfont.ttf',
	'/usr/share/fonts/dejavu/DejaVuSans.ttf',
	'/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
	'/usr/share/fonts/dejavu-sans-fonts/DejaVuSans.ttf',
	'/usr/local/share/fonts/DejaVuSans.ttf'
];

const SEVERITY_DEFAULTS = [
	0 => ['Not classified', '97AAB3'],
	1 => ['Information', '7499FF'],
	2 => ['Warning', 'FFC859'],
	3 => ['Average', 'FFA059'],
	4 => ['High', 'E97659'],
	5 => ['Disaster', 'E45959']
];

exit(main($argv));

function main(array $argv): int {
	$options = getopt('', ['config:', 'list', 'run:', 'output:', 'test-mail:', 'verbose', 'help']);

	if (isset($options['help']) || !isset($options['config'])) {
		fwrite(STDERR, "Usage: php reportpack-runner.php --config <file> [--list | --run <id|name> | --test-mail <address>] [--output <folder>] [--verbose]\n");

		return isset($options['help']) ? EXIT_OK : EXIT_USAGE;
	}

	try {
		$config = load_config($options['config']);
	}
	catch (Throwable $e) {
		fwrite(STDERR, 'Configuration: '.$e->getMessage()."\n");

		return EXIT_USAGE;
	}

	if ($config['runner']['timezone'] !== '') {
		date_default_timezone_set($config['runner']['timezone']);
	}

	$verbose = isset($options['verbose']);

	if (isset($options['test-mail'])) {
		return test_mail($config, $options['test-mail'], $verbose);
	}

	ZabbixApi::use(new JsonRpcApi($config['zabbix']['url'], $config['zabbix']['token'],
		$config['zabbix']['verify_tls']
	));

	// Only one runner at a time: a slow report must not be sent twice by overlapping cron runs.
	$lock = fopen($config['runner']['state_file'].'.lock', 'c');

	if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
		say('Another runner is still working; nothing done.');

		return EXIT_OK;
	}

	try {
		$module_config = module_config();
	}
	catch (Throwable $e) {
		fwrite(STDERR, 'Zabbix API: '.$e->getMessage()."\n");

		return EXIT_FAILED;
	}

	$schedules = [];

	foreach ($module_config['schedules'] ?? [] as $schedule) {
		if (is_array($schedule) && ($schedule['id'] ?? '') !== '') {
			$schedules[$schedule['id']] = Schedule::normalise($schedule);
		}
	}

	$state = load_state($config['runner']['state_file']);
	$now = time();

	if (isset($options['list'])) {
		return list_schedules($schedules, $state, $now);
	}

	if (isset($options['run'])) {
		$schedule = find_schedule($schedules, $options['run']);

		if ($schedule === null) {
			fwrite(STDERR, 'No schedule with the ID or name "'.$options['run']."\". Use --list.\n");

			return EXIT_USAGE;
		}

		$ok = run_schedule($schedule, $config, $module_config, $now, $options['output'] ?? null, $verbose);

		if ($ok && !isset($options['output'])) {
			$state[$schedule['id']] = $now;
			save_state($config['runner']['state_file'], $state);
		}

		return $ok ? EXIT_OK : EXIT_FAILED;
	}

	$failed = 0;
	$sent = 0;

	foreach ($schedules as $id => $schedule) {
		$last = $state[$id] ?? null;

		if (!Schedule::isDue($schedule, $now, $last)) {
			// Remember schedules seen for the first time, so they start from their next slot.
			if ($last === null && $schedule['enabled']) {
				$state[$id] = Schedule::lastDue($schedule, $now);
			}

			continue;
		}

		if (run_schedule($schedule, $config, $module_config, $now, $options['output'] ?? null, $verbose)) {
			$state[$id] = $now;
			$sent++;
		}
		else {
			// Not recorded as sent: the next run tries again.
			$failed++;
		}
	}

	// Forget schedules that were deleted.
	$state = array_intersect_key($state, $schedules);
	save_state($config['runner']['state_file'], $state);

	if ($sent > 0 || $failed > 0 || $verbose) {
		say(sprintf('%d report(s) sent, %d failed.', $sent, $failed));
	}

	return $failed > 0 ? EXIT_FAILED : EXIT_OK;
}

/**
 * Builds one scheduled report and e-mails it (or writes it to a folder).
 */
function run_schedule(array $schedule, array $config, array $module_config, int $now, ?string $output,
		bool $verbose): bool {
	$label = '"'.$schedule['name'].'"';

	try {
		$result = ReportBuilder::build($schedule, $now);

		if ($result['report'] === null) {
			throw new RuntimeException($result['error']);
		}

		$report = $result['report'];
		$severities = severities();
		$prepared_for = trim($schedule['prepared_for']) !== '' ? trim($schedule['prepared_for']) : $report['group'];
		$branding = Branding::fromConfig($module_config);
		$options = [
			'prepared_for' => $schedule['prepared_for'],
			'severity_names' => array_column($severities, 0),
			'severity_colors' => array_column($severities, 1)
		];

		switch ($schedule['format']) {
			case 'pdf':
				$fonts = Branding::fonts(find_font($config['runner']['font']));

				if ($fonts['regular'] === null) {
					throw new RuntimeException('No DejaVu Sans font found for the PDF; set "font" in the [runner] section.');
				}

				$logo = Branding::logo();

				// Without GD only PNG logos can be read; Report Pack stores logos as PNG.
				if ($logo !== null && !function_exists('imagecreatefromstring')
						&& PngImage::decode($logo['bytes']) === null) {
					fwrite(STDERR, $label.': the logo is not a PNG file and the PHP GD extension is missing, '
						.'so the PDF is sent without the logo.'."\n");
				}

				$data = PdfReport::render($report, $branding, $logo !== null ? $logo['bytes'] : null, $fonts, $options);
				$type = 'application/pdf';
				break;

			case 'xlsx':
				$data = XlsxReport::render($report, $branding, $options);
				$type = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
				break;

			default:
				$data = CsvReport::render($report, $options);
				$type = 'text/csv';
		}

		$file_name = ReportBuilder::fileName($report, $schedule['report'], $schedule['format']);
		$values = [
			'report' => $schedule['report'] === ReportBuilder::AVAILABILITY ? 'Availability report' : 'Most frequent problems',
			'prepared_for' => $prepared_for,
			'period' => $report['period']['label'],
			'group' => $report['group'],
			'from' => date('Y-m-d H:i', $report['period']['from']),
			'till' => date('Y-m-d H:i', $report['period']['till'])
		];

		if ($output !== null) {
			if (!is_dir($output) && !mkdir($output, 0750, true)) {
				throw new RuntimeException('Cannot create folder '.$output);
			}

			file_put_contents(rtrim($output, '/\\').'/'.$file_name, $data);
			say($label.': written to '.rtrim($output, '/\\').'/'.$file_name.' ('.strlen($data).' bytes).');

			return true;
		}

		$mailer = new Mailer($config['smtp']);

		try {
			$mailer->send(Schedule::recipients($schedule['recipients']), Schedule::expand($schedule['subject'], $values),
				Schedule::expand($schedule['message'], $values),
				[['name' => $file_name, 'type' => $type, 'data' => $data]]
			);
		}
		finally {
			if ($verbose) {
				say(implode("\n", $mailer->log()));
			}
		}

		say($label.': sent to '.implode(', ', Schedule::recipients($schedule['recipients'])).'.');

		return true;
	}
	catch (Throwable $e) {
		fwrite(STDERR, date('Y-m-d H:i:s').' '.$label.' failed: '.$e->getMessage()."\n");

		return false;
	}
}

function test_mail(array $config, string $address, bool $verbose): int {
	$mailer = null;

	try {
		$mailer = new Mailer($config['smtp']);
		$mailer->send([$address], 'Report Pack test message',
			"This is a test message from the Report Pack runner.\nIf you can read it, the mail settings work."
		);
		say('Test message sent to '.$address.'.');

		return EXIT_OK;
	}
	catch (Throwable $e) {
		fwrite(STDERR, 'Test message failed: '.$e->getMessage()."\n");

		return EXIT_FAILED;
	}
	finally {
		if ($verbose && $mailer !== null) {
			say(implode("\n", $mailer->log()));
		}
	}
}

function list_schedules(array $schedules, array $state, int $now): int {
	if (!$schedules) {
		say('No scheduled reports. Create them in Zabbix under Reports › Report Pack › Scheduled reports.');

		return EXIT_OK;
	}

	foreach ($schedules as $id => $schedule) {
		$last = isset($state[$id]) ? date('Y-m-d H:i', $state[$id]) : 'never';
		$next = $schedule['enabled'] ? date('Y-m-d H:i', Schedule::nextDue($schedule, $now)) : 'disabled';

		say(sprintf("%s  %-30s %-4s %-32s last: %s  next: %s  to: %s", $id, $schedule['name'],
			strtoupper($schedule['format']), Schedule::describe($schedule), $last, $next, $schedule['recipients']
		));
	}

	return EXIT_OK;
}

function find_schedule(array $schedules, string $key): ?array {
	if (array_key_exists($key, $schedules)) {
		return $schedules[$key];
	}

	foreach ($schedules as $schedule) {
		if (strcasecmp($schedule['name'], $key) === 0) {
			return $schedule;
		}
	}

	return null;
}

/**
 * The Report Pack module configuration from Zabbix: branding and schedules.
 */
function module_config(): array {
	$modules = ZabbixApi::current()->call('module.get', [
		'output' => ['moduleid', 'config', 'status'],
		'filter' => ['id' => 'reportpack']
	]);

	if (!$modules) {
		throw new RuntimeException('The Report Pack module is not installed in this Zabbix.');
	}

	$config = $modules[0]['config'];

	return is_array($config) ? $config : (json_decode((string) $config, true) ?: []);
}

/**
 * Severity names and colours as configured in Zabbix, or the Zabbix defaults.
 *
 * @return array  severity => [name, colour]
 */
function severities(): array {
	$severities = SEVERITY_DEFAULTS;

	try {
		$settings = ZabbixApi::current()->call('settings.get', ['output' => 'extend']);

		foreach (array_keys($severities) as $severity) {
			if (isset($settings['severity_name_'.$severity])) {
				$severities[$severity] = [$settings['severity_name_'.$severity], $settings['severity_color_'.$severity]];
			}
		}
	}
	catch (Throwable $e) {
		// Defaults are fine.
	}

	return $severities;
}

function find_font(string $configured): ?string {
	if ($configured !== '') {
		return is_readable($configured) ? $configured : null;
	}

	foreach (FONT_CANDIDATES as $candidate) {
		if (is_readable($candidate)) {
			return $candidate;
		}
	}

	return null;
}

function load_config(string $file): array {
	if (!is_readable($file)) {
		throw new RuntimeException('Cannot read '.$file);
	}

	$ini = parse_ini_file($file, true, INI_SCANNER_RAW);

	if ($ini === false) {
		throw new RuntimeException('Cannot parse '.$file);
	}

	$value = static function (string $section, string $key, string $default = '') use ($ini): string {
		$raw = $ini[$section][$key] ?? $default;

		return trim(trim((string) $raw), '"');
	};
	$flag = static function (string $section, string $key, bool $default) use ($value): bool {
		$raw = strtolower($value($section, $key, $default ? 'yes' : 'no'));

		return in_array($raw, ['1', 'yes', 'true', 'on'], true);
	};

	$config = [
		'zabbix' => [
			'url' => $value('zabbix', 'url'),
			'token' => $value('zabbix', 'token'),
			'verify_tls' => $flag('zabbix', 'verify_tls', true)
		],
		'smtp' => [
			'host' => $value('smtp', 'host'),
			'port' => (int) $value('smtp', 'port', '25'),
			'security' => strtolower($value('smtp', 'security', 'none')),
			'username' => $value('smtp', 'username'),
			'password' => $value('smtp', 'password'),
			'from' => $value('smtp', 'from'),
			'from_name' => $value('smtp', 'from_name', 'Zabbix reports'),
			'verify_tls' => $flag('smtp', 'verify_tls', true),
			'helo' => $value('smtp', 'helo')
		],
		'runner' => [
			'state_file' => $value('runner', 'state_file', sys_get_temp_dir().'/reportpack-state.json'),
			'font' => $value('runner', 'font'),
			'timezone' => $value('runner', 'timezone')
		]
	];

	if ($config['zabbix']['url'] === '' || $config['zabbix']['token'] === '') {
		throw new RuntimeException('[zabbix] url and token are required.');
	}

	if (!in_array($config['smtp']['security'], ['none', 'starttls', 'ssl'], true)) {
		throw new RuntimeException('[smtp] security must be none, starttls or ssl.');
	}

	if ($config['runner']['timezone'] !== '' && !in_array($config['runner']['timezone'], timezone_identifiers_list(), true)) {
		throw new RuntimeException('[runner] timezone is not a known time zone: '.$config['runner']['timezone']);
	}

	return $config;
}

function load_state(string $file): array {
	$data = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;

	return is_array($data) ? array_map('intval', $data) : [];
}

function save_state(string $file, array $state): void {
	$temporary = $file.'.tmp';

	if (file_put_contents($temporary, json_encode($state, JSON_PRETTY_PRINT)."\n") !== false) {
		rename($temporary, $file);
	}
}

function say(string $text): void {
	echo $text, "\n";
}
