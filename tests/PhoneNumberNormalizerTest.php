<?php
/**
 * Tests for EvoPhoneNumberNormalizer.
 *
 * Run with:
 *   ./vendor/bin/phpunit tests
 * or, without PHPUnit:
 *   php tests/PhoneNumberNormalizerTest.php
 */

require_once dirname(__DIR__) . '/plugin/lib/PhoneNumberNormalizer.php';

class PhoneNumberNormalizerTest {

    private $passed = 0;
    private $failed = 0;
    private $failures = array();

    public function run() {
        $this->test_returns_null_for_empty_input();
        $this->test_strips_separators();
        $this->test_keeps_plus_prefix_as_is();
        $this->test_drops_double_zero_international_prefix();
        $this->test_prepends_default_country_code_when_missing();
        $this->test_strips_leading_national_zero();
        $this->test_does_not_double_prepend_country_code();
        $this->test_rejects_too_short();
        $this->test_rejects_too_long();
        $this->test_normalize_many_dedupes();

        echo "\n-- Summary --\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        if ($this->failed) {
            echo "Failures:\n";
            foreach ($this->failures as $f) {
                echo "  - $f\n";
            }
            exit(1);
        }
    }

    private function assertSame($expected, $actual, $msg) {
        if ($expected === $actual) {
            $this->passed++;
            return;
        }
        $this->failed++;
        $this->failures[] = sprintf(
            '%s — expected %s, got %s',
            $msg,
            var_export($expected, true),
            var_export($actual, true)
        );
    }

    private function test_returns_null_for_empty_input() {
        $this->assertSame(null, EvoPhoneNumberNormalizer::normalize(null), 'null input');
        $this->assertSame(null, EvoPhoneNumberNormalizer::normalize(''), 'empty string');
        $this->assertSame(null, EvoPhoneNumberNormalizer::normalize('   '), 'whitespace only');
        $this->assertSame(null, EvoPhoneNumberNormalizer::normalize('abc'), 'no digits');
    }

    private function test_strips_separators() {
        $this->assertSame('5215512345678',
            EvoPhoneNumberNormalizer::normalize('+52 1 (55) 1234-5678', '52'),
            'parens/spaces/hyphens with + prefix');
    }

    private function test_keeps_plus_prefix_as_is() {
        $this->assertSame('15551234567',
            EvoPhoneNumberNormalizer::normalize('+1-555-123-4567', '52'),
            '+1 number with default cc=52 stays +1');
    }

    private function test_drops_double_zero_international_prefix() {
        $this->assertSame('5215512345678',
            EvoPhoneNumberNormalizer::normalize('005215512345678', '52'),
            '00<cc> prefix is the international dialing form');
    }

    private function test_prepends_default_country_code_when_missing() {
        $this->assertSame('525512345678',
            EvoPhoneNumberNormalizer::normalize('5512345678', '52'),
            '10-digit local number gets 52 prefix');
    }

    private function test_strips_leading_national_zero() {
        $this->assertSame('525512345678',
            EvoPhoneNumberNormalizer::normalize('05512345678', '52'),
            'leading 0 trunk prefix is stripped before cc');
    }

    private function test_does_not_double_prepend_country_code() {
        $this->assertSame('5215512345678',
            EvoPhoneNumberNormalizer::normalize('5215512345678', '52'),
            'already includes cc, length OK');
    }

    private function test_rejects_too_short() {
        $this->assertSame(null,
            EvoPhoneNumberNormalizer::normalize('1234', '52'),
            'too short after normalization');
    }

    private function test_rejects_too_long() {
        $this->assertSame(null,
            EvoPhoneNumberNormalizer::normalize('+1234567890123456', '52'),
            'too long after normalization');
    }

    private function test_normalize_many_dedupes() {
        $out = EvoPhoneNumberNormalizer::normalizeMany(
            array('+52 55 1234 5678', '5215512345678', '5512345678', 'bad'),
            '52'
        );
        sort($out);
        $expected = array('525512345678', '5215512345678');
        sort($expected);
        $this->assertSame($expected, $out, 'normalizeMany dedupes and drops invalid');
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0]) === __FILE__) {
    $t = new PhoneNumberNormalizerTest();
    $t->run();
    echo "OK\n";
}
