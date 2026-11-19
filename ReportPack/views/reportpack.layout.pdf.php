<?php

/**
 * Sends a view's output as a PDF download.
 *
 * @var CView $this
 * @var array $data
 */

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="'.$data['page']['file'].'"');
header('Content-Length: '.strlen($data['main_block']));

echo $data['main_block'];
