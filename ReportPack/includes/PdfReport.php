<?php

namespace Modules\ReportPack\Includes;

/**
 * Lays out a report as a branded A4 PDF: header with logo and company name, summary tiles, a table
 * that continues over as many pages as needed, and a footer with page numbers.
 *
 * Takes plain arrays only, so it can be tested without Zabbix.
 */
class PdfReport {

	public const AVAILABILITY = 'availability';
	public const TOP_PROBLEMS = 'top_problems';

	private const MARGIN = 40;
	private const BOTTOM = 58;
	private const ROW = 18;
	private const HEAD_ROW = 26;
	private const LINE = 10.5;

	private const INK = [30, 41, 59];
	private const MUTED = [100, 116, 139];
	private const RULE = [226, 232, 240];
	private const TILE = [243, 245, 248];
	private const ZEBRA = [248, 250, 252];
	private const RED = [196, 43, 43];
	private const GREEN = [30, 125, 70];
	private const WHITE = [255, 255, 255];

	private Pdf $pdf;
	private array $report;
	private array $branding;
	private array $options;
	private array $accent;
	private float $y = 0;

	/**
	 * @param array       $report    Report as built by ReportData plus 'type', 'group', 'period', 'sla',
	 *                               'min_severity' and 'generated'.
	 * @param array       $branding  ['company', 'accent', 'footer'].
	 * @param string|null $logo      Logo image bytes.
	 * @param array       $fonts     ['regular' => path, 'bold' => path|null].
	 * @param array       $options   'prepared_for', 'severity_names' (int => name), 'severity_colors' (int => #hex).
	 */
	public static function render(array $report, array $branding, ?string $logo, array $fonts, array $options): string {
		return (new self($report, $branding, $fonts, $options))->build($logo);
	}

	private function __construct(array $report, array $branding, array $fonts, array $options) {
		$this->report = $report;
		$this->branding = $branding;
		$this->options = $options + ['prepared_for' => '', 'severity_names' => [], 'severity_colors' => []];
		$this->accent = Branding::isColour($branding['accent'] ?? '')
			? Branding::rgb($branding['accent'])
			: Branding::rgb(Branding::DEFAULT_ACCENT);

		$this->pdf = new Pdf();
		$this->pdf->addFont('regular', $fonts['regular']);

		if ($fonts['bold'] !== null) {
			$this->pdf->addFont('bold', $fonts['bold']);
		}
		else {
			$this->pdf->addFont('bold', $fonts['regular'], true);
		}
	}

	private function build(?string $logo): string {
		$title = $this->title();

		$this->pdf->setInfo($title.' – '.$this->preparedFor(), $this->branding['company'] ?? '',
			$this->report['period']['label']
		);

		$this->pdf->addPage();
		$this->firstPageHeader($title, $logo);
		$this->tiles();

		if ($this->report['type'] === self::AVAILABILITY) {
			$this->dailyChart();
			$this->availabilityTable();
			$this->note('Availability is the share of the period in which the host had no problem of the minimum '
				.'severity or higher. Overlapping problems on one host are counted once. '
				.(($this->report['summary']['exclude_maintenance'] ?? false)
					? 'Maintenance windows are excluded from both the downtime and the period.'
					: 'Maintenance windows are counted as normal time.')
			);
		}
		else {
			$this->topProblemsTable();
			$this->note('Times counts how often the problem started in the period. Total duration is the time the '
				.'trigger spent in problem state within the period. '
				.(($this->report['summary']['exclude_maintenance'] ?? false)
					? 'Problems and problem time inside maintenance windows are left out.'
					: 'Maintenance windows are counted as normal time.')
			);
		}

		$this->footers();

		return $this->pdf->output();
	}

	// --- Page parts -----------------------------------------------------------------

	private function firstPageHeader(string $title, ?string $logo): void {
		$pdf = $this->pdf;
		$right = $pdf->width() - self::MARGIN;

		$pdf->rect(0, 0, $pdf->width(), 8, $this->accent);

		$logo_bottom = 36;

		if ($logo !== null && ($image = $pdf->addImage($logo)) !== null) {
			// Fit into 160 × 44 points, keeping the proportions; a small logo is enlarged at most 1.5 times.
			$scale = min(160 / $image['width'], 44 / $image['height'], 1.5);
			$w = $image['width'] * $scale;
			$h = $image['height'] * $scale;
			$pdf->image($image['key'], self::MARGIN, 36, $w, $h);
			$logo_bottom = 36 + $h;
		}

		$company = trim($this->branding['company'] ?? '');

		if ($company !== '') {
			$pdf->text($right, 40, $pdf->fit($company, 'bold', 12, 250), 'bold', 12, self::INK, 'right');
		}

		$y = max(96, $logo_bottom + 22);

		$pdf->text(self::MARGIN, $y, $title, 'bold', 20, self::INK);
		$y += 32;

		$prepared = 'Prepared for '.$this->preparedFor();
		$pdf->text(self::MARGIN, $y, $pdf->fit($prepared, 'bold', 11, $right - self::MARGIN), 'bold', 11, $this->accent);
		$y += 18;

		$period = $this->report['period'];
		$pdf->text(self::MARGIN, $y, $period['label'].'  ·  '.date('Y-m-d H:i', $period['from']).' – '
			.date('Y-m-d H:i', $period['till']), 'regular', 9.5, self::MUTED
		);
		$y += 14;

		$details = 'Host group: '.$this->report['group'].'  ·  Minimum severity: '
			.$this->severityName($this->report['min_severity']).'  ·  Generated '
			.date('Y-m-d H:i', $this->report['generated']);
		$pdf->text(self::MARGIN, $y, $pdf->fit($details, 'regular', 9.5, $right - self::MARGIN), 'regular', 9.5,
			self::MUTED
		);
		$y += 22;

		$pdf->line(self::MARGIN, $y, $right, $y, self::RULE, 0.8);
		$this->y = $y + 16;
	}

	private function continuationHeader(): void {
		$pdf = $this->pdf;

		$pdf->rect(0, 0, $pdf->width(), 4, $this->accent);
		$pdf->text(self::MARGIN, 26, $this->title().' (continued)', 'bold', 10, self::INK);
		$pdf->text($pdf->width() - self::MARGIN, 26,
			$pdf->fit($this->preparedFor().'  ·  '.$this->report['period']['label'], 'regular', 9, 300), 'regular', 9,
			self::MUTED, 'right'
		);
		$this->y = 50;
	}

	private function tiles(): void {
		$summary = $this->report['summary'];

		if ($this->report['type'] === self::AVAILABILITY) {
			$sla = self::percent($this->report['sla']);
			$tiles = [
				[sprintf('%.3f%%', $summary['average']), 'Average availability', null],
				[$summary['sla_met'].' / '.$summary['hosts'], 'Hosts meeting the SLA target of '.$sla,
					$summary['sla_missed'] > 0 ? self::RED : self::GREEN
				],
				[self::duration($summary['downtime']), 'Total downtime, all hosts', null],
				[self::duration($summary['allowed_downtime']), 'Allowed downtime per host at '.$sla, null]
			];
		}
		else {
			$total = array_sum(array_column($this->report['rows'], 'duration'));
			$tiles = [
				[(string) $summary['problems'], 'Problems started in the period', null],
				[(string) $summary['with_problems'], 'Triggers that had problems', null],
				[self::duration($total), 'Time in problem state, listed triggers', null],
				[(string) $summary['shown'], 'Triggers listed below', null]
			];
		}

		$pdf = $this->pdf;
		$gap = 10;
		$width = ($pdf->width() - 2 * self::MARGIN - 3 * $gap) / 4;
		$height = 60;

		foreach ($tiles as $i => [$value, $label, $colour]) {
			$x = self::MARGIN + $i * ($width + $gap);

			$pdf->rect($x, $this->y, $width, $height, self::TILE);
			$pdf->rect($x, $this->y, $width, 3, $this->accent);
			$pdf->text($x + 10, $this->y + 13, $pdf->fit($value, 'bold', 16, $width - 20), 'bold', 16,
				$colour ?? self::INK
			);

			$line_y = $this->y + 37;

			foreach (array_slice($pdf->wrap($label, 'regular', 7.8, $width - 20), 0, 2) as $line) {
				$pdf->text($x + 10, $line_y, $line, 'regular', 7.8, self::MUTED);
				$line_y += 9.5;
			}
		}

		$this->y += $height + 22;
	}

	/**
	 * Bar chart of the group's average availability per day, with the SLA target as a dashed line.
	 */
	private function dailyChart(): void {
		$days = $this->report['daily'] ?? [];

		if (count($days) < 2) {
			return;
		}

		$pdf = $this->pdf;
		$sla = (float) $this->report['sla'];
		$x0 = self::MARGIN + 38;
		// Right gutter for the SLA label.
		$width = $pdf->width() - self::MARGIN - $x0 - 52;
		$height = 74;

		$pdf->text(self::MARGIN, $this->y, 'Average availability by day', 'bold', 9.5, self::INK);
		$top = $this->y + 18;

		// Scale: from a little below the lowest value (or the SLA target) up to 100 %.
		$values = array_column($days, 'availability');
		$low = min(min($values), $sla);
		$low = max(0.0, $low - max((100 - $low) * 0.25, 0.005));
		$span = 100 - $low;
		$y_of = static function (float $value) use ($top, $height, $low, $span): float {
			return $top + $height * (1 - (max($low, $value) - $low) / $span);
		};

		$pdf->line($x0, $top + $height, $x0 + $width, $top + $height, self::RULE, 0.6);
		$pdf->line($x0, $top, $x0 + $width, $top, self::RULE, 0.4);
		$pdf->text($x0 - 4, $top - 4, '100%', 'regular', 7, self::MUTED, 'right');
		$pdf->text($x0 - 4, $top + $height - 4, self::percent($low), 'regular', 7, self::MUTED, 'right');

		$slot = $width / count($days);
		$bar = max(1.0, min($slot * 0.7, 14));
		$label_every = (int) ceil(count($days) / 16);

		foreach ($days as $i => $day) {
			$x = $x0 + $i * $slot + ($slot - $bar) / 2;
			$y = $y_of($day['availability']);
			// Days that met the target are drawn light, so the days that did not stand out.
			$colour = Availability::meets($day['availability'], $sla) ? [168, 214, 186] : self::RED;

			// A day at exactly the bottom of the scale still shows a thin bar.
			$pdf->rect($x, $y, $bar, max(0.8, $top + $height - $y), $colour);

			if ($i % $label_every === 0) {
				$pdf->text($x + $bar / 2, $top + $height + 4, date('j', $day['day']), 'regular', 6.8, self::MUTED,
					'center'
				);
			}
		}

		$sla_y = $y_of($sla);

		if ($sla > $low && $sla < 100) {
			$pdf->line($x0, $sla_y, $x0 + $width + 4, $sla_y, $this->accent, 0.8, [3, 2]);
			$pdf->text($x0 + $width + 8, $sla_y - 4.5, 'SLA '.self::percent($sla), 'bold', 7, $this->accent);
		}

		$this->y = $top + $height + 26;
	}

	private function availabilityTable(): void {
		$columns = [
			['Host', 0.31, 'left', true],
			['Availability', 0.13, 'right'],
			['SLA', 0.09, 'left'],
			['Downtime', 0.13, 'right'],
			['Outages', 0.10, 'right'],
			['Longest outage', 0.13, 'right'],
			['Problems', 0.11, 'right']
		];

		$rows = [];

		foreach ($this->report['rows'] as $row) {
			$colour = $row['sla_met'] ? self::GREEN : self::RED;
			$rows[] = [
				[$row['host']],
				[sprintf('%.3f%%', $row['availability']), $colour],
				[$row['sla_met'] ? 'Met' : 'Missed', $colour, 'bold'],
				[$row['downtime'] > 0 ? self::duration($row['downtime']) : '–'],
				[(string) $row['outages']],
				[$row['longest'] > 0 ? self::duration($row['longest']) : '–'],
				[(string) $row['problems']]
			];
		}

		$this->table($columns, $rows);
	}

	private function topProblemsTable(): void {
		$columns = [
			['Host', 0.22, 'left', true],
			['Problem', 0.23, 'left', true],
			['Severity', 0.15, 'left'],
			['Times', 0.08, 'right'],
			['Total duration', 0.12, 'right'],
			['Last started', 0.20, 'right']
		];

		$rows = [];

		foreach ($this->report['rows'] as $row) {
			$rows[] = [
				[$row['host']],
				[$row['problem']],
				[$this->severityName($row['severity']), null, 'regular', $this->severityColour($row['severity'])],
				[(string) $row['problems']],
				[$row['duration'] > 0 ? self::duration($row['duration']) : '–'],
				[$row['last'] !== null ? date('Y-m-d H:i', $row['last']) : '–']
			];
		}

		$this->table($columns, $rows);
	}

	/**
	 * @param array $columns  [[label, share of width, align, wraps], ...]; text in a column that wraps
	 *                        may take two lines, the row grows to fit.
	 * @param array $rows     Cells: [text, colour|null, font|null, marker colour|null]
	 */
	private function table(array $columns, array $rows): void {
		$pdf = $this->pdf;
		$x0 = self::MARGIN;
		$total = $pdf->width() - 2 * self::MARGIN;
		$widths = array_map(static function (array $column) use ($total): float {
			return $column[1] * $total;
		}, $columns);

		$head = function () use ($pdf, $columns, $widths, $x0, $total): void {
			$pdf->rect($x0, $this->y, $total, self::HEAD_ROW, $this->accent);
			$x = $x0;

			foreach ($columns as $i => [$label, , $align]) {
				// Up to two lines, vertically centred, so labels are never cut.
				$lines = array_slice($pdf->wrap($label, 'bold', 7.8, $widths[$i] - 12), 0, 2);
				$line_y = $this->y + (self::HEAD_ROW - 9.5 * count($lines)) / 2 + 0.5;

				foreach ($lines as $line) {
					$pdf->text($align === 'right' ? $x + $widths[$i] - 6 : $x + 6, $line_y,
						$pdf->fit($line, 'bold', 7.8, $widths[$i] - 12), 'bold', 7.8, self::WHITE, $align
					);
					$line_y += 9.5;
				}

				$x += $widths[$i];
			}

			$this->y += self::HEAD_ROW;
		};

		// Keep the header together with at least two rows.
		if ($this->y + self::HEAD_ROW + 2 * self::ROW > $pdf->height() - self::BOTTOM) {
			$pdf->addPage();
			$this->continuationHeader();
		}

		$head();

		if (!$rows) {
			$pdf->text($x0 + 6, $this->y + 6, 'No data for this period.', 'regular', 8.8, self::MUTED);
			$this->y += self::ROW;
		}

		foreach ($rows as $n => $cells) {
			// Lines of every cell first: the tallest cell sets the row height.
			$cell_lines = [];

			foreach ($cells as $i => $cell) {
				[$text, , $font, $marker] = $cell + [null, null, 'regular', null];
				$font = $font ?? 'regular';
				$room = $widths[$i] - 12 - ($marker !== null ? 10 : 0);

				if (!empty($columns[$i][3]) && $pdf->textWidth($text, $font, 8.6) > $room) {
					$lines = $pdf->wrap($text, $font, 8.6, $room);

					if (count($lines) > 2) {
						$lines = [$lines[0], $pdf->fit(implode(' ', array_slice($lines, 1)).' …', $font, 8.6, $room)];
					}

					$cell_lines[$i] = $lines;
				}
				else {
					$cell_lines[$i] = [$pdf->fit($text, $font, 8.6, $room)];
				}
			}

			$extra_lines = max(array_map('count', $cell_lines)) - 1;
			$height = self::ROW + $extra_lines * self::LINE + ($extra_lines > 0 ? 3 : 0);

			if ($this->y + $height > $pdf->height() - self::BOTTOM) {
				$pdf->addPage();
				$this->continuationHeader();
				$head();
			}

			if ($n % 2 === 1) {
				$pdf->rect($x0, $this->y, $total, $height, self::ZEBRA);
			}

			$x = $x0;

			foreach ($cells as $i => $cell) {
				[, $colour, $font, $marker] = $cell + [null, null, 'regular', null];
				$font = $font ?? 'regular';
				$left = $x + 6;

				if ($marker !== null) {
					$pdf->rect($left, $this->y + 6, 6, 6, $marker);
					$left += 10;
				}

				$line_y = $this->y + 5;

				foreach ($cell_lines[$i] as $line) {
					$pdf->text($columns[$i][2] === 'right' ? $x + $widths[$i] - 6 : $left, $line_y, $line, $font, 8.6,
						$colour ?? self::INK, $columns[$i][2]
					);
					$line_y += self::LINE;
				}

				$x += $widths[$i];
			}

			$this->y += $height;
			$pdf->line($x0, $this->y, $x0 + $total, $this->y, self::RULE, 0.4);
		}

		$this->y += 12;
	}

	private function note(string $text): void {
		$pdf = $this->pdf;
		$lines = $pdf->wrap($text, 'regular', 7.8, $pdf->width() - 2 * self::MARGIN);

		if ($this->y + count($lines) * 10 > $pdf->height() - self::BOTTOM) {
			$pdf->addPage();
			$this->continuationHeader();
		}

		foreach ($lines as $line) {
			$pdf->text(self::MARGIN, $this->y, $line, 'regular', 7.8, self::MUTED);
			$this->y += 10;
		}
	}

	private function footers(): void {
		$pdf = $this->pdf;
		$pages = $pdf->pageCount();
		$left_text = trim($this->branding['footer'] ?? '') !== ''
			? trim($this->branding['footer'])
			: trim($this->branding['company'] ?? '');
		$y = $pdf->height() - 40;

		for ($page = 0; $page < $pages; $page++) {
			$pdf->setPage($page);
			$pdf->line(self::MARGIN, $y, $pdf->width() - self::MARGIN, $y, self::RULE, 0.6);

			if ($left_text !== '') {
				$pdf->text(self::MARGIN, $y + 7, $pdf->fit($left_text, 'regular', 7.5, 380), 'regular', 7.5, self::MUTED);
			}

			$pdf->text($pdf->width() - self::MARGIN, $y + 7, 'Page '.($page + 1).' of '.$pages, 'regular', 7.5,
				self::MUTED, 'right'
			);
		}
	}

	// --- Helpers --------------------------------------------------------------------

	private function title(): string {
		return $this->report['type'] === self::AVAILABILITY ? 'Availability report' : 'Most frequent problems';
	}

	private function preparedFor(): string {
		$name = trim($this->options['prepared_for']);

		return $name !== '' ? $name : $this->report['group'];
	}

	private function severityName(int $severity): string {
		return $this->options['severity_names'][$severity] ?? (string) $severity;
	}

	private function severityColour(int $severity): ?array {
		$hex = $this->options['severity_colors'][$severity] ?? null;

		if ($hex === null) {
			return null;
		}

		$hex = '#'.ltrim($hex, '#');

		return Branding::isColour($hex) ? Branding::rgb($hex) : null;
	}

	/**
	 * Readable duration with at most two units: "2d 4h", "3h 12m", "1m 47s", "37s".
	 */
	public static function duration(int $seconds): string {
		if ($seconds <= 0) {
			return '0';
		}

		$units = ['d' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
		$parts = [];

		foreach ($units as $unit => $size) {
			if ($seconds >= $size) {
				$parts[] = intdiv($seconds, $size).$unit;
				$seconds %= $size;
			}
			elseif ($parts) {
				// Keep units adjacent: "1h 0m", not "1h 5s".
				$parts[] = '0'.$unit;
			}

			if (count($parts) === 2) {
				break;
			}
		}

		return implode(' ', $parts);
	}

	private static function percent(float $value): string {
		return rtrim(rtrim(sprintf('%.3f', $value), '0'), '.').'%';
	}
}
