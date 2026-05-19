<?php
/**
 * Tests for EvoTemplateRenderer.
 */

require_once dirname(__DIR__) . '/plugin/lib/TemplateRenderer.php';

class TemplateRendererTest {

    private $passed = 0;
    private $failed = 0;
    private $failures = array();

    public function run() {
        $this->test_basic_substitution();
        $this->test_missing_key_renders_empty();
        $this->test_fallback_syntax();
        $this->test_array_value_becomes_empty();
        $this->test_html_to_whatsapp_bold_italic();
        $this->test_html_to_whatsapp_line_breaks();
        $this->test_html_to_whatsapp_strips_unknown_tags();
        $this->test_html_to_whatsapp_decodes_entities();
        $this->test_truncate_short_text_unchanged();
        $this->test_truncate_long_text();

        echo "\n-- Summary --\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        if ($this->failed) {
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
        $this->failures[] = sprintf('%s — expected %s, got %s',
            $msg, var_export($expected, true), var_export($actual, true));
    }

    private function test_basic_substitution() {
        $this->assertSame(
            'Hello Renato, ticket #1234',
            EvoTemplateRenderer::render('Hello {{name}}, ticket #{{num}}',
                array('name' => 'Renato', 'num' => '1234')),
            'basic substitution'
        );
    }

    private function test_missing_key_renders_empty() {
        $this->assertSame(
            'A=, B=42',
            EvoTemplateRenderer::render('A={{a}}, B={{b}}', array('b' => 42)),
            'missing key → empty string'
        );
    }

    private function test_fallback_syntax() {
        $this->assertSame(
            'A=fallback, B=42',
            EvoTemplateRenderer::render('A={{a|fallback}}, B={{b|nope}}', array('b' => 42)),
            'fallback used when key missing'
        );
    }

    private function test_array_value_becomes_empty() {
        $this->assertSame(
            'X=, Y=1',
            EvoTemplateRenderer::render('X={{x}}, Y={{y}}',
                array('x' => array('not', 'scalar'), 'y' => 1)),
            'non-scalar values render empty'
        );
    }

    private function test_html_to_whatsapp_bold_italic() {
        $this->assertSame(
            '*hi* _there_',
            EvoTemplateRenderer::htmlToWhatsappText('<strong>hi</strong> <em>there</em>'),
            'bold/italic conversion'
        );
    }

    private function test_html_to_whatsapp_line_breaks() {
        $out = EvoTemplateRenderer::htmlToWhatsappText('a<br>b<br/>c');
        $this->assertSame("a\nb\nc", $out, '<br> → newlines');
    }

    private function test_html_to_whatsapp_strips_unknown_tags() {
        $this->assertSame(
            'hello world',
            EvoTemplateRenderer::htmlToWhatsappText('<span class="x">hello</span> <div>world</div>'),
            'strip unknown tags'
        );
    }

    private function test_html_to_whatsapp_decodes_entities() {
        $this->assertSame(
            'Tom & Jerry',
            EvoTemplateRenderer::htmlToWhatsappText('Tom &amp; Jerry'),
            'HTML entity decoded'
        );
    }

    private function test_truncate_short_text_unchanged() {
        $this->assertSame('short', EvoTemplateRenderer::truncate('short', 10),
            'short text not truncated');
    }

    private function test_truncate_long_text() {
        $out = EvoTemplateRenderer::truncate(str_repeat('a', 100), 10);
        $this->assertSame(true, function_exists('mb_substr')
            ? mb_strlen($out, 'UTF-8') === 10
            : strlen($out) === 10,
            'truncated to max length'
        );
        $this->assertSame('…', substr($out, -strlen('…')), 'ellipsis appended');
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0]) === __FILE__) {
    $t = new TemplateRendererTest();
    $t->run();
    echo "OK\n";
}
