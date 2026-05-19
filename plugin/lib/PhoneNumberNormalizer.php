<?php
/**
 * Phone number normalization to a form Evolution API accepts.
 *
 * Evolution API expects numbers in international form without `+` or
 * separators, e.g. `523312345678`. This class normalizes arbitrary user
 * input (with spaces, hyphens, parentheses, leading zeros, plus sign,
 * national trunk prefixes) into that form.
 *
 * @license GPL-2.0-or-later
 */
class EvoPhoneNumberNormalizer {

    /**
     * @param string $raw       Number as entered (any format).
     * @param string $defaultCc Default country code (digits only), e.g. "52".
     * @return string|null      Digits-only E.164 form without "+", or null if invalid.
     */
    public static function normalize($raw, $defaultCc = '52') {
        if ($raw === null) {
            return null;
        }

        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        // Preserve a leading "+" intent before stripping non-digits.
        $hasPlus = (strpos($raw, '+') === 0);

        // Keep digits only.
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '' || $digits === null) {
            return null;
        }

        // If it had a "+", trust the prefix as-is.
        if ($hasPlus) {
            return self::validateLength($digits);
        }

        // "00" international prefix → drop it.
        if (strpos($digits, '00') === 0) {
            return self::validateLength(substr($digits, 2));
        }

        $defaultCc = preg_replace('/\D+/', '', (string) $defaultCc);
        if ($defaultCc === '' || $defaultCc === null) {
            // No default country: trust input as-is.
            return self::validateLength($digits);
        }

        // Already starts with default country code: leave it.
        if (strpos($digits, $defaultCc) === 0
            && strlen($digits) >= strlen($defaultCc) + 7) {
            return self::validateLength($digits);
        }

        // National trunk prefix "0" (e.g. some LATAM countries) → strip.
        if (strpos($digits, '0') === 0) {
            $digits = ltrim($digits, '0');
        }

        return self::validateLength($defaultCc . $digits);
    }

    /**
     * Normalize a list, returning only the valid ones.
     *
     * @param array  $list
     * @param string $defaultCc
     * @return array Indexed array of normalized digits-only numbers.
     */
    public static function normalizeMany(array $list, $defaultCc = '52') {
        $out = array();
        foreach ($list as $raw) {
            $n = self::normalize($raw, $defaultCc);
            if ($n !== null) {
                $out[] = $n;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Basic sanity bounds for an international phone number.
     * ITU E.164 caps at 15 digits; minimum useful length is 8 (country + subscriber).
     */
    private static function validateLength($digits) {
        $len = strlen($digits);
        if ($len < 8 || $len > 15) {
            return null;
        }
        return $digits;
    }
}
