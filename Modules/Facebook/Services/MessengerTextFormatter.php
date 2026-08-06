<?php

namespace Modules\Facebook\Services;

use Normalizer;

final class MessengerTextFormatter
{
    /**
     * Messenger text bubbles do not render Markdown. Keep the content plain,
     * but preserve emphasis and structure with Unicode text and readable bullets.
     */
    public static function format(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        $text = preg_replace('/```(?:[a-z]+)?\s*/iu', '', $text) ?? $text;
        $text = str_replace('```', '', $text);

        $text = preg_replace_callback('/\*\*([^*\n]{1,160})\*\*/u', static function (array $matches): string {
            return self::bold($matches[1]);
        }, $text) ?? $text;
        $text = preg_replace_callback('/__([^_\n]{1,160})__/u', static function (array $matches): string {
            return self::bold($matches[1]);
        }, $text) ?? $text;

        $text = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/u', '$1 ($2)', $text) ?? $text;
        $text = preg_replace('/`([^`\n]+)`/u', '$1', $text) ?? $text;
        $text = preg_replace('/^[ \t]*[-*][ \t]+/mu', '• ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /** Chuyển văn bản hoặc ký tự sang kiểu chữ đậm tương thích Messenger. */
    private static function bold(string $text): string
    {
        $text = trim($text);
        $decomposed = class_exists(Normalizer::class)
            ? (Normalizer::normalize($text, Normalizer::FORM_D) ?: $text)
            : $text;

        $result = '';
        foreach (preg_split('//u', $decomposed, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            $result .= self::boldCharacter($character);
        }

        return $result;
    }

    /** Chuyển văn bản hoặc ký tự sang kiểu chữ đậm tương thích Messenger. */
    private static function boldCharacter(string $character): string
    {
        $codePoint = mb_ord($character, 'UTF-8');

        return match (true) {
            $codePoint >= 65 && $codePoint <= 90 => mb_chr(0x1D400 + ($codePoint - 65), 'UTF-8'),
            $codePoint >= 97 && $codePoint <= 122 => mb_chr(0x1D41A + ($codePoint - 97), 'UTF-8'),
            $codePoint >= 48 && $codePoint <= 57 => mb_chr(0x1D7CE + ($codePoint - 48), 'UTF-8'),
            default => $character,
        };
    }
}
