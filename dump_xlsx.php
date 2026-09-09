<?php
require __DIR__ . '/vendor/autoload.php';

var_dump([
    'Dompdf\Dompdf' => class_exists(\Dompdf\Dompdf::class),
    'vendor_dir_files' => array_slice(scandir(__DIR__ . '/vendor/dompdf/dompdf'), 0, 15),
    'src_exists' => is_dir(__DIR__ . '/vendor/dompdf/dompdf/src'),
    'options_exists' => file_exists(__DIR__ . '/vendor/dompdf/dompdf/src/Options.php'),
    'dompdf_php_exists' => file_exists(__DIR__ . '/vendor/dompdf/dompdf/src/Dompdf.php'),
]);
