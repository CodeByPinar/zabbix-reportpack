<?php

namespace Modules\ReportPack\Includes;

/**
 * A report as CSV for spreadsheets: plain numbers (dot decimal separator, durations in seconds) plus a
 * readable copy of each duration, UTF-8 with a byte order mark so Excel reads non-English names.
 */
class CsvReport {

	public static function render(array $report, array $options = []): string {
		$names = $options['severity_names'] ?? [];
		$severity = static function (int $value) use ($names): string {
			return $names[$value] ?? (string) $value;
		};
		$lines = [];
		$from = date('Y-m-d H:i:s', $report['period']['from']);
		$till = date('Y-m-d H:i:s', $report['period']['till']);
		$excluded = ($report['summary']['exclude_maintenance'] ?? false) ? 'yes' : 'no';

		if ($report['type'] === PdfReport::AVAILABILITY) {
			$lines[] = ['Host', 'Availability %', 'SLA target %', 'SLA met', 'Downtime (s)', 'Downtime', 'Outages',
				'Longest outage (s)', 'Problems', 'In maintenance (s)', 'Maintenance excluded', 'Period start',
				'Period end', 'Minimum severity'
			];

			foreach ($report['rows'] as $row) {
				$lines[] = [
					$row['host'],
					number_format($row['availability'], 3, '.', ''),
					number_format($report['sla'], 3, '.', ''),
					$row['sla_met'] ? 'yes' : 'no',
					$row['downtime'],
					PdfReport::duration($row['downtime']),
					$row['outages'],
					$row['longest'],
					$row['problems'],
					$row['maintenance'] ?? 0,
					$excluded,
					$from,
					$till,
					$severity($report['min_severity'])
				];
			}
		}
		else {
			$lines[] = ['Host', 'Problem', 'Severity', 'Times', 'Total duration (s)', 'Total duration', 'Last started',
				'Maintenance excluded', 'Period start', 'Period end'
			];

			foreach ($report['rows'] as $row) {
				$lines[] = [
					$row['host'],
					$row['problem'],
					$severity($row['severity']),
					$row['problems'],
					$row['duration'],
					PdfReport::duration($row['duration']),
					$row['last'] !== null ? date('Y-m-d H:i:s', $row['last']) : '',
					$excluded,
					$from,
					$till
				];
			}
		}

		$csv = '';

		foreach ($lines as $line) {
			$csv .= implode(',', array_map([self::class, 'field'], $line))."\r\n";
		}

		return "\xEF\xBB\xBF".$csv;
	}

	private static function field($value): string {
		$value = (string) $value;

		// A leading =, +, - or @ would be run as a formula by spreadsheet programs.
		if ($value !== '' && strpos('=+-@', $value[0]) !== false && !is_numeric($value)) {
			$value = "'".$value;
		}

		return '"'.str_replace('"', '""', $value).'"';
	}
}
