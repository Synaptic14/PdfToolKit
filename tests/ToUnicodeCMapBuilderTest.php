<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Text\CharacterMap;
use PdfToolkit\Writer\ToUnicodeCMapBuilder;
use PHPUnit\Framework\TestCase;

final class ToUnicodeCMapBuilderTest extends TestCase
{
    public function testBuildCompositePreservesOriginalSourceAcrossExpandedMultipleSubstitutionTokens(): void
    {
        $cmap = (new ToUnicodeCMapBuilder())->buildComposite([
            CharacterMap::sourceKey("\u{00E4}", 'a') => 1,
            CharacterMap::sourceKey('', 'b') => 2,
        ]);

        $this->assertStringContainsString('<0001> <00E4>', $cmap);
        $this->assertStringContainsString('<0002> <>', $cmap);
    }
}
