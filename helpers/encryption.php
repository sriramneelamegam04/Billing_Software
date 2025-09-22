<?php
function aes_encrypt($plaintext) {
    return base64_encode(openssl_encrypt(
        $plaintext,
        'AES-256-CBC',
        $_ENV['AES_KEY'],
        OPENSSL_RAW_DATA,
        $_ENV['AES_IV']
    ));
}

function aes_decrypt($ciphertext) {
    return openssl_decrypt(
        base64_decode($ciphertext),
        'AES-256-CBC',
        $_ENV['AES_KEY'],
        OPENSSL_RAW_DATA,
        $_ENV['AES_IV']
    );
}
