<?php
// helpers/barcode.php
// Simple EAN-13 like generator (numeric string + checksum).
// If you already get real manufacturer barcodes, just pass-through.

function ean13_checksum(string $digits12): int {
    // 12-digit -> compute check digit
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $n = intval($digits12[$i]);
        $sum += ($i % 2 === 0) ? $n : 3 * $n;
    }
    return (10 - ($sum % 10)) % 10;
}

/**
 * Auto-generate 13-digit barcode.
 * Format: 890 (IN prefix-ish) + org(3) + product(5) + rand(1) = 12 digits -> + checksum
 * Ensures high uniqueness across org/products without DB race conditions.
 */
function generate_barcode(int $org_id, int $product_id): string {
    $org   = str_pad(strval($org_id % 1000), 3, '0', STR_PAD_LEFT);
    $prod  = str_pad(strval($product_id % 100000), 5, '0', STR_PAD_LEFT);
    $rand1 = strval(random_int(0, 9));
    $base12 = "890" . $org . $prod . $rand1; // 12 digits
    $chk = ean13_checksum($base12);
    return $base12 . $chk; // 13 digits
}
