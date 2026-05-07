<?php

declare(strict_types=1);

namespace PdfToolkit\Text;

final class TrueTypeTextShaper
{
    public function shape(string $text, ParsedTrueTypeFont $font): string
    {
        return implode('', array_map(
            static fn (array $token): string => $token['display'],
            $this->shapeTokens($text, $font)
        ));
    }

    /**
     * @return list<array{key: string, display: string}>
     */
    public function shapeTokens(string $text, ParsedTrueTypeFont $font): array
    {
        if ($text === '' || ($font->singleSubstitutions === [] && $font->alternateSubstitutions === [] && $font->multipleSubstitutions === [] && $font->ligatureSubstitutions === [])) {
            return $this->baseTokens($text, $font);
        }

        $tokens = $this->baseTokens($text, $font);
        $shaped = $this->applyLigatures($tokens, $font);

        return array_map(
            static fn (array $token): array => [
                'key' => $token['key'],
                'display' => $token['display'],
            ],
            $shaped,
        );
    }

    /**
     * @param list<array{key: string, display: string, glyphId: int, source: string}> $tokens
     * @return list<array{key: string, display: string, glyphId: int, source: string}>
     */
    private function applyLigatures(array $tokens, ParsedTrueTypeFont $font): array
    {
        if ($tokens === [] || $font->ligatureSubstitutions === []) {
            return $tokens;
        }

        $maxSequenceLength = $this->maxLigatureSequenceLength($font);
        $maxPasses = max(1, count($tokens));

        for ($pass = 0; $pass < $maxPasses; $pass++) {
            $shaped = [];
            $changed = false;

            for ($index = 0; $index < count($tokens);) {
                $matched = false;
                $maxLength = min($maxSequenceLength, count($tokens) - $index);

                for ($length = $maxLength; $length >= 2; $length--) {
                    $sequence = array_column(array_slice($tokens, $index, $length), 'glyphId');

                    if (in_array(0, $sequence, true)) {
                        continue;
                    }

                    $ligatureGlyphId = $font->ligatureGlyphIdForSequence($sequence);

                    if ($ligatureGlyphId === null) {
                        continue;
                    }

                    $codePoint = $font->codePointForGlyphId($ligatureGlyphId);

                    if ($codePoint === null) {
                        continue;
                    }

                    $display = mb_chr($codePoint);
                    $sourceText = implode('', array_column(array_slice($tokens, $index, $length), 'source'));
                    $shaped[] = [
                        'key' => CharacterMap::sourceKey($sourceText, $display),
                        'display' => $display,
                        'glyphId' => $ligatureGlyphId,
                        'source' => $sourceText,
                    ];
                    $index += $length;
                    $matched = true;
                    $changed = true;
                    break;
                }

                if ($matched) {
                    continue;
                }

                $shaped[] = [
                    'key' => $tokens[$index]['key'],
                    'display' => $tokens[$index]['display'],
                    'glyphId' => $tokens[$index]['glyphId'],
                    'source' => $tokens[$index]['source'],
                ];
                $index++;
            }

            $tokens = $shaped;

            if (!$changed) {
                break;
            }
        }

        return $tokens;
    }

    private function maxLigatureSequenceLength(ParsedTrueTypeFont $font): int
    {
        $max = 1;

        foreach (array_keys($font->ligatureSubstitutions) as $sequenceKey) {
            $max = max($max, substr_count($sequenceKey, ':') + 1);
        }

        return $max;
    }

    /**
     * @return list<array{key: string, display: string, glyphId: int, source: string}>
     */
    private function baseTokens(string $text, ParsedTrueTypeFont $font): array
    {
        $tokens = [];

        foreach (mb_str_split($text) as $character) {
            $glyphId = $font->glyphIdForCodePoint(mb_ord($character)) ?? 0;
            $resolvedMultipleSubstitutionTokens = $this->resolveDisplayableMultipleSubstitutionTokens($glyphId, $font);

            if ($resolvedMultipleSubstitutionTokens !== null) {
                foreach ($resolvedMultipleSubstitutionTokens as $index => $resolvedToken) {
                    $tokens[] = [
                        'key' => $index === 0
                            ? CharacterMap::sourceKey($character, $resolvedToken['display'])
                            : CharacterMap::sourceKey('', $resolvedToken['display']),
                        'display' => $resolvedToken['display'],
                        'glyphId' => $resolvedToken['glyphId'],
                        'source' => $index === 0 ? $character : '',
                    ];
                }

                continue;
            }

            $resolvedSubstitution = $this->resolveDisplayableSubstitutionGlyph($glyphId, $font);

            if ($resolvedSubstitution !== null) {
                $tokens[] = [
                    'key' => $resolvedSubstitution['display'] === $character
                        ? CharacterMap::key($character)
                        : CharacterMap::sourceKey($character, $resolvedSubstitution['display']),
                    'display' => $resolvedSubstitution['display'],
                    'glyphId' => $resolvedSubstitution['glyphId'],
                    'source' => $character,
                ];

                continue;
            }

            $tokens[] = [
                'key' => CharacterMap::key($character),
                'display' => $character,
                'glyphId' => $glyphId,
                'source' => $character,
            ];
        }

        return $tokens;
    }

    /**
     * @param array<int, true> $visitedGlyphIds
     * @return list<array{glyphId: int, display: string}>|null
     */
    private function resolveDisplayableMultipleSubstitutionTokens(
        int $glyphId,
        ParsedTrueTypeFont $font,
        array $visitedGlyphIds = [],
    ): ?array {
        if (isset($visitedGlyphIds[$glyphId])) {
            return null;
        }

        $visitedGlyphIds[$glyphId] = true;
        $multipleReplacementGlyphIds = $font->multipleSubstitutionGlyphIdsForGlyphId($glyphId);

        if ($multipleReplacementGlyphIds !== []) {
            $tokens = [];

            foreach ($multipleReplacementGlyphIds as $replacementGlyphId) {
                $resolved = $this->resolveDisplayableMultipleSubstitutionTokens($replacementGlyphId, $font, $visitedGlyphIds);

                if ($resolved !== null) {
                    $tokens = [...$tokens, ...$resolved];
                    continue;
                }

                $resolvedSingle = $this->resolveDisplayableSubstitutionGlyph($replacementGlyphId, $font, $visitedGlyphIds);

                if ($resolvedSingle !== null) {
                    $tokens[] = $resolvedSingle;
                    continue;
                }

                $codePoint = $font->codePointForGlyphId($replacementGlyphId);

                if ($codePoint === null) {
                    return null;
                }

                $tokens[] = [
                    'glyphId' => $replacementGlyphId,
                    'display' => mb_chr($codePoint),
                ];
            }

            return $tokens === [] ? null : $tokens;
        }

        return null;
    }

    /**
     * @param array<int, true> $visitedGlyphIds
     * @return array{glyphId: int, display: string}|null
     */
    private function resolveDisplayableSubstitutionGlyph(
        int $glyphId,
        ParsedTrueTypeFont $font,
        array $visitedGlyphIds = [],
    ): ?array {
        if (isset($visitedGlyphIds[$glyphId])) {
            return null;
        }

        $visitedGlyphIds[$glyphId] = true;

        $singleReplacementGlyphId = $font->singleSubstitutionGlyphIdForGlyphId($glyphId);

        if ($singleReplacementGlyphId !== null) {
            $resolved = $this->resolveDisplayableSubstitutionGlyph($singleReplacementGlyphId, $font, $visitedGlyphIds);

            if ($resolved !== null) {
                return $resolved;
            }

            $codePoint = $font->codePointForGlyphId($singleReplacementGlyphId);

            if ($codePoint !== null) {
                return [
                    'glyphId' => $singleReplacementGlyphId,
                    'display' => mb_chr($codePoint),
                ];
            }
        }

        foreach ($font->alternateSubstitutionGlyphIdsForGlyphId($glyphId) as $alternateGlyphId) {
            if (isset($visitedGlyphIds[$alternateGlyphId])) {
                continue;
            }

            $resolved = $this->resolveDisplayableSubstitutionGlyph($alternateGlyphId, $font, $visitedGlyphIds);

            if ($resolved !== null) {
                return $resolved;
            }

            $codePoint = $font->codePointForGlyphId($alternateGlyphId);

            if ($codePoint !== null) {
                return [
                    'glyphId' => $alternateGlyphId,
                    'display' => mb_chr($codePoint),
                ];
            }
        }

        return null;
    }
}
