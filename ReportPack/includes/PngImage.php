<?php

namespace Modules\ReportPack\Includes;

/**
 * Reads a PNG file into raw pixels without the GD extension, so the command-line sender can put the logo
 * into PDFs on servers where php-gd is not installed.
 *
 * Supports all colour types and bit depths of non-interlaced PNG files, including palette and tRNS
 * transparency. The logos Report Pack stores are non-interlaced PNG files.
 */
class PngImage {

	/** Largest image accepted, in pixels, so a broken file cannot use up the memory. */
	private const MAX_PIXELS = 4000000;

	/**
	 * @return array|null  ['width' => int, 'height' => int, 'rgb' => string, 'alpha' => string|null] with one
	 *                     byte per sample, or null if the bytes are not a PNG image this class can read.
	 */
	public static function decode(string $bytes): ?array {
		if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) !== 0) {
			return null;
		}

		$header = null;
		$palette = '';
		$trns = null;
		$idat = '';
		$length = strlen($bytes);

		for ($pos = 8; $pos + 8 <= $length;) {
			$size = unpack('N', substr($bytes, $pos, 4))[1];
			$type = substr($bytes, $pos + 4, 4);
			$data = (string) substr($bytes, $pos + 8, $size);
			$pos += 12 + $size;

			if ($type === 'IHDR' && strlen($data) === 13) {
				$header = unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $data);
			}
			elseif ($type === 'PLTE') {
				$palette = $data;
			}
			elseif ($type === 'tRNS') {
				$trns = $data;
			}
			elseif ($type === 'IDAT') {
				$idat .= $data;
			}
			elseif ($type === 'IEND') {
				break;
			}
		}

		if ($header === null || $header['compression'] != 0 || $header['filter'] != 0
				|| $header['interlace'] != 0) {
			return null;
		}

		$width = $header['width'];
		$height = $header['height'];
		$depth = $header['depth'];
		$color = $header['color'];
		$channels = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$color] ?? 0;
		$depths = [0 => [1, 2, 4, 8, 16], 2 => [8, 16], 3 => [1, 2, 4, 8], 4 => [8, 16], 6 => [8, 16]][$color] ?? [];

		if ($channels == 0 || !in_array($depth, $depths) || $width < 1 || $height < 1
				|| $width * $height > self::MAX_PIXELS || ($color == 3 && $palette === '')) {
			return null;
		}

		$raw = @gzuncompress($idat);
		$bits = $channels * $depth;
		$stride = intdiv($width * $bits + 7, 8);
		$bpp = max(1, intdiv($bits, 8));

		if ($raw === false || strlen($raw) < $height * ($stride + 1)) {
			return null;
		}

		// Transparent colour of grey and RGB images, compared with the 8-bit samples.
		$key = null;

		if ($trns !== null && ($color == 0 || $color == 2) && strlen($trns) >= 2 * $channels) {
			$key = [];

			for ($c = 0; $c < $channels; $c++) {
				$value = unpack('n', substr($trns, 2 * $c, 2))[1];
				$key[] = $depth == 16 ? $value >> 8 : $value;
			}
		}

		$rgb = '';
		$alpha = '';
		$has_alpha = false;
		$previous = array_fill(0, $stride, 0);
		$scale = $depth < 8 ? 255 / ((1 << $depth) - 1) : 1;

		for ($y = 0, $offset = 0; $y < $height; $y++, $offset += $stride + 1) {
			$line = array_values(unpack('C*', substr($raw, $offset + 1, $stride)));
			$line = self::unfilter(ord($raw[$offset]), $line, $previous, $bpp);

			if ($line === null) {
				return null;
			}

			$previous = $line;
			$samples = self::samples($line, $depth, $width * $channels);
			$line_rgb = [];
			$line_alpha = [];

			for ($x = 0; $x < $width; $x++) {
				$s = $x * $channels;

				switch ($color) {
					case 0:
						$g = (int) round($samples[$s] * $scale);
						array_push($line_rgb, $g, $g, $g);
						$a = $key !== null && $samples[$s] == $key[0] ? 0 : 255;
						break;

					case 2:
						array_push($line_rgb, $samples[$s], $samples[$s + 1], $samples[$s + 2]);
						$a = $key !== null && $samples[$s] == $key[0] && $samples[$s + 1] == $key[1]
							&& $samples[$s + 2] == $key[2] ? 0 : 255;
						break;

					case 3:
						$index = $samples[$s];
						$entry = substr($palette, 3 * $index, 3);

						if (strlen($entry) < 3) {
							$entry = "\0\0\0";
						}

						array_push($line_rgb, ord($entry[0]), ord($entry[1]), ord($entry[2]));
						$a = $trns !== null && $index < strlen($trns) ? ord($trns[$index]) : 255;
						break;

					case 4:
						array_push($line_rgb, $samples[$s], $samples[$s], $samples[$s]);
						$a = $samples[$s + 1];
						break;

					default:
						array_push($line_rgb, $samples[$s], $samples[$s + 1], $samples[$s + 2]);
						$a = $samples[$s + 3];
				}

				$line_alpha[] = $a;
				$has_alpha = $has_alpha || $a < 255;
			}

			$rgb .= pack('C*', ...$line_rgb);
			$alpha .= pack('C*', ...$line_alpha);
		}

		return [
			'width' => $width,
			'height' => $height,
			'rgb' => $rgb,
			'alpha' => $has_alpha ? $alpha : null
		];
	}

	/**
	 * Reverses the PNG filter of one scanline.
	 *
	 * @param int[] $line
	 * @param int[] $previous  The unfiltered line above, zeros for the first line.
	 *
	 * @return int[]|null
	 */
	private static function unfilter(int $filter, array $line, array $previous, int $bpp): ?array {
		$count = count($line);

		switch ($filter) {
			case 0:
				return $line;

			case 1:
				for ($i = $bpp; $i < $count; $i++) {
					$line[$i] = ($line[$i] + $line[$i - $bpp]) & 0xFF;
				}

				return $line;

			case 2:
				for ($i = 0; $i < $count; $i++) {
					$line[$i] = ($line[$i] + $previous[$i]) & 0xFF;
				}

				return $line;

			case 3:
				for ($i = 0; $i < $count; $i++) {
					$left = $i >= $bpp ? $line[$i - $bpp] : 0;
					$line[$i] = ($line[$i] + (($left + $previous[$i]) >> 1)) & 0xFF;
				}

				return $line;

			case 4:
				for ($i = 0; $i < $count; $i++) {
					$a = $i >= $bpp ? $line[$i - $bpp] : 0;
					$b = $previous[$i];
					$c = $i >= $bpp ? $previous[$i - $bpp] : 0;
					$p = $a + $b - $c;
					$pa = abs($p - $a);
					$pb = abs($p - $b);
					$pc = abs($p - $c);
					$predictor = ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
					$line[$i] = ($line[$i] + $predictor) & 0xFF;
				}

				return $line;
		}

		return null;
	}

	/**
	 * Splits an unfiltered line into samples: 16-bit samples are reduced to their high byte, samples
	 * smaller than a byte are unpacked (unscaled).
	 *
	 * @param int[] $line
	 *
	 * @return int[]
	 */
	private static function samples(array $line, int $depth, int $count): array {
		if ($depth == 8) {
			return $line;
		}

		$samples = [];

		if ($depth == 16) {
			for ($i = 0; $i < $count; $i++) {
				$samples[] = $line[2 * $i];
			}

			return $samples;
		}

		$per_byte = intdiv(8, $depth);
		$mask = (1 << $depth) - 1;

		foreach ($line as $byte) {
			for ($k = $per_byte - 1; $k >= 0; $k--) {
				$samples[] = ($byte >> ($k * $depth)) & $mask;
			}
		}

		return array_slice($samples, 0, $count);
	}
}
