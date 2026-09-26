<?php

namespace App\Domain\Security;

/**
 * Time-based one-time codes (RFC 6238, the six digits an authenticator app shows), written out
 * here instead of pulled in as a package: it is forty lines of HMAC and the console is the only user.
 * SHA-1, 30 second steps, 6 digits: the defaults every authenticator app reads from a QR code.
 */
class Totp
{
    private const STEP = 30;

    private const DIGITS = 6;

    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new secret, 160 random bits in base32. */
    public static function secret(): string
    {
        // 20 bytes = 160 bits = 32 base32 characters.
        $out = '';
        $bits = '';
        foreach (str_split(random_bytes(20)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[bindec($chunk)];
        }

        return $out;
    }

    /** The link an authenticator app reads from the QR code. */
    public static function uri(string $secret, string $account, string $issuer = 'Appwerk'): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?'.http_build_query([
            'secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::STEP,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** The code for one time step. */
    public static function at(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The time step the code belongs to, or null. One step either side is accepted, because a
     * phone's clock drifts and a code typed in its last second arrives in the next step. A step
     * at or before $after has been used already and is refused, so an overheard code is worthless.
     * The clock is Laravel's, so a test that freezes time freezes the code window with it.
     */
    public static function verify(string $secret, string $code, ?int $after = null, ?int $now = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return null;
        }
        $current = intdiv($now ?? now()->getTimestamp(), self::STEP);
        foreach ([$current, $current - 1, $current + 1] as $step) {
            if (($after === null || $step > $after) && hash_equals(self::at($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    private static function decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($secret, '='))) as $char) {
            $pos = strpos(self::B32, $char);
            if ($pos !== false) {
                $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
            }
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
