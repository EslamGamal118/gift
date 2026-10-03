<?php

namespace App\Support;

/**
 * Search text normalization shared by PHP (the customer's keyword) and MySQL
 * (the `search_text` generated columns), so both sides compare the same form:
 * lower case, no tashkeel / tatweel, unified alef / teh marbuta / alef maksura.
 */
final class SearchText
{
    /**
     * Applied in order, both in PHP and in the generated column SQL.
     *
     * @var array<string, string>
     */
    public const REPLACEMENTS = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ة' => 'ه',
        'ى' => 'ي', 'ئ' => 'ي',
        'ؤ' => 'و',
        'ـ' => '',
        // Tashkeel
        "\u{064B}" => '', "\u{064C}" => '', "\u{064D}" => '', "\u{064E}" => '', "\u{064F}" => '',
        "\u{0650}" => '', "\u{0651}" => '', "\u{0652}" => '', "\u{0670}" => '',
    ];

    /** Shorter tokens are matched exactly, never fuzzily. */
    public const MIN_FUZZY_LENGTH = 4;

    public static function normalize(string $text): string
    {
        $text = mb_strtolower(strtr($text, self::REPLACEMENTS));
        $text = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
    }

    /**
     * Distinct words of the normalized text (letters and digits only, so they
     * are safe inside a LIKE pattern).
     *
     * @return array<int, string>
     */
    public static function tokens(string $text, int $limit = 6): array
    {
        $words = array_filter(explode(' ', self::normalize($text)), fn (string $word) => $word !== '');

        return array_slice(array_values(array_unique($words)), 0, $limit);
    }

    /**
     * MySQL expression normalizing `$expression` like normalize() (punctuation
     * is kept: matching is by substring, so it does not get in the way).
     */
    public static function sql(string $expression): string
    {
        $sql = "LOWER({$expression})";

        foreach (self::REPLACEMENTS as $from => $to) {
            $sql = "REPLACE({$sql}, '{$from}', '{$to}')";
        }

        return $sql;
    }

    /**
     * Allowed typo distance for a token: none for short words, 1 up to 5 letters, then 2.
     */
    public static function maxDistance(string $token): int
    {
        $length = mb_strlen($token);

        return match (true) {
            $length < self::MIN_FUZZY_LENGTH => 0,
            $length <= 5                     => 1,
            default                          => 2,
        };
    }

    /**
     * Multibyte-safe Levenshtein distance (PHP's levenshtein() counts bytes,
     * so every Arabic letter would cost 2). Stops early above `$max`.
     */
    public static function distance(string $a, string $b, int $max = PHP_INT_MAX): int
    {
        $a = mb_str_split($a);
        $b = mb_str_split($b);

        if (abs(count($a) - count($b)) > $max) {
            return $max + 1;
        }

        $previous = range(0, count($b));

        foreach ($a as $i => $charA) {
            $current = [$i + 1];
            $rowMin  = $i + 1;

            foreach ($b as $j => $charB) {
                $current[$j + 1] = min(
                    $previous[$j + 1] + 1,
                    $current[$j] + 1,
                    $previous[$j] + ($charA === $charB ? 0 : 1),
                );
                $rowMin = min($rowMin, $current[$j + 1]);
            }

            if ($rowMin > $max) {
                return $max + 1;
            }

            $previous = $current;
        }

        return $previous[count($b)];
    }
}
