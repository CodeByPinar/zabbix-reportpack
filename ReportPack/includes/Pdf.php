<?php

namespace Modules\ReportPack\Includes;

use RuntimeException;

/**
 * A small PDF 1.4 writer: pages, Unicode text in embedded TrueType fonts, filled and stroked shapes,
 * and images with transparency. Needs only the zlib extension; with GD it also reads JPEG and GIF images,
 * without GD only PNG.
 *
 * Coordinates are in points (1/72 inch) measured from the top-left corner of the page.
 */
class Pdf {

	public const A4_WIDTH = 595.28;
	public const A4_HEIGHT = 841.89;

	private float $width;
	private float $height;

	/** @var string[] content stream of each page */
	private array $pages = [];
	private int $page = -1;

	/** name => ['font' => TrueTypeFont, 'glyphs' => [glyph => code point], 'fake_bold' => bool] */
	private array $fonts = [];

	/** key => ['width', 'height', 'rgb', 'alpha'] */
	private array $images = [];

	private array $info = [];

	public function __construct(float $width = self::A4_WIDTH, float $height = self::A4_HEIGHT) {
		$this->width = $width;
		$this->height = $height;
	}

	public function width(): float {
		return $this->width;
	}

	public function height(): float {
		return $this->height;
	}

	/**
	 * @param string      $name       Name used in text() calls.
	 * @param string      $path       TrueType file.
	 * @param bool        $fake_bold  Draw outlined to look bold, for when no bold font file exists.
	 */
	public function addFont(string $name, string $path, bool $fake_bold = false): void {
		$this->fonts[$name] = ['font' => new TrueTypeFont($path), 'glyphs' => [], 'fake_bold' => $fake_bold];
	}

	public function setInfo(string $title, string $author = '', string $subject = ''): void {
		$this->info = ['Title' => $title, 'Author' => $author, 'Subject' => $subject];
	}

	public function addPage(): int {
		$this->pages[] = '';
		$this->page = count($this->pages) - 1;

		return $this->page;
	}

	public function setPage(int $page): void {
		$this->page = $page;
	}

	public function pageCount(): int {
		return count($this->pages);
	}

	// --- Text -----------------------------------------------------------------------

	/**
	 * Width of a text in points.
	 */
	public function textWidth(string $text, string $font, float $size): float {
		$ttf = $this->font($font)['font'];
		$units = 0;

		foreach (self::codePoints($text) as $code_point) {
			$units += $ttf->advance($ttf->glyph($code_point));
		}

		return $units * $size / $ttf->units_per_em;
	}

	/**
	 * Writes one line of text. $y is the top of the line box; the baseline is placed below it.
	 *
	 * @param string $align  left, right or center, relative to $x.
	 */
	public function text(float $x, float $y, string $text, string $font, float $size, array $color = [0, 0, 0],
			string $align = 'left'): void {
		if ($text === '') {
			return;
		}

		$entry = &$this->fonts[$font];
		$ttf = $entry['font'];

		if ($align !== 'left') {
			$width = $this->textWidth($text, $font, $size);
			$x -= $align === 'right' ? $width : $width / 2;
		}

		$hex = '';

		foreach (self::codePoints($text) as $code_point) {
			$glyph = $ttf->glyph($code_point);
			$entry['glyphs'][$glyph] = $code_point;
			$hex .= sprintf('%04X', $glyph);
		}

		$baseline = $this->height - $y - $size * $ttf->ascent / $ttf->units_per_em;
		$resource = 'F'.(array_search($font, array_keys($this->fonts), true) + 1);

		// q … Q: the text render mode used for fake bold would otherwise stay on for later text.
		$ops = 'q BT '.self::color($color, 'rg');

		if ($entry['fake_bold']) {
			$ops .= ' '.self::color($color, 'RG').' '.self::num($size * 0.065).' w 2 Tr';
		}

		$ops .= ' /'.$resource.' '.self::num($size).' Tf '.self::num($x).' '.self::num($baseline).' Td <'.$hex.'> Tj ET Q';

		unset($entry);

		$this->pages[$this->page] .= $ops."\n";
	}

	/**
	 * Cuts a text to a width, ending it with "…" when it was too long.
	 */
	public function fit(string $text, string $font, float $size, float $width): string {
		if ($this->textWidth($text, $font, $size) <= $width) {
			return $text;
		}

		$ellipsis = '…';
		$chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

		while ($chars && $this->textWidth(implode('', $chars).$ellipsis, $font, $size) > $width) {
			array_pop($chars);
		}

		return rtrim(implode('', $chars)).$ellipsis;
	}

	/**
	 * Breaks a text into lines no wider than $width, at spaces where possible.
	 *
	 * @return string[]
	 */
	public function wrap(string $text, string $font, float $size, float $width): array {
		$lines = [];

		foreach (preg_split('/\R/u', $text) as $paragraph) {
			$line = '';

			foreach (preg_split('/ +/u', $paragraph) as $word) {
				$candidate = $line === '' ? $word : $line.' '.$word;

				if ($line !== '' && $this->textWidth($candidate, $font, $size) > $width) {
					$lines[] = $line;
					$line = $word;
				}
				else {
					$line = $candidate;
				}

				// A single word wider than the line is cut.
				if ($this->textWidth($line, $font, $size) > $width) {
					$lines[] = $this->fit($line, $font, $size, $width);
					$line = '';
				}
			}

			$lines[] = $line;
		}

		return $lines;
	}

	// --- Shapes ---------------------------------------------------------------------

	public function rect(float $x, float $y, float $w, float $h, ?array $fill, ?array $stroke = null,
			float $line_width = 0.5): void {
		$ops = 'q ';

		if ($fill !== null) {
			$ops .= self::color($fill, 'rg').' ';
		}

		if ($stroke !== null) {
			$ops .= self::color($stroke, 'RG').' '.self::num($line_width).' w ';
		}

		$ops .= self::num($x).' '.self::num($this->height - $y - $h).' '.self::num($w).' '.self::num($h).' re ';
		$ops .= $fill !== null && $stroke !== null ? 'B' : ($fill !== null ? 'f' : 'S');

		$this->pages[$this->page] .= $ops." Q\n";
	}

	/**
	 * @param float[] $dash  Dash pattern in points, for example [3, 2]; empty for a solid line.
	 */
	public function line(float $x1, float $y1, float $x2, float $y2, array $color, float $line_width = 0.5,
			array $dash = []): void {
		$this->pages[$this->page] .= 'q '.self::color($color, 'RG').' '.self::num($line_width).' w '
			.($dash ? '['.implode(' ', array_map([self::class, 'num'], $dash)).'] 0 d ' : '')
			.self::num($x1).' '.self::num($this->height - $y1).' m '
			.self::num($x2).' '.self::num($this->height - $y2).' l S Q'."\n";
	}

	// --- Images ---------------------------------------------------------------------

	/**
	 * Registers an image (PNG, JPEG or GIF bytes) and returns its key and size in pixels.
	 *
	 * @return array|null  ['key' => string, 'width' => int, 'height' => int], or null if it cannot be read.
	 */
	public function addImage(string $bytes): ?array {
		// Without GD, PNG files are read in PHP; other formats are left out and the PDF is made without them.
		if (!function_exists('imagecreatefromstring')) {
			$png = PngImage::decode($bytes);

			return $png === null ? null : $this->storeImage($png['width'], $png['height'], $png['rgb'], $png['alpha']);
		}

		$image = @imagecreatefromstring($bytes);

		if ($image === false) {
			return null;
		}

		if (!imageistruecolor($image)) {
			imagepalettetotruecolor($image);
		}

		$width = imagesx($image);
		$height = imagesy($image);
		$rgb = '';
		$alpha = '';
		$has_alpha = false;

		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				$pixel = imagecolorat($image, $x, $y);
				$rgb .= chr(($pixel >> 16) & 0xFF).chr(($pixel >> 8) & 0xFF).chr($pixel & 0xFF);

				// GD alpha: 0 opaque … 127 transparent.
				$a = ($pixel >> 24) & 0x7F;
				$alpha .= chr(255 - (int) round($a * 255 / 127));
				$has_alpha = $has_alpha || $a > 0;
			}
		}

		imagedestroy($image);

		return $this->storeImage($width, $height, $rgb, $has_alpha ? $alpha : null);
	}

	/**
	 * @param string      $rgb    3 bytes per pixel.
	 * @param string|null $alpha  1 byte per pixel (255 = opaque), or null for an opaque image.
	 */
	private function storeImage(int $width, int $height, string $rgb, ?string $alpha): array {
		$key = 'Im'.(count($this->images) + 1);
		$this->images[$key] = [
			'width' => $width,
			'height' => $height,
			'rgb' => gzcompress($rgb, 9),
			'alpha' => $alpha !== null ? gzcompress($alpha, 9) : null
		];

		return ['key' => $key, 'width' => $width, 'height' => $height];
	}

	public function image(string $key, float $x, float $y, float $w, float $h): void {
		$this->pages[$this->page] .= 'q '.self::num($w).' 0 0 '.self::num($h).' '.self::num($x).' '
			.self::num($this->height - $y - $h).' cm /'.$key.' Do Q'."\n";
	}

	// --- Output ---------------------------------------------------------------------

	public function output(): string {
		if (!$this->pages) {
			$this->addPage();
		}

		$objects = [];
		$add = static function (string $body) use (&$objects): int {
			$objects[] = $body;

			return count($objects);
		};
		$reserve = static function () use (&$objects): int {
			$objects[] = null;

			return count($objects);
		};

		$catalog = $reserve();
		$pages = $reserve();

		// Fonts.
		$font_resources = '';
		$index = 0;

		foreach ($this->fonts as $entry) {
			$index++;
			$font_resources .= '/F'.$index.' '.$this->writeFont($entry, $add).' 0 R ';
		}

		// Images.
		$image_resources = '';

		foreach ($this->images as $key => $image) {
			$smask = '';

			if ($image['alpha'] !== null) {
				$mask = $add(self::stream('/Type /XObject /Subtype /Image /Width '.$image['width'].' /Height '
					.$image['height'].' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode',
					$image['alpha']
				));
				$smask = ' /SMask '.$mask.' 0 R';
			}

			$id = $add(self::stream('/Type /XObject /Subtype /Image /Width '.$image['width'].' /Height '
				.$image['height'].' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode'.$smask,
				$image['rgb']
			));
			$image_resources .= '/'.$key.' '.$id.' 0 R ';
		}

		$resources = '<< /ProcSet [/PDF /Text /ImageB /ImageC] /Font << '.$font_resources.'>>'
			.($image_resources !== '' ? ' /XObject << '.$image_resources.'>>' : '').' >>';

		$kids = [];

		foreach ($this->pages as $content) {
			$stream = $add(self::stream('/Filter /FlateDecode', gzcompress($content, 6)));
			$kids[] = $add('<< /Type /Page /Parent '.$pages.' 0 R /MediaBox [0 0 '.self::num($this->width).' '
				.self::num($this->height).'] /Resources '.$resources.' /Contents '.$stream.' 0 R >>'
			).' 0 R';
		}

		$objects[$pages - 1] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
		$objects[$catalog - 1] = '<< /Type /Catalog /Pages '.$pages.' 0 R >>';

		$info = '<< /Producer '.self::textString('Report Pack for Zabbix')
			.' /CreationDate '.self::textString('D:'.date('YmdHis'));

		foreach ($this->info as $field => $value) {
			if ($value !== '') {
				$info .= ' /'.$field.' '.self::textString($value);
			}
		}

		$info_id = $add($info.' >>');

		// Assemble with a cross-reference table.
		$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];

		foreach ($objects as $i => $body) {
			$offsets[] = strlen($pdf);
			$pdf .= ($i + 1)." 0 obj\n".$body."\nendobj\n";
		}

		$xref = strlen($pdf);
		$pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

		foreach ($offsets as $offset) {
			$pdf .= sprintf("%010d 00000 n \n", $offset);
		}

		$id = md5($pdf);
		$pdf .= "trailer\n<< /Size ".(count($objects) + 1).' /Root '.$catalog.' 0 R /Info '.$info_id.' 0 R /ID [<'.$id
			.'> <'.$id.">] >>\nstartxref\n".$xref."\n%%EOF\n";

		return $pdf;
	}

	private function writeFont(array $entry, callable $add): int {
		/** @var TrueTypeFont $ttf */
		$ttf = $entry['font'];
		$glyphs = $entry['glyphs'] + [0 => 0];
		ksort($glyphs);

		$scale = 1000 / $ttf->units_per_em;
		// A subset font name starts with six capital letters and a plus sign.
		$tag = substr(strtoupper(preg_replace('/[^a-z]/', '', md5(implode(',', array_keys($glyphs)).$ttf->name)).'ABCDEF'), 0, 6);
		$base_font = $tag.'+'.$ttf->name;

		$subset = $ttf->subset(array_keys($glyphs));
		$file = $add(self::stream('/Length1 '.strlen($subset).' /Filter /FlateDecode', gzcompress($subset, 9)));

		$descriptor = $add('<< /Type /FontDescriptor /FontName /'.$base_font.' /Flags 32 /FontBBox ['
			.implode(' ', array_map(static function ($v) use ($scale) {
				return (int) round($v * $scale);
			}, $ttf->bbox)).'] /ItalicAngle '.self::num($ttf->italic_angle)
			.' /Ascent '.(int) round($ttf->ascent * $scale).' /Descent '.(int) round($ttf->descent * $scale)
			.' /CapHeight '.(int) round($ttf->cap_height * $scale).' /StemV 80 /FontFile2 '.$file.' 0 R >>'
		);

		$widths = '';

		foreach (array_keys($glyphs) as $glyph) {
			$widths .= $glyph.' ['.(int) round($ttf->advance($glyph) * $scale).'] ';
		}

		$cid_font = $add('<< /Type /Font /Subtype /CIDFontType2 /BaseFont /'.$base_font
			.' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor '
			.$descriptor.' 0 R /DW 1000 /W ['.$widths.'] /CIDToGIDMap /Identity >>'
		);

		$to_unicode = $add(self::stream('/Filter /FlateDecode', gzcompress(self::toUnicodeCMap($glyphs), 9)));

		return $add('<< /Type /Font /Subtype /Type0 /BaseFont /'.$base_font.' /Encoding /Identity-H /DescendantFonts ['
			.$cid_font.' 0 R] /ToUnicode '.$to_unicode.' 0 R >>'
		);
	}

	/**
	 * Lets PDF viewers copy and search the text: maps each glyph back to its character.
	 */
	private static function toUnicodeCMap(array $glyphs): string {
		$entries = [];

		foreach ($glyphs as $glyph => $code_point) {
			if ($glyph === 0 || $code_point === 0) {
				continue;
			}

			$utf16 = strtoupper(bin2hex(mb_convert_encoding(mb_chr($code_point, 'UTF-8'), 'UTF-16BE', 'UTF-8')));
			$entries[] = sprintf('<%04X> <%s>', $glyph, $utf16);
		}

		$cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
			."/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
			."/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
			."1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";

		foreach (array_chunk($entries, 100) as $chunk) {
			$cmap .= count($chunk)." beginbfchar\n".implode("\n", $chunk)."\nendbfchar\n";
		}

		return $cmap."endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
	}

	// --- Helpers --------------------------------------------------------------------

	private function font(string $name): array {
		if (!array_key_exists($name, $this->fonts)) {
			throw new RuntimeException('Unknown font '.$name);
		}

		return $this->fonts[$name];
	}

	private static function codePoints(string $text): array {
		$code_points = [];

		foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $char) {
			$code_points[] = mb_ord($char, 'UTF-8');
		}

		return $code_points;
	}

	private static function stream(string $dictionary, string $data): string {
		return '<< '.$dictionary.' /Length '.strlen($data)." >>\nstream\n".$data."\nendstream";
	}

	/**
	 * PDF text string in UTF-16 with byte order mark, safe for any language.
	 */
	private static function textString(string $text): string {
		return '<FEFF'.strtoupper(bin2hex(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'))).'>';
	}

	private static function color(array $rgb, string $operator): string {
		return self::num($rgb[0] / 255).' '.self::num($rgb[1] / 255).' '.self::num($rgb[2] / 255).' '.$operator;
	}

	private static function num(float $value): string {
		$text = rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');

		return $text === '-0' || $text === '' ? '0' : $text;
	}
}
