<?php

namespace Modules\ReportPack\Includes;

use RuntimeException;

/**
 * Reads a TrueType font file and writes a subset of it for embedding in a PDF.
 *
 * Only what a PDF writer needs: metrics, the Unicode → glyph map, advance widths and a subset
 * that keeps glyph numbers unchanged (unused glyphs are emptied, not removed), so text can be
 * written as glyph numbers with the Identity mapping.
 */
class TrueTypeFont {

	public string $name;
	public int $units_per_em;
	public array $bbox;
	public int $ascent;
	public int $descent;
	public int $cap_height;
	public float $italic_angle;

	private string $data;
	private array $tables = [];
	private int $num_glyphs;
	private array $advance_widths = [];
	private array $cmap = [];
	private bool $long_loca;

	public function __construct(string $path) {
		$data = @file_get_contents($path);

		if ($data === false || strlen($data) < 12) {
			throw new RuntimeException('Cannot read font file '.$path);
		}

		$this->data = $data;
		$this->readTableDirectory();

		foreach (['head', 'hhea', 'maxp', 'hmtx', 'cmap', 'loca', 'glyf'] as $tag) {
			if (!array_key_exists($tag, $this->tables)) {
				throw new RuntimeException('Not a usable TrueType font (no '.$tag.' table): '.$path);
			}
		}

		$this->readHead();
		$this->readHheaAndHmtx();
		$this->num_glyphs = $this->u16($this->tables['maxp']['offset'] + 4);
		$this->readCmap();
		$this->readOs2AndPost();
		$this->name = self::postScriptName(pathinfo($path, PATHINFO_FILENAME));
	}

	/**
	 * Glyph number for a Unicode code point; 0 (the "missing" glyph) when the font lacks it.
	 */
	public function glyph(int $code_point): int {
		return $this->cmap[$code_point] ?? 0;
	}

	/**
	 * Advance width of a glyph in font units.
	 */
	public function advance(int $glyph): int {
		return $this->advance_widths[$glyph] ?? end($this->advance_widths);
	}

	/**
	 * A copy of the font that contains only the given glyphs (plus glyph 0 and the parts of composite
	 * glyphs). Glyph numbers stay the same.
	 */
	public function subset(array $glyphs): string {
		$keep = [0 => true];

		foreach ($glyphs as $glyph) {
			if ($glyph >= 0 && $glyph < $this->num_glyphs) {
				$keep[$glyph] = true;
			}
		}

		$offsets = $this->locaOffsets();
		$glyf_start = $this->tables['glyf']['offset'];

		// Composite glyphs are drawn from other glyphs; those must stay too.
		$queue = array_keys($keep);

		while ($queue) {
			$glyph = array_pop($queue);

			foreach ($this->componentGlyphs($glyf_start + $offsets[$glyph], $offsets[$glyph + 1] - $offsets[$glyph])
					as $component) {
				if (!array_key_exists($component, $keep) && $component < $this->num_glyphs) {
					$keep[$component] = true;
					$queue[] = $component;
				}
			}
		}

		$glyf = '';
		$loca = '';

		for ($glyph = 0; $glyph < $this->num_glyphs; $glyph++) {
			$loca .= pack('N', strlen($glyf));

			if (array_key_exists($glyph, $keep)) {
				$length = $offsets[$glyph + 1] - $offsets[$glyph];
				$glyf .= substr($this->data, $glyf_start + $offsets[$glyph], $length);

				// Glyph data is kept 4-byte aligned.
				$glyf .= str_repeat("\0", (4 - strlen($glyf) % 4) % 4);
			}
		}

		$loca .= pack('N', strlen($glyf));

		// The new loca table uses 32-bit offsets.
		$head = $this->table('head');
		$head = substr_replace($head, pack('n', 1), 50, 2);
		// The whole-file checksum adjustment is recalculated below.
		$head = substr_replace($head, "\0\0\0\0", 8, 4);

		$tables = [
			'glyf' => $glyf,
			'head' => $head,
			'hhea' => $this->table('hhea'),
			'hmtx' => $this->table('hmtx'),
			'loca' => $loca,
			'maxp' => $this->table('maxp')
		];

		// Hinting programs are needed by the glyphs' instructions.
		foreach (['cvt ', 'fpgm', 'prep'] as $tag) {
			if (array_key_exists($tag, $this->tables)) {
				$tables[$tag] = $this->table($tag);
			}
		}

		ksort($tables, SORT_STRING);

		$font = self::buildFont($tables);

		// head.checkSumAdjustment = 0xB1B0AFBA - checksum of the whole font.
		$adjustment = (0xB1B0AFBA - self::checksum($font)) & 0xFFFFFFFF;
		$head_offset = self::tableOffset($font, 'head');

		return substr_replace($font, pack('N', $adjustment), $head_offset + 8, 4);
	}

	// --- Reading ---------------------------------------------------------------------

	private function readTableDirectory(): void {
		$version = substr($this->data, 0, 4);

		if ($version !== "\x00\x01\x00\x00" && $version !== 'true') {
			throw new RuntimeException('Only TrueType outline fonts are supported.');
		}

		$count = $this->u16(4);

		for ($i = 0; $i < $count; $i++) {
			$record = 12 + 16 * $i;
			$tag = substr($this->data, $record, 4);
			$this->tables[$tag] = [
				'offset' => $this->u32($record + 8),
				'length' => $this->u32($record + 12)
			];
		}
	}

	private function readHead(): void {
		$head = $this->tables['head']['offset'];

		$this->units_per_em = $this->u16($head + 18);
		$this->bbox = [$this->i16($head + 36), $this->i16($head + 38), $this->i16($head + 40), $this->i16($head + 42)];
		$this->long_loca = $this->i16($head + 50) === 1;
	}

	private function readHheaAndHmtx(): void {
		$hhea = $this->tables['hhea']['offset'];

		$this->ascent = $this->i16($hhea + 4);
		$this->descent = $this->i16($hhea + 6);
		$metrics = $this->u16($hhea + 34);

		$hmtx = $this->tables['hmtx']['offset'];

		for ($i = 0; $i < $metrics; $i++) {
			$this->advance_widths[$i] = $this->u16($hmtx + 4 * $i);
		}
	}

	private function readOs2AndPost(): void {
		$this->cap_height = (int) round($this->ascent * 0.7);

		if (array_key_exists('OS/2', $this->tables)) {
			$os2 = $this->tables['OS/2']['offset'];

			if ($this->u16($os2) >= 2 && $this->tables['OS/2']['length'] >= 90) {
				$this->cap_height = $this->i16($os2 + 88);
			}
		}

		$this->italic_angle = 0.0;

		if (array_key_exists('post', $this->tables)) {
			$post = $this->tables['post']['offset'];
			$this->italic_angle = $this->i16($post + 4) + $this->u16($post + 6) / 65536;
		}
	}

	private function readCmap(): void {
		$cmap = $this->tables['cmap']['offset'];
		$count = $this->u16($cmap + 2);
		$candidates = [];

		for ($i = 0; $i < $count; $i++) {
			$record = $cmap + 4 + 8 * $i;
			$platform = $this->u16($record);
			$encoding = $this->u16($record + 2);
			$offset = $cmap + $this->u32($record + 4);
			$format = $this->u16($offset);

			if ($format === 12 && ($platform === 3 && $encoding === 10 || $platform === 0)) {
				$candidates[0] = [$format, $offset];
			}
			elseif ($format === 4 && ($platform === 3 && $encoding === 1 || $platform === 0)) {
				$candidates[1] = [$format, $offset];
			}
		}

		if (!$candidates) {
			throw new RuntimeException('The font has no Unicode character map.');
		}

		ksort($candidates);
		[$format, $offset] = reset($candidates);

		if ($format === 12) {
			$groups = $this->u32($offset + 12);

			for ($i = 0; $i < $groups; $i++) {
				$group = $offset + 16 + 12 * $i;
				$start = $this->u32($group);
				$end = min($this->u32($group + 4), 0x10FFFF);
				$glyph = $this->u32($group + 8);

				for ($code = $start; $code <= $end; $code++) {
					$this->cmap[$code] = $glyph + $code - $start;
				}
			}

			return;
		}

		$segments = $this->u16($offset + 6) / 2;
		$ends = $offset + 14;
		$starts = $ends + 2 * $segments + 2;
		$deltas = $starts + 2 * $segments;
		$range_offsets = $deltas + 2 * $segments;

		for ($s = 0; $s < $segments; $s++) {
			$end = $this->u16($ends + 2 * $s);
			$start = $this->u16($starts + 2 * $s);
			$delta = $this->i16($deltas + 2 * $s);
			$range_offset_position = $range_offsets + 2 * $s;
			$range_offset = $this->u16($range_offset_position);

			if ($start === 0xFFFF) {
				continue;
			}

			for ($code = $start; $code <= $end; $code++) {
				if ($range_offset === 0) {
					$glyph = ($code + $delta) & 0xFFFF;
				}
				else {
					$glyph = $this->u16($range_offset_position + $range_offset + 2 * ($code - $start));

					if ($glyph !== 0) {
						$glyph = ($glyph + $delta) & 0xFFFF;
					}
				}

				if ($glyph !== 0) {
					$this->cmap[$code] = $glyph;
				}
			}
		}
	}

	private function locaOffsets(): array {
		$loca = $this->tables['loca']['offset'];
		$offsets = [];

		for ($i = 0; $i <= $this->num_glyphs; $i++) {
			$offsets[] = $this->long_loca ? $this->u32($loca + 4 * $i) : 2 * $this->u16($loca + 2 * $i);
		}

		return $offsets;
	}

	private function componentGlyphs(int $offset, int $length): array {
		if ($length <= 0 || $this->i16($offset) >= 0) {
			return [];
		}

		$components = [];
		$position = $offset + 10;

		do {
			$flags = $this->u16($position);
			$components[] = $this->u16($position + 2);
			$position += 4;
			$position += ($flags & 0x0001) ? 4 : 2;

			if ($flags & 0x0008) {
				$position += 2;
			}
			elseif ($flags & 0x0040) {
				$position += 4;
			}
			elseif ($flags & 0x0080) {
				$position += 8;
			}
		}
		while ($flags & 0x0020);

		return $components;
	}

	private function table(string $tag): string {
		return substr($this->data, $this->tables[$tag]['offset'], $this->tables[$tag]['length']);
	}

	// --- Writing ---------------------------------------------------------------------

	private static function buildFont(array $tables): string {
		$count = count($tables);
		$entry_selector = (int) floor(log($count, 2));
		$search_range = 16 * (2 ** $entry_selector);

		$directory = "\x00\x01\x00\x00".pack('nnnn', $count, $search_range, $entry_selector, 16 * $count - $search_range);
		$body = '';
		$offset = 12 + 16 * $count;

		foreach ($tables as $tag => $content) {
			$directory .= $tag.pack('NNN', self::checksum($content), $offset + strlen($body), strlen($content));
			$body .= $content.str_repeat("\0", (4 - strlen($content) % 4) % 4);
		}

		return $directory.$body;
	}

	private static function tableOffset(string $font, string $tag): int {
		$count = unpack('n', $font, 4)[1];

		for ($i = 0; $i < $count; $i++) {
			if (substr($font, 12 + 16 * $i, 4) === $tag) {
				return unpack('N', $font, 12 + 16 * $i + 8)[1];
			}
		}

		throw new RuntimeException('Table '.$tag.' missing in subset.');
	}

	private static function checksum(string $data): int {
		$data .= str_repeat("\0", (4 - strlen($data) % 4) % 4);
		$sum = 0;

		foreach (unpack('N*', $data) as $word) {
			$sum = ($sum + $word) & 0xFFFFFFFF;
		}

		return $sum;
	}

	private static function postScriptName(string $file_name): string {
		$name = preg_replace('/[^A-Za-z0-9-]/', '', $file_name);

		return $name !== '' ? $name : 'Font';
	}

	private function u16(int $offset): int {
		return unpack('n', $this->data, $offset)[1];
	}

	private function i16(int $offset): int {
		$value = $this->u16($offset);

		return $value >= 0x8000 ? $value - 0x10000 : $value;
	}

	private function u32(int $offset): int {
		return unpack('N', $this->data, $offset)[1];
	}
}
