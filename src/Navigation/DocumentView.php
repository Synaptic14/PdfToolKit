<?php

declare(strict_types=1);

namespace PdfToolkit\Navigation;

final class DocumentView
{
    public const PAGE_LAYOUT_SINGLE_PAGE = 'SinglePage';
    public const PAGE_LAYOUT_ONE_COLUMN = 'OneColumn';
    public const PAGE_LAYOUT_TWO_COLUMN_LEFT = 'TwoColumnLeft';
    public const PAGE_LAYOUT_TWO_COLUMN_RIGHT = 'TwoColumnRight';
    public const PAGE_LAYOUT_TWO_PAGE_LEFT = 'TwoPageLeft';
    public const PAGE_LAYOUT_TWO_PAGE_RIGHT = 'TwoPageRight';

    public const PAGE_MODE_USE_NONE = 'UseNone';
    public const PAGE_MODE_USE_OUTLINES = 'UseOutlines';
    public const PAGE_MODE_USE_THUMBS = 'UseThumbs';
    public const PAGE_MODE_FULL_SCREEN = 'FullScreen';
    public const PAGE_MODE_USE_OC = 'UseOC';
    public const PAGE_MODE_USE_ATTACHMENTS = 'UseAttachments';

    private function __construct()
    {
    }
}
