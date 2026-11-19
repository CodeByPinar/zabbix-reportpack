<?php

namespace Modules\ReportPack\Includes;

/**
 * A report as an Excel workbook: the report table, a summary sheet and, for availability, one row per day.
 *
 * Plain arrays in, bytes out, so it runs in the frontend, in the scheduled runner and in tests.
 */
class XlsxReport {

	public static function render(array $report, array $branding, array $options): string {
		$options += ['prepared_for' => '', 'severity_names' => []];
		$prepared_for = trim($options['prepared_for']) !== '' ? trim($options['prepared_for']) : $report['group'];
		$title = $report['type'] === PdfReport::AVAILABILITY ? 'Availability report' : 'Most frequent problems';

		$xlsx = new Xlsx();
		$xlsx->setInfo($title.' – '.$prepared_for, $branding['company'] ?? '');
		$xlsx->setAccent($branding['accent'] ?? '');

		if ($report['type'] === PdfReport::AVAILABILITY) {
			$rows = [];

			foreach ($report['rows'] as $row) {
				$rows[] = [
					$row['host'],
					$row['availability'],
					$row['sla_met'] ? 'Met' : 'Missed',
					$row['downtime'],
					$row['outages'],
					$row['longest'],
					$row['problems'],
					$row['maintenance'] ?? 0
				];
			}

			$xlsx->addSheet('Availability', [
				['Host', 36, Xlsx::TEXT],
				['Availability %', 15, Xlsx::PERCENT],
				['SLA', 9, Xlsx::TEXT],
				['Downtime', 12, Xlsx::DURATION],
				['Outages', 10, Xlsx::INTEGER],
				['Longest outage', 15, Xlsx::DURATION],
				['Problems', 10, Xlsx::INTEGER],
				['In maintenance', 15, Xlsx::DURATION]
			], $rows);

			$daily = [];

			foreach ($report['daily'] ?? [] as $day) {
				$daily[] = [date('Y-m-d', $day['day']), $day['availability'], $day['downtime']];
			}

			if ($daily) {
				$xlsx->addSheet('By day', [
					['Day', 12, Xlsx::TEXT],
					['Average availability %', 22, Xlsx::PERCENT],
					['Downtime, all hosts', 20, Xlsx::DURATION]
				], $daily);
			}

			$summary = $report['summary'];
			$facts = [
				['Average availability %', $summary['average'], Xlsx::PERCENT],
				['SLA target %', $report['sla'], Xlsx::PERCENT],
				['Hosts', $summary['hosts'], Xlsx::INTEGER],
				['Hosts meeting the SLA target', $summary['sla_met'], Xlsx::INTEGER],
				['Hosts missing the SLA target', $summary['sla_missed'], Xlsx::INTEGER],
				['Total downtime, all hosts', $summary['downtime'], Xlsx::DURATION],
				['Allowed downtime per host', $summary['allowed_downtime'], Xlsx::DURATION],
				['Maintenance windows', $summary['exclude_maintenance'] ? 'Excluded' : 'Counted as normal time', Xlsx::TEXT]
			];
		}
		else {
			$rows = [];

			foreach ($report['rows'] as $row) {
				$rows[] = [
					$row['host'],
					$row['problem'],
					$options['severity_names'][$row['severity']] ?? (string) $row['severity'],
					$row['problems'],
					$row['duration'],
					$row['last']
				];
			}

			$xlsx->addSheet('Problems', [
				['Host', 30, Xlsx::TEXT],
				['Problem', 60, Xlsx::TEXT],
				['Severity', 14, Xlsx::TEXT],
				['Times', 9, Xlsx::INTEGER],
				['Total duration', 15, Xlsx::DURATION],
				['Last started', 18, Xlsx::DATETIME]
			], $rows);

			$summary = $report['summary'];
			$facts = [
				['Problems started in the period', $summary['problems'], Xlsx::INTEGER],
				['Triggers that had problems', $summary['with_problems'], Xlsx::INTEGER],
				['Triggers listed', $summary['shown'], Xlsx::INTEGER],
				['Maintenance windows', $summary['exclude_maintenance'] ? 'Excluded' : 'Counted as normal time', Xlsx::TEXT]
			];
		}

		$about = [
			['Report', $title],
			['Prepared for', $prepared_for],
			['Prepared by', $branding['company'] ?? ''],
			['Host group', $report['group']],
			['Period', $report['period']['label']],
			['Period start', date('Y-m-d H:i', $report['period']['from'])],
			['Period end', date('Y-m-d H:i', $report['period']['till'])],
			['Minimum severity', $options['severity_names'][$report['min_severity']] ?? (string) $report['min_severity']],
			['Generated', date('Y-m-d H:i', $report['generated'])]
		];

		// The summary sheet mixes formats per row, so values are written as text where needed.
		$summary_rows = [];

		foreach ($facts as [$label, $value, $format]) {
			$summary_rows[] = [$label, self::formatted($value, $format)];
		}

		foreach ($about as [$label, $value]) {
			$summary_rows[] = [$label, $value];
		}

		$xlsx->addSheet('Summary', [['Item', 34, Xlsx::TEXT], ['Value', 40, Xlsx::TEXT]], $summary_rows);

		return $xlsx->output();
	}

	private static function formatted($value, int $format): string {
		switch ($format) {
			case Xlsx::PERCENT:
				return sprintf('%.3f%%', $value);

			case Xlsx::DURATION:
				return PdfReport::duration((int) $value);

			default:
				return (string) $value;
		}
	}
}
