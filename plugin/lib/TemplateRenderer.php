<?php
/**
 * Tiny template engine: replaces {{token}} placeholders.
 *
 * Differences vs. plain str_replace:
 *  - Unknown tokens render as empty (not literal {{token}}).
 *  - Values are stringified safely (objects/arrays → empty).
 *  - Supports a default value via `{{token|fallback}}`.
 *
 * @license GPL-2.0-or-later
 */
class EvoTemplateRenderer {

    public static function render($template, array $vars) {
        if ($template === null) {
            return '';
        }
        $template = (string) $template;
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*(?:\|\s*([^}]*))?\}\}/',
            function ($m) use ($vars) {
                $key = $m[1];
                $fallback = isset($m[2]) ? $m[2] : '';
                if (array_key_exists($key, $vars)) {
                    $v = $vars[$key];
                    if (is_scalar($v)) {
                        return (string) $v;
                    }
                    if ($v === null) {
                        return $fallback;
                    }
                    return ''; // arrays / objects → empty
                }
                return $fallback;
            },
            $template
        );
    }

    /**
     * Strip HTML to plain text for WhatsApp delivery.
     * WhatsApp does not support HTML — convert minimal formatting to plain.
     */
    public static function htmlToWhatsappText($html) {
        $html = (string) $html;

        // Bold: <strong>/<b> → *text*
        $html = preg_replace('#<(strong|b)\b[^>]*>(.*?)</\1>#is', '*$2*', $html);
        // Italic: <em>/<i> → _text_
        $html = preg_replace('#<(em|i)\b[^>]*>(.*?)</\1>#is', '_$2_', $html);
        // Line breaks
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</p>\s*<p[^>]*>#i', "\n\n", $html);
        $html = preg_replace('#<p[^>]*>#i', '', $html);
        $html = preg_replace('#</p>#i', '', $html);
        // Lists: <li> → "• "
        $html = preg_replace('#<li[^>]*>#i', '• ', $html);
        $html = preg_replace('#</li>#i', "\n", $html);

        // Strip remaining tags.
        $text = strip_tags($html);
        // Decode HTML entities.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Collapse runs of blank lines.
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    /**
     * Truncate to N chars without breaking UTF-8 mid-codepoint.
     */
    public static function truncate($text, $max = 3500) {
        $text = (string) $text;
        if (function_exists('mb_strlen')) {
            if (mb_strlen($text, 'UTF-8') <= $max) { return $text; }
            return mb_substr($text, 0, $max - 1, 'UTF-8') . '…';
        }
        if (strlen($text) <= $max) { return $text; }
        return substr($text, 0, $max - 1) . '…';
    }
}
