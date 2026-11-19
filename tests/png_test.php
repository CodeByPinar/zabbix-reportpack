<?php
/**
 * Checks PngImage against reference pixels.
 *
 *     php tests/png_test.php [folder]
 *
 * The folder (default: tests/fixtures/png) holds case.png files, the expected case.rgb / case.alpha
 * bytes and cases.json, written with Pillow by tests/make_png_cases.py.
 */

require __DIR__.'/../ReportPack/includes/PngImage.php';

use Modules\ReportPack\Includes\PngImage;

$dir = $argv[1] ?? __DIR__.'/fixtures/png';
$cases = json_decode((string) @file_get_contents($dir.'/cases.json'), true);

if (!is_array($cases)) {
	fwrite(STDERR, "Usage: php png_test.php <folder with cases.json>\n");
	exit(2);
}

$failed = 0;

foreach ($cases as $case) {
	$name = $case['name'];
	$started = microtime(true);
	$image = PngImage::decode((string) file_get_contents($dir.'/'.$name.'.png'));
	$ms = round((microtime(true) - $started) * 1000);

	if (!$case['readable']) {
		$ok = $image === null;
	}
	else {
		$alpha_file = $dir.'/'.$name.'.alpha';
		$ok = $image !== null
			&& $image['rgb'] === file_get_contents($dir.'/'.$name.'.rgb')
			&& ($case['alpha']
				? $image['alpha'] === file_get_contents($alpha_file)
				: $image['alpha'] === null);
	}

	printf("%-14s %s (%d ms)\n", $name, $ok ? 'ok' : 'FAILED', $ms);
	$failed += $ok ? 0 : 1;
}

echo $failed == 0 ? "All PNG checks passed.\n" : $failed." PNG check(s) failed.\n";
exit($failed == 0 ? 0 : 1);
