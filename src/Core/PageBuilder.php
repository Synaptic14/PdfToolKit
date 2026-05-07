<?php

declare(strict_types=1);

namespace PdfToolkit\Core;

final readonly class PageBuilder
{
    public function __construct(
        private Page $page,
        private DocumentBuilder $documentBuilder,
    ) {
    }

    public function page(): Page
    {
        return $this->page;
    }

    public function done(): DocumentBuilder
    {
        return $this->documentBuilder->endPage();
    }
}
