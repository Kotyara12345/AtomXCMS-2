<?php

/**
 * Renders current BBCode and legacy HTML product descriptions safely.
 */
class ShopDescriptionFormatter
{
    public static function format($text)
    {
        $text = (string)$text;
        if (trim($text) === '') return '';
        if (preg_match('/<\/?[a-z][^>]*>/i', $text)) return self::sanitizeHtml($text);
        return self::parseBbcode($text);
    }

    public static function text($text)
    {
        $html = self::format($text);
        $html = preg_replace('#<br\s*/?>#i', ' ', $html);
        $html = preg_replace('#</?(?:p|div|li|h[1-6]|blockquote|tr|pre|ul|ol|table)\b[^>]*>#i', ' ', $html);
        $text = trim(strip_tags($html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    private static function parseBbcode($text)
    {
        $register = ShopHelper::register();
        if (!empty($register['PrintText'])) return $register['PrintText']->parseBBCodes($text);
        if (class_exists('PrintText')) return (new PrintText())->parseBBCodes($text);
        return nl2br(h($text));
    }

    private static function sanitizeHtml($html)
    {
        $blocked = 'script|style|iframe|object|embed|form|input|textarea|select|button|meta|link';
        $html = preg_replace('#<(' . $blocked . ')\b[^>]*>.*?</\1>#is', '', (string)$html);
        $html = preg_replace('#</?(?:' . $blocked . ')\b[^>]*>#is', '', $html);
        $html = preg_replace('#\s+on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)#is', '', $html);
        $html = preg_replace_callback('#\s+style\s*=\s*(["\'])(.*?)\1#is', array('self', 'sanitizeStyle'), $html);
        $html = preg_replace('#\s+(href|src)\s*=\s*(["\'])\s*(?:javascript|vbscript|data):[^"\']*\2#is', ' $1="#"', $html);
        $html = preg_replace('#\s+(href|src)\s*=\s*(?:javascript|vbscript|data):[^\s>]*#is', ' $1="#"', $html);
        $allowed = '<p><br><strong><b><em><i><u><s><strike><ul><ol><li><a><img>'
            . '<h1><h2><h3><h4><h5><h6><blockquote><pre><code><hr>'
            . '<table><thead><tbody><tfoot><tr><th><td><figure><figcaption><span><div>';
        return strip_tags($html, $allowed);
    }

    private static function sanitizeStyle($matches)
    {
        $safe = array();
        foreach (explode(';', $matches[2]) as $declaration) {
            if (!preg_match('/^\s*(text-align|color|background-color|font-weight|font-style|text-decoration)\s*:\s*(.+?)\s*$/i', $declaration, $parts)) continue;
            $value = trim($parts[2]);
            if ($value === '' || preg_match('/(?:expression|url\s*\(|javascript|behavior|@import)/i', $value)) continue;
            if (preg_match('/^[#a-z0-9\s(),.%\-]+$/i', $value)) $safe[] = strtolower($parts[1]) . ': ' . $value;
        }
        return $safe ? ' style="' . implode('; ', $safe) . '"' : '';
    }
}
