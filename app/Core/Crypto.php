<?php
declare(strict_types=1);
namespace WB2A\Core;

/** AES-256-GCM encryption for stored credentials. */
final class Crypto
{
    public static function available(): bool
    {
        return function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
    }

    public static function encrypt(string $plain): string
    {
        if (!self::available() || $plain === '') { return $plain; }
        $key = hash('sha256', Env::secret(), true);
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) { return $plain; }
        return 'enc1:' . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $stored): string
    {
        if (strpos($stored, 'enc1:') !== 0) { return $stored; }
        if (!self::available()) { return ''; }
        $raw = base64_decode(substr($stored, 5), true);
        if ($raw === false || strlen($raw) < 29) { return ''; }
        $key = hash('sha256', Env::secret(), true);
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct = substr($raw, 28);
        $pt = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $pt === false ? '' : $pt;
    }

    public static function mask(string $token): string
    {
        if ($token === '') { return ''; }
        $n = strlen($token);
        if ($n <= 10) { return substr($token, 0, 2) . '****'; }
        return substr($token, 0, 6) . '****' . substr($token, -4);
    }
}
