<?php

declare(strict_types=1);

namespace PdfToolkit\Tests\Support;

final class CorpusFixtures
{
    /**
     * @return list<CorpusFixture>
     */
    public static function all(): array
    {
        $root = dirname(__DIR__, 2);

        return [
            new CorpusFixture(
                name: 'irs-1099-misc',
                path: $root . '/examples/f1099msc.pdf',
                expectedPageCount: 6,
                expectedVersion: '1.7',
                workflows: ['load', 'roundtrip', 'overlay', 'form-fill'],
            ),
            new CorpusFixture(
                name: 'chubb-application-form',
                path: $root . '/examples/chubb_application_form.pdf',
                expectedPageCount: 4,
                expectedVersion: '1.3',
                workflows: ['load', 'roundtrip', 'overlay'],
            ),
        ];
    }
}
