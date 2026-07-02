<?php

/**
 * Trait compartido para escapar XML respetando etiquetas <italic>.
 */
trait EscapeXmlTrait
{
    private function escapeXmlWithItalic($text)
    {
        $text = (string) $text;
        $text = str_replace("\xE2\x80\x93", '-', $text);
        $result = '';
        $offset = 0;
        while (preg_match('/<italic>(.*?)<\/italic>/su', $text, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $matchText = $matches[0][0];
            $matchPos = $matches[0][1];
            $prefix = substr($text, $offset, $matchPos - $offset);
            $result .= htmlspecialchars($prefix, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $result .= '<italic>' . htmlspecialchars($matches[1][0], ENT_QUOTES | ENT_XML1, 'UTF-8') . '</italic>';
            $offset = $matchPos + strlen($matchText);
        }
        $result .= htmlspecialchars(substr($text, $offset), ENT_QUOTES | ENT_XML1, 'UTF-8');
        return $result;
    }
}
