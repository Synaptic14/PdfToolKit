<?php

declare(strict_types=1);

namespace PdfToolkit\Navigation;

final readonly class ViewerPreferences
{
    public const PRINT_SCALING_APP_DEFAULT = 'AppDefault';
    public const PRINT_SCALING_NONE = 'None';

    public function __construct(
        public ?bool $hideToolbar = null,
        public ?bool $hideMenubar = null,
        public ?bool $hideWindowUI = null,
        public ?bool $fitWindow = null,
        public ?bool $centerWindow = null,
        public ?bool $displayDocTitle = null,
        public ?string $printScaling = null,
    ) {
    }
}
