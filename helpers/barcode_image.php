<?php
/**
 * Generate barcode SVG (Code128) and return Data URI
 */
function barcodeDataUri($text, $height = 60, $scale = 2) {
    // Use SVG renderer instead of PNG (no GD required)
    $generator = new Picqer\Barcode\BarcodeGeneratorSVG();
    $barcode = $generator->getBarcode($text, $generator::TYPE_CODE_128, $scale, $height);

    // Convert SVG string into base64 data URI
    return 'data:image/svg+xml;base64,' . base64_encode($barcode);
}
