<?php

namespace Modules\ReportPack\Includes;

/**
 * Writes a small Excel workbook (.xlsx) without any library: sheets of rows with text, numbers,
 * percentages and durations, a bold header row, frozen header and column widths.
 *
 * An .xlsx file is a ZIP of XML parts; the ZIP is written here with zlib's raw deflate, so the PHP
 * zip extension is not needed.
 */
class Xlsx {

	// Cell formats, indexes into cellXfs in styles.xml.
	public const TEXT = 0;
	public const HEADER = 1;
	public const INTEGER = 2;
	public const PERCENT = 3;
	public const DURATION = 4;
	public const DATETIME = 5;
	public const DECIMAL = 6;

	private array $sheets = [];
	private string $title = '';
	private string $author = '';
	private string $accent = '0B3A5B';

	public function setInfo(string $title, string $author = ''): void {
		$this->title = $title;
		$this->author = $author;
	}

	/**
	 * Header row colour, as #RRGGBB.
	 */
	public function setAccent(string $hex): void {
		if (preg_match('/^#[0-9A-Fa-f]{6}$/', $hex)) {
			$this->accent = strtoupper(substr($hex, 1));
		}
	}

	/**
	 * @param string $name     Sheet name (Excel allows 31 characters, no []:*?/\).
	 * @param array  $columns  [['label', width in characters, format], ...]
	 * @param array  $rows     Rows of values in column order. Numbers stay numbers. Percentages are given
	 *                         as percent values (99.9), durations in seconds, date-times as Unix timestamps.
	 */
	public function addSheet(string $name, array $columns, array $rows): void {
		$name = mb_substr(preg_replace('/[\[\]:*?\/\\\\]/u', ' ', $name), 0, 31);
		$this->sheets[] = ['name' => $name !== '' ? $name : 'Sheet'.(count($this->sheets) + 1),
			'columns' => $columns, 'rows' => $rows
		];
	}

	public function output(): string {
		$files = [
			'[Content_Types].xml' => $this->contentTypes(),
			'_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
				.'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
				.'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
				.'</Relationships>',
			'docProps/core.xml' => $this->coreProperties(),
			'xl/workbook.xml' => $this->workbook(),
			'xl/_rels/workbook.xml.rels' => $this->workbookRels(),
			'xl/styles.xml' => self::styles($this->accent)
		];

		foreach ($this->sheets as $i => $sheet) {
			$files['xl/worksheets/sheet'.($i + 1).'.xml'] = self::sheet($sheet);
		}

		return self::zip($files);
	}

	// --- Parts ----------------------------------------------------------------------

	private function contentTypes(): string {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			.'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			.'<Default Extension="xml" ContentType="application/xml"/>'
			.'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			.'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			.'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';

		foreach (array_keys($this->sheets) as $i) {
			$xml .= '<Override PartName="/xl/worksheets/sheet'.($i + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}

		return $xml.'</Types>';
	}

	private function coreProperties(): string {
		$now = gmdate('Y-m-d\TH:i:s\Z');

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			.'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
			.'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
			.'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
			.'<dc:title>'.self::xml($this->title).'</dc:title>'
			.'<dc:creator>'.self::xml($this->author !== '' ? $this->author : 'Report Pack for Zabbix').'</dc:creator>'
			.'<dcterms:created xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:created>'
			.'<dcterms:modified xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:modified>'
			.'</cp:coreProperties>';
	}

	private function workbook(): string {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
			.'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';

		foreach ($this->sheets as $i => $sheet) {
			$xml .= '<sheet name="'.self::xml($sheet['name']).'" sheetId="'.($i + 1).'" r:id="rId'.($i + 1).'"/>';
		}

		return $xml.'</sheets></workbook>';
	}

	private function workbookRels(): string {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

		foreach (array_keys($this->sheets) as $i) {
			$xml .= '<Relationship Id="rId'.($i + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($i + 1).'.xml"/>';
		}

		$xml .= '<Relationship Id="rId'.(count($this->sheets) + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

		return $xml.'</Relationships>';
	}

	private static function styles(string $accent): string {
		// Durations are stored as fractions of a day and shown as [h]:mm:ss, so they add up in sums.
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			.'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			.'<numFmts count="4">'
			.'<numFmt numFmtId="164" formatCode="0.000&quot;%&quot;"/>'
			.'<numFmt numFmtId="165" formatCode="[h]:mm:ss"/>'
			.'<numFmt numFmtId="166" formatCode="yyyy-mm-dd hh:mm"/>'
			.'<numFmt numFmtId="167" formatCode="0.000"/>'
			.'</numFmts>'
			.'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
			.'<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
			.'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
			.'<fill><patternFill patternType="solid"><fgColor rgb="FF'.$accent.'"/><bgColor indexed="64"/></patternFill></fill></fills>'
			.'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
			.'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			.'<cellXfs count="7">'
			.'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
			.'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
			.'<xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
			.'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
			.'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
			.'<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
			.'<xf numFmtId="167" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
			.'</cellXfs>'
			.'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			.'</styleSheet>';
	}

	private static function sheet(array $sheet): string {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			.'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			.'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
			.'<cols>';

		foreach ($sheet['columns'] as $i => $column) {
			$xml .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.(float) $column[1].'" customWidth="1"/>';
		}

		$xml .= '</cols><sheetData><row r="1">';

		foreach ($sheet['columns'] as $i => $column) {
			$xml .= self::cell(self::ref($i, 1), $column[0], self::HEADER);
		}

		$xml .= '</row>';

		foreach ($sheet['rows'] as $r => $row) {
			$number = $r + 2;
			$xml .= '<row r="'.$number.'">';

			foreach (array_values($row) as $i => $value) {
				$format = $sheet['columns'][$i][2] ?? self::TEXT;
				$xml .= self::cell(self::ref($i, $number), $value, $format);
			}

			$xml .= '</row>';
		}

		$last = self::ref(max(0, count($sheet['columns']) - 1), max(1, count($sheet['rows']) + 1));

		return $xml.'</sheetData><autoFilter ref="A1:'.$last.'"/></worksheet>';
	}

	private static function cell(string $ref, $value, int $format): string {
		if ($value === null || $value === '') {
			return '';
		}

		if ($format === self::HEADER || $format === self::TEXT || !is_numeric($value)) {
			return '<c r="'.$ref.'" t="inlineStr"'.($format === self::HEADER ? ' s="1"' : '').'><is><t xml:space="preserve">'
				.self::xml((string) $value).'</t></is></c>';
		}

		$number = (float) $value;

		if ($format === self::DURATION) {
			$number /= 86400;
		}
		elseif ($format === self::DATETIME) {
			// Excel counts days from 1899-12-30; shift the timestamp to local time first.
			$number = ($number + (int) date('Z', (int) $value)) / 86400 + 25569;
		}

		return '<c r="'.$ref.'" s="'.$format.'"><v>'.self::number($number).'</v></c>';
	}

	private static function ref(int $column, int $row): string {
		$letters = '';

		for ($n = $column + 1; $n > 0; $n = intdiv($n - 1, 26)) {
			$letters = chr(65 + ($n - 1) % 26).$letters;
		}

		return $letters.$row;
	}

	private static function number(float $value): string {
		$text = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');

		return $text === '' || $text === '-0' ? '0' : $text;
	}

	private static function xml(string $text): string {
		// Remove control characters XML does not allow.
		$text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);

		return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
	}

	// --- ZIP ------------------------------------------------------------------------

	private static function zip(array $files): string {
		$data = '';
		$directory = '';
		$time = self::dosTime(time());

		foreach ($files as $name => $content) {
			$compressed = gzdeflate($content, 6);
			$crc = crc32($content);
			$offset = strlen($data);

			$header = pack('VvvvVVVVvv', 0x04034B50, 20, 0x0800, 8, $time, $crc, strlen($compressed),
				strlen($content), strlen($name), 0
			);
			$data .= $header.$name.$compressed;

			$directory .= pack('VvvvvVVVVvvvvvVV', 0x02014B50, 20, 20, 0x0800, 8, $time, $crc,
				strlen($compressed), strlen($content), strlen($name), 0, 0, 0, 0, 0, $offset
			).$name;
		}

		return $data.$directory.pack('VvvvvVVv', 0x06054B50, 0, 0, count($files), count($files), strlen($directory),
			strlen($data), 0
		);
	}

	private static function dosTime(int $timestamp): int {
		$t = getdate($timestamp);

		return (($t['year'] - 1980) << 25) | ($t['mon'] << 21) | ($t['mday'] << 16)
			| ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
	}
}
