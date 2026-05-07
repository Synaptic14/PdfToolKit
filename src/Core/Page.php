<?php

declare(strict_types=1);

namespace PdfToolkit\Core;

use PdfToolkit\Annotations\LinkAnnotation;
use PdfToolkit\Annotations\TextAnnotation;
use PdfToolkit\Forms\FormField;
use PdfToolkit\Graphics\Line;
use PdfToolkit\Graphics\Rectangle;
use PdfToolkit\Image\ImagePlacement;
use PdfToolkit\Text\TextRun;

final class Page
{
    private const PAGE_BOX_NAMES = ['CropBox', 'BleedBox', 'TrimBox', 'ArtBox'];

    /**
     * @var list<TextRun>
     */
    private array $texts = [];

    /**
     * @var list<Line>
     */
    private array $lines = [];

    /**
     * @var list<Rectangle>
     */
    private array $rectangles = [];

    /**
     * @var list<ImagePlacement>
     */
    private array $images = [];

    /**
     * @var list<FormField>
     */
    private array $formFields = [];

    /**
     * @var list<TextAnnotation>
     */
    private array $textAnnotations = [];

    /**
     * @var list<LinkAnnotation>
     */
    private array $linkAnnotations = [];

    private ?ImportedPageSource $importedSource = null;

    public function __construct(
        private float $width,
        private float $height,
        private int $rotation = 0,
        private array $pageBoxes = [],
    ) {
    }

    public static function a4(): self
    {
        return new self(595.0, 842.0);
    }

    public function width(): float
    {
        return $this->width;
    }

    public function height(): float
    {
        return $this->height;
    }

    public function setSize(float $width, float $height): void
    {
        if ($width <= 0 || $height <= 0) {
            throw new PdfException('Page width and height must be greater than zero.');
        }

        $this->width = $width;
        $this->height = $height;
    }

    public function rotation(): int
    {
        return $this->rotation;
    }

    public function setRotation(int $rotation): void
    {
        if (!in_array($rotation, [0, 90, 180, 270], true)) {
            throw new PdfException('Page rotation must be one of 0, 90, 180, or 270 degrees.');
        }

        $this->rotation = $rotation;
    }

    /**
     * @return array<string, list<float>>
     */
    public function pageBoxes(): array
    {
        return $this->pageBoxes;
    }

    /**
     * @param list<float> $box
     */
    public function setPageBox(string $name, array $box): void
    {
        $this->pageBoxes[$this->normalizePageBoxName($name)] = $this->normalizePageBox($box);
    }

    /**
     * @param list<float> $box
     */
    public function setCropBox(array $box): void
    {
        $this->setPageBox('CropBox', $box);
    }

    /**
     * @param list<float> $box
     */
    public function setBleedBox(array $box): void
    {
        $this->setPageBox('BleedBox', $box);
    }

    /**
     * @param list<float> $box
     */
    public function setTrimBox(array $box): void
    {
        $this->setPageBox('TrimBox', $box);
    }

    /**
     * @param list<float> $box
     */
    public function setArtBox(array $box): void
    {
        $this->setPageBox('ArtBox', $box);
    }

    public function addText(TextRun $text): void
    {
        $this->texts[] = $text;
    }

    /**
     * @return list<TextRun>
     */
    public function texts(): array
    {
        return $this->texts;
    }

    public function addLine(Line $line): void
    {
        $this->lines[] = $line;
    }

    /**
     * @return list<Line>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function addRectangle(Rectangle $rectangle): void
    {
        $this->rectangles[] = $rectangle;
    }

    /**
     * @return list<Rectangle>
     */
    public function rectangles(): array
    {
        return $this->rectangles;
    }

    public function addImage(ImagePlacement $image): void
    {
        $this->images[] = $image;
    }

    /**
     * @return list<ImagePlacement>
     */
    public function images(): array
    {
        return $this->images;
    }

    public function addFormField(FormField $field): void
    {
        $this->formFields[] = $field;
    }

    /**
     * @return list<FormField>
     */
    public function formFields(): array
    {
        return $this->formFields;
    }

    public function clearFormFields(): void
    {
        $this->formFields = [];
    }

    public function addTextAnnotation(TextAnnotation $annotation): void
    {
        $this->textAnnotations[] = $annotation;
    }

    /**
     * @return list<TextAnnotation>
     */
    public function textAnnotations(): array
    {
        return $this->textAnnotations;
    }

    public function addLinkAnnotation(LinkAnnotation $annotation): void
    {
        $this->linkAnnotations[] = $annotation;
    }

    /**
     * @return list<LinkAnnotation>
     */
    public function linkAnnotations(): array
    {
        return $this->linkAnnotations;
    }

    public function importedSource(): ?ImportedPageSource
    {
        return $this->importedSource;
    }

    public function setImportedSource(?ImportedPageSource $importedSource): void
    {
        $this->importedSource = $importedSource;
    }

    private function normalizePageBoxName(string $name): string
    {
        foreach (self::PAGE_BOX_NAMES as $allowedName) {
            if (strcasecmp($name, $allowedName) === 0) {
                return $allowedName;
            }
        }

        throw new PdfException(sprintf('Unsupported page box: %s', $name));
    }

    /**
     * @param list<float> $box
     * @return list<float>
     */
    private function normalizePageBox(array $box): array
    {
        if (count($box) !== 4) {
            throw new PdfException('Page boxes must contain exactly four numeric values.');
        }

        $values = array_values($box);

        foreach ($values as $value) {
            if (!is_int($value) && !is_float($value)) {
                throw new PdfException('Page boxes must contain exactly four numeric values.');
            }
        }

        if ($values[2] <= $values[0] || $values[3] <= $values[1]) {
            throw new PdfException('Page box upper-right coordinates must be greater than lower-left coordinates.');
        }

        return array_map(static fn (int|float $value): float => (float) $value, $values);
    }
}
