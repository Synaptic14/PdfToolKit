<?php

declare(strict_types=1);

namespace PdfToolkit\Import;

use PdfToolkit\Annotations\LinkAnnotation;
use PdfToolkit\Annotations\TextAnnotation;
use PdfToolkit\Core\ImportedContentOperation;
use PdfToolkit\Core\ImportedContentStream;
use PdfToolkit\Core\ImportedPageSource;
use PdfToolkit\Core\Page;
use PdfToolkit\Graphics\Color;
use PdfToolkit\Graphics\Line;
use PdfToolkit\Graphics\Rectangle;
use PdfToolkit\Parser\ContentStreamSerializer;
use PdfToolkit\Parser\PdfName;
use PdfToolkit\Parser\PdfLiteralString;
use PdfToolkit\Parser\PdfReference;
use PdfToolkit\Parser\PdfValueParser;
use PdfToolkit\Parser\PdfValueSerializer;
use PdfToolkit\Text\TextRun;

final readonly class ImportedPageEditor
{
    public function __construct(
        private Page $page,
        private ImportedPageCollection $collection,
    ) {
    }

    public function overlayText(
        string $text,
        float $x,
        float $y,
        float $fontSize = 12.0
    ): self {
        $this->page->addText(new TextRun($text, $x, $y, $fontSize));

        return $this;
    }

    public function overlayLine(
        float $x1,
        float $y1,
        float $x2,
        float $y2,
        float $width = 1.0,
        ?Color $strokeColor = null,
    ): self {
        $this->page->addLine(new Line($x1, $y1, $x2, $y2, $width, $strokeColor));

        return $this;
    }

    public function overlayRectangle(
        float $x,
        float $y,
        float $width,
        float $height,
        ?Color $strokeColor = null,
        ?Color $fillColor = null,
        float $lineWidth = 1.0,
    ): self {
        $this->page->addRectangle(new Rectangle($x, $y, $width, $height, $strokeColor, $fillColor, $lineWidth));

        return $this;
    }

    public function redactArea(
        float $x,
        float $y,
        float $width,
        float $height,
        ?Color $fillColor = null,
    ): self {
        return $this->overlayRectangle($x, $y, $width, $height, fillColor: $fillColor ?? Color::black(), lineWidth: 0.0);
    }

    public function setRotation(int $rotation): self
    {
        $this->page->setRotation($rotation);

        return $this;
    }

    public function setSize(float $width, float $height): self
    {
        $this->page->setSize($width, $height);

        return $this;
    }

    public function addTextAnnotation(
        string $contents,
        float $x,
        float $y,
        float $width = 24.0,
        float $height = 24.0,
        bool $open = false,
        string $icon = 'Note',
    ): self {
        $this->page->addTextAnnotation(new TextAnnotation($contents, $x, $y, $width, $height, $open, $icon));

        return $this;
    }

    public function addLink(
        string $uri,
        float $x,
        float $y,
        float $width,
        float $height,
        bool $border = false,
    ): self {
        $this->page->addLinkAnnotation(new LinkAnnotation($uri, $x, $y, $width, $height, $border));

        return $this;
    }

    public function addPageLink(
        int $pageNumber,
        float $x,
        float $y,
        float $width,
        float $height,
        bool $border = false,
        ?float $left = null,
        ?float $top = null,
        ?float $zoom = null,
    ): self {
        $this->page->addLinkAnnotation(LinkAnnotation::toPage($pageNumber, $x, $y, $width, $height, $border, $left, $top, $zoom));

        return $this;
    }

    public function addNamedDestinationLink(
        string $destinationName,
        float $x,
        float $y,
        float $width,
        float $height,
        bool $border = false,
    ): self {
        $this->page->addLinkAnnotation(LinkAnnotation::toNamedDestination($destinationName, $x, $y, $width, $height, $border));

        return $this;
    }

    public function clearImportedAnnotations(): self
    {
        $source = $this->page->importedSource();

        if ($source === null) {
            return $this;
        }

        $pageDictionary = $source->pageDictionary;
        $annotationRoots = self::referencedObjectNumbers($pageDictionary['Annots'] ?? null);
        unset($pageDictionary['Annots']);

        $dependentObjects = $this->removeDependentObjectGraph($source->dependentObjects, $annotationRoots);

        $this->page->setImportedSource(new ImportedPageSource(
            objectNumber: $source->objectNumber,
            pageDictionary: $pageDictionary,
            resourceDictionary: $source->resourceDictionary,
            contentStreams: $source->contentStreams,
            dependentObjects: $dependentObjects,
            warnings: $source->warnings,
        ));

        return $this;
    }

    public function clearImportedAnnotationsBySubtype(string ...$subtypes): self
    {
        $normalizedSubtypes = [];

        foreach ($subtypes as $subtype) {
            $subtype = ltrim(trim($subtype), '/');

            if ($subtype === '') {
                throw new \InvalidArgumentException('Annotation subtypes must not be empty.');
            }

            $normalizedSubtypes[$subtype] = true;
        }

        if ($normalizedSubtypes === []) {
            return $this;
        }

        $source = $this->page->importedSource();

        if ($source === null) {
            return $this;
        }

        $annotations = $source->pageDictionary['Annots'] ?? null;

        if (!is_array($annotations)) {
            return $this;
        }

        $pageDictionary = $source->pageDictionary;
        $retainedAnnotations = [];
        $annotationRootsToRemove = [];

        foreach ($annotations as $annotation) {
            $subtype = self::annotationSubtype($annotation, $source->dependentObjects);

            if ($subtype !== null && isset($normalizedSubtypes[$subtype])) {
                foreach (self::referencedObjectNumbers($annotation) as $objectNumber) {
                    $annotationRootsToRemove[] = $objectNumber;
                }

                continue;
            }

            $retainedAnnotations[] = $annotation;
        }

        if ($retainedAnnotations === []) {
            unset($pageDictionary['Annots']);
        } else {
            $pageDictionary['Annots'] = $retainedAnnotations;
        }

        $dependentObjects = $this->removeDependentObjectGraph(
            $source->dependentObjects,
            array_values(array_unique($annotationRootsToRemove))
        );

        $this->page->setImportedSource(new ImportedPageSource(
            objectNumber: $source->objectNumber,
            pageDictionary: $pageDictionary,
            resourceDictionary: $source->resourceDictionary,
            contentStreams: $source->contentStreams,
            dependentObjects: $dependentObjects,
            warnings: $source->warnings,
        ));

        return $this;
    }

    public function clearImportedLinkAnnotations(): self
    {
        return $this->clearImportedAnnotationsBySubtype('Link');
    }

    public function replaceImportedTextAnnotationContents(string $search, string $replace): self
    {
        return $this->rewriteImportedAnnotations(
            static function (mixed $annotation, array &$dependentObjects) use ($search, $replace): mixed {
                $subtype = self::annotationSubtype($annotation, $dependentObjects);

                if ($subtype !== 'Text') {
                    return $annotation;
                }

                return self::replaceAnnotationLiteralStringValue($annotation, $dependentObjects, 'Contents', $search, $replace);
            }
        );
    }

    public function replaceImportedLinkUris(string $search, string $replace): self
    {
        return $this->rewriteImportedAnnotations(
            static function (mixed $annotation, array &$dependentObjects) use ($search, $replace): mixed {
                $subtype = self::annotationSubtype($annotation, $dependentObjects);

                if ($subtype !== 'Link') {
                    return $annotation;
                }

                return self::replaceLinkAnnotationUri($annotation, $dependentObjects, $search, $replace);
            }
        );
    }

    public function replaceImportedPageLinkDestinations(
        int $pageNumber,
        ?float $left = null,
        ?float $top = null,
        ?float $zoom = null,
    ): self {
        if ($pageNumber < 1) {
            throw new \InvalidArgumentException('Page numbers start at 1.');
        }

        $targetSource = $this->collection->document()->page($pageNumber - 1)->importedSource();

        if ($targetSource === null) {
            throw new \InvalidArgumentException('Imported page link destinations must target an imported page.');
        }

        $destination = self::pageDestinationArray($targetSource->objectNumber, $left, $top, $zoom);

        return $this->rewriteImportedAnnotations(
            static function (mixed $annotation, array &$dependentObjects) use ($destination): mixed {
                $subtype = self::annotationSubtype($annotation, $dependentObjects);

                if ($subtype !== 'Link') {
                    return $annotation;
                }

                return self::replaceLinkAnnotationPageDestination($annotation, $dependentObjects, $destination);
            }
        );
    }

    /**
     * @param list<float> $box
     */
    public function setPageBox(string $name, array $box): self
    {
        $this->page->setPageBox($name, $box);

        return $this;
    }

    /**
     * @param list<float> $box
     */
    public function setCropBox(array $box): self
    {
        $this->page->setCropBox($box);

        return $this;
    }

    /**
     * @param list<float> $box
     */
    public function setBleedBox(array $box): self
    {
        $this->page->setBleedBox($box);

        return $this;
    }

    /**
     * @param list<float> $box
     */
    public function setTrimBox(array $box): self
    {
        $this->page->setTrimBox($box);

        return $this;
    }

    /**
     * @param list<float> $box
     */
    public function setArtBox(array $box): self
    {
        $this->page->setArtBox($box);

        return $this;
    }

    public function importedSource(): ?\PdfToolkit\Core\ImportedPageSource
    {
        return $this->page->importedSource();
    }

    /**
     * @return list<\PdfToolkit\Core\ImportedContentOperation>
     */
    public function operations(): array
    {
        $source = $this->page->importedSource();

        if ($source === null) {
            return [];
        }

        $operations = [];

        foreach ($source->contentStreams as $stream) {
            foreach ($stream->operations as $operation) {
                $operations[] = $operation;
            }
        }

        return $operations;
    }

    public function replaceText(string $search, string $replace): self
    {
        return $this->rewriteStreams(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => new ImportedContentOperation(
                operator: $operation->operator,
                operands: self::replaceOperands($operation->operands, $search, $replace),
            )
        );
    }

    public function translate(float $dx, float $dy): self
    {
        return $this->rewriteStreams(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => self::translateOperation($operation, $dx, $dy)
        );
    }

    public function setLineWidth(float $width): self
    {
        if ($width < 0) {
            throw new \InvalidArgumentException('Line width must be greater than or equal to zero.');
        }

        return $this->rewriteStreams(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => self::rewriteSingleNumericOperandOperation(
                $operation,
                'w',
                $width
            )
        );
    }

    public function setStrokeColor(Color $color): self
    {
        return $this->rewriteStreams(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => self::rewriteColorOperation(
                $operation,
                grayscaleOperator: 'G',
                rgbOperator: 'RG',
                cmykOperator: 'K',
                color: $color
            )
        );
    }

    public function setFillColor(Color $color): self
    {
        return $this->rewriteStreams(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => self::rewriteColorOperation(
                $operation,
                grayscaleOperator: 'g',
                rgbOperator: 'rg',
                cmykOperator: 'k',
                color: $color
            )
        );
    }

    public function setLineCap(int $lineCap): self
    {
        if (!in_array($lineCap, [0, 1, 2], true)) {
            throw new \InvalidArgumentException('Line cap must be 0, 1, or 2.');
        }

        return $this->rewriteStreams(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => self::rewriteSingleNumericOperandOperation(
                $operation,
                'J',
                (float) $lineCap
            )
        );
    }

    public function setLineJoin(int $lineJoin): self
    {
        if (!in_array($lineJoin, [0, 1, 2], true)) {
            throw new \InvalidArgumentException('Line join must be 0, 1, or 2.');
        }

        return $this->rewriteStreams(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => self::rewriteSingleNumericOperandOperation(
                $operation,
                'j',
                (float) $lineJoin
            )
        );
    }

    public function setMiterLimit(float $miterLimit): self
    {
        if ($miterLimit <= 0) {
            throw new \InvalidArgumentException('Miter limit must be greater than zero.');
        }

        return $this->rewriteStreams(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => self::rewriteSingleNumericOperandOperation(
                $operation,
                'M',
                $miterLimit
            )
        );
    }

    /**
     * @param list<float|int> $pattern
     */
    public function setDashPattern(array $pattern, float $phase = 0.0): self
    {
        if ($phase < 0) {
            throw new \InvalidArgumentException('Dash phase must be greater than or equal to zero.');
        }

        $normalizedPattern = [];

        foreach ($pattern as $value) {
            if ((!is_int($value) && !is_float($value)) || $value < 0) {
                throw new \InvalidArgumentException('Dash pattern values must be numbers greater than or equal to zero.');
            }

            $normalizedPattern[] = (float) $value;
        }

        return $this->rewriteStreams(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => self::rewriteDashPatternOperation(
                $operation,
                $normalizedPattern,
                $phase
            )
        );
    }

    public function setCharacterSpacing(float $spacing): self
    {
        return $this->rewriteStreams(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => self::rewriteSingleNumericOperandOperation(
                $operation,
                'Tc',
                $spacing
            )
        );
    }

    public function setWordSpacing(float $spacing): self
    {
        return $this->rewriteStreams(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => self::rewriteSingleNumericOperandOperation(
                $operation,
                'Tw',
                $spacing
            )
        );
    }

    public function setHorizontalScaling(float $scale): self
    {
        if ($scale <= 0) {
            throw new \InvalidArgumentException('Horizontal scaling must be greater than zero.');
        }

        return $this->rewriteStreams(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => self::rewriteSingleNumericOperandOperation(
                $operation,
                'Tz',
                $scale
            )
        );
    }

    public function setLeading(float $leading): self
    {
        return $this->rewriteStreams(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => self::rewriteSingleNumericOperandOperation(
                $operation,
                'TL',
                $leading
            )
        );
    }

    public function setTextRise(float $rise): self
    {
        return $this->rewriteStreams(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => self::rewriteSingleNumericOperandOperation(
                $operation,
                'Ts',
                $rise
            )
        );
    }

    public function setTextRenderingMode(int $mode): self
    {
        if ($mode < 0 || $mode > 7) {
            throw new \InvalidArgumentException('Text rendering mode must be between 0 and 7.');
        }

        return $this->rewriteStreams(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => self::rewriteSingleNumericOperandOperation(
                $operation,
                'Tr',
                (float) $mode
            )
        );
    }

    public function setFontSize(float $size): self
    {
        if ($size <= 0) {
            throw new \InvalidArgumentException('Font size must be greater than zero.');
        }

        return $this->rewriteStreams(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => self::rewriteTextFontSizeOperation(
                $operation,
                $size
            )
        );
    }

    public function setTextPosition(float $x, float $y): self
    {
        return $this->rewriteStreams(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => self::rewriteTextPositionOperation(
                $operation,
                $x,
                $y
            )
        );
    }

    public function translateTextPosition(float $dx, float $dy): self
    {
        return $this->rewriteStreams(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => self::translateTextPositionOperation(
                $operation,
                $dx,
                $dy
            )
        );
    }

    public function setTextMatrix(
        float $a,
        float $b,
        float $c,
        float $d,
        float $e,
        float $f,
    ): self {
        return $this->rewriteStreams(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => self::rewriteTextMatrixOperation(
                $operation,
                $a,
                $b,
                $c,
                $d,
                $e,
                $f,
            )
        );
    }

    public function setRenderingIntent(string $intent): self
    {
        $intent = ltrim(trim($intent), '/');

        if ($intent === '') {
            throw new \InvalidArgumentException('Rendering intent must not be empty.');
        }

        return $this->rewriteStreams(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => self::rewriteNamedIntentOperation(
                $operation,
                $intent
            )
        );
    }

    public function setFlatness(float $flatness): self
    {
        if ($flatness < 0 || $flatness > 100) {
            throw new \InvalidArgumentException('Flatness must be between 0 and 100.');
        }

        return $this->rewriteStreams(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => self::rewriteSingleNumericOperandOperation(
                $operation,
                'i',
                $flatness
            )
        );
    }

    public function setGraphicsMatrix(
        float $a,
        float $b,
        float $c,
        float $d,
        float $e,
        float $f,
    ): self {
        return $this->rewriteStreams(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => self::rewriteGraphicsMatrixOperation(
                $operation,
                $a,
                $b,
                $c,
                $d,
                $e,
                $f,
            )
        );
    }

    public function setPathPaintingOperator(string $operator): self
    {
        $operator = trim($operator);

        if (!in_array($operator, ['S', 's', 'f', 'F', 'f*', 'B', 'B*', 'b', 'b*', 'n'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported path painting operator: %s', $operator));
        }

        return $this->rewriteStreams(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => self::rewritePathPaintingOperation(
                $operation,
                $operator
            )
        );
    }

    public function setClippingRule(string $rule): self
    {
        $rule = strtolower(trim($rule));

        $operator = match ($rule) {
            'nonzero' => 'W',
            'evenodd' => 'W*',
            default => throw new \InvalidArgumentException(sprintf('Unsupported clipping rule: %s', $rule)),
        };

        return $this->rewriteStreams(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => self::rewriteClippingRuleOperation(
                $operation,
                $operator
            )
        );
    }

    public function setAutoClosePathPainting(bool $close = true): self
    {
        return $this->rewriteStreams(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => self::rewriteAutoClosePathPaintingOperation(
                $operation,
                $close
            )
        );
    }

    public function renameFontResource(string $from, string $to): self
    {
        return $this->renameResourceReference('Font', 'Tf', $from, $to);
    }

    public function renameXObjectResource(string $from, string $to): self
    {
        return $this->renameResourceReference('XObject', 'Do', $from, $to);
    }

    public function renameGraphicsStateResource(string $from, string $to): self
    {
        return $this->renameResourceReference('ExtGState', 'gs', $from, $to);
    }

    public function setStrokeAlpha(float $alpha): self
    {
        self::assertAlphaValue($alpha);

        return $this->rewriteExtGStateAlpha('CA', $alpha);
    }

    public function setFillAlpha(float $alpha): self
    {
        self::assertAlphaValue($alpha);

        return $this->rewriteExtGStateAlpha('ca', $alpha);
    }

    public function setBlendMode(string $blendMode): self
    {
        $blendMode = ltrim(trim($blendMode), '/');

        if ($blendMode === '') {
            throw new \InvalidArgumentException('Blend mode must not be empty.');
        }

        return $this->rewriteExtGStateValue('BM', $blendMode);
    }

    public function setStrokeOverprint(bool $enabled = true): self
    {
        return $this->rewriteExtGStateValue('OP', $enabled);
    }

    public function setFillOverprint(bool $enabled = true): self
    {
        return $this->rewriteExtGStateValue('op', $enabled);
    }

    public function setOverprintMode(int $mode): self
    {
        if (!in_array($mode, [0, 1], true)) {
            throw new \InvalidArgumentException('Overprint mode must be 0 or 1.');
        }

        return $this->rewriteExtGStateValue('OPM', $mode);
    }

    public function setStrokeAdjustment(bool $enabled = true): self
    {
        return $this->rewriteExtGStateValue('SA', $enabled);
    }

    public function setAlphaIsShape(bool $enabled = true): self
    {
        return $this->rewriteExtGStateValue('AIS', $enabled);
    }

    public function setTextKnockout(bool $enabled = true): self
    {
        return $this->rewriteExtGStateValue('TK', $enabled);
    }

    public function setGraphicsStateRenderingIntent(string $intent): self
    {
        $intent = ltrim(trim($intent), '/');

        if ($intent === '') {
            throw new \InvalidArgumentException('Graphics-state rendering intent must not be empty.');
        }

        return $this->rewriteExtGStateValue('RI', $intent);
    }

    public function setGraphicsStateFlatness(float $flatness): self
    {
        if ($flatness < 0 || $flatness > 100) {
            throw new \InvalidArgumentException('Graphics-state flatness must be between 0 and 100.');
        }

        return $this->rewriteExtGStateValue('FL', $flatness);
    }

    public function setSmoothnessTolerance(float $smoothness): self
    {
        if ($smoothness < 0 || $smoothness > 1) {
            throw new \InvalidArgumentException('Smoothness tolerance must be between 0 and 1.');
        }

        return $this->rewriteExtGStateValue('SM', $smoothness);
    }

    public function setGraphicsStateLineWidth(float $width): self
    {
        if ($width < 0) {
            throw new \InvalidArgumentException('Graphics-state line width must be greater than or equal to zero.');
        }

        return $this->rewriteExtGStateValue('LW', $width);
    }

    public function setGraphicsStateLineCap(int $lineCap): self
    {
        if ($lineCap < 0 || $lineCap > 2) {
            throw new \InvalidArgumentException('Graphics-state line cap must be 0, 1, or 2.');
        }

        return $this->rewriteExtGStateValue('LC', $lineCap);
    }

    public function setGraphicsStateLineJoin(int $lineJoin): self
    {
        if ($lineJoin < 0 || $lineJoin > 2) {
            throw new \InvalidArgumentException('Graphics-state line join must be 0, 1, or 2.');
        }

        return $this->rewriteExtGStateValue('LJ', $lineJoin);
    }

    public function setGraphicsStateMiterLimit(float $miterLimit): self
    {
        if ($miterLimit <= 0) {
            throw new \InvalidArgumentException('Graphics-state miter limit must be greater than zero.');
        }

        return $this->rewriteExtGStateValue('ML', $miterLimit);
    }

    /**
     * @param list<float|int> $pattern
     */
    public function setGraphicsStateDashPattern(array $pattern, float $phase = 0.0): self
    {
        if ($phase < 0) {
            throw new \InvalidArgumentException('Graphics-state dash phase must be greater than or equal to zero.');
        }

        $normalizedPattern = [];

        foreach ($pattern as $value) {
            if ((!is_int($value) && !is_float($value)) || $value < 0) {
                throw new \InvalidArgumentException('Graphics-state dash pattern values must be numbers greater than or equal to zero.');
            }

            $normalizedPattern[] = (float) $value;
        }

        return $this->rewriteExtGStateValue('D', [$normalizedPattern, $phase]);
    }

    public function setBlackGenerationMode(string $mode): self
    {
        return $this->rewriteExtGStateNamedValue('BG', $mode, 'Black-generation mode must not be empty.');
    }

    public function setBlackGenerationMode2(string $mode): self
    {
        return $this->rewriteExtGStateNamedValue('BG2', $mode, 'Second-generation black-generation mode must not be empty.');
    }

    public function setUndercolorRemovalMode(string $mode): self
    {
        return $this->rewriteExtGStateNamedValue('UCR', $mode, 'Undercolor-removal mode must not be empty.');
    }

    public function setUndercolorRemovalMode2(string $mode): self
    {
        return $this->rewriteExtGStateNamedValue('UCR2', $mode, 'Second-generation undercolor-removal mode must not be empty.');
    }

    public function setTransferFunctionMode(string $mode): self
    {
        return $this->rewriteExtGStateNamedValue('TR', $mode, 'Transfer-function mode must not be empty.');
    }

    public function setTransferFunctionMode2(string $mode): self
    {
        return $this->rewriteExtGStateNamedValue('TR2', $mode, 'Second-generation transfer-function mode must not be empty.');
    }

    public function setHalftoneMode(string $mode): self
    {
        return $this->rewriteExtGStateNamedValue('HT', $mode, 'Halftone mode must not be empty.');
    }

    /**
     * @param list<mixed> $operands
     * @return list<mixed>
     */
    private static function replaceOperands(array $operands, string $search, string $replace): array
    {
        $updated = [];

        foreach ($operands as $operand) {
            $updated[] = self::replaceOperandValue($operand, $search, $replace);
        }

        return $updated;
    }

    private static function replaceOperandValue(mixed $value, string $search, string $replace): mixed
    {
        if ($value instanceof PdfLiteralString) {
            return new PdfLiteralString(str_replace($search, $replace, $value->value));
        }

        if (is_string($value)) {
            return str_replace($search, $replace, $value);
        }

        if ($value instanceof PdfName) {
            return $value;
        }

        if (is_array($value)) {
            $updated = [];

            foreach ($value as $key => $item) {
                $updated[$key] = self::replaceOperandValue($item, $search, $replace);
            }

            return $updated;
        }

        return $value;
    }

    private function rewriteStreams(callable $transform): self
    {
        return $this->rewriteStreamsAndResources($transform);
    }

    private function rewriteExtGStateAlpha(string $key, float $alpha): self
    {
        return $this->rewriteExtGStateValue($key, $alpha);
    }

    private function rewriteExtGStateNamedValue(string $key, string $value, string $errorMessage): self
    {
        $value = ltrim(trim($value), '/');

        if ($value === '') {
            throw new \InvalidArgumentException($errorMessage);
        }

        return $this->rewriteExtGStateValue($key, $value);
    }

    private function rewriteExtGStateValue(string $key, mixed $value): self
    {
        $source = $this->page->importedSource();

        if ($source === null) {
            return $this;
        }

        $dependentObjects = $source->dependentObjects;
        $resources = self::rewriteExtGStateResources($source->resourceDictionary, $dependentObjects, $key, $value);

        $this->page->setImportedSource(new ImportedPageSource(
            objectNumber: $source->objectNumber,
            pageDictionary: $source->pageDictionary,
            resourceDictionary: $resources,
            contentStreams: $source->contentStreams,
            dependentObjects: $dependentObjects,
            warnings: $source->warnings,
        ));

        return $this;
    }

    private function rewriteStreamsAndResources(callable $transform, ?callable $resourceTransform = null): self
    {
        $source = $this->page->importedSource();

        if ($source === null) {
            return $this;
        }

        $streams = [];
        $serializer = new ContentStreamSerializer();

        foreach ($source->contentStreams as $stream) {
            $operations = [];
            $changed = false;

            foreach ($stream->operations as $operation) {
                $rewritten = $transform($operation);
                $operations[] = $rewritten;
                $changed = $changed || $rewritten != $operation;
            }

            $contents = !$changed || $operations === [] ? $stream->contents : $serializer->serialize($operations);

            $streams[] = new ImportedContentStream(
                contents: $contents,
                dictionary: $stream->dictionary,
                warnings: $stream->warnings,
                operations: $operations,
            );
        }

        $this->page->setImportedSource(new ImportedPageSource(
            objectNumber: $source->objectNumber,
            pageDictionary: $source->pageDictionary,
            resourceDictionary: $resourceTransform === null
                ? $source->resourceDictionary
                : $resourceTransform($source->resourceDictionary),
            contentStreams: $streams,
            dependentObjects: $source->dependentObjects,
            warnings: $source->warnings,
        ));

        return $this;
    }

    private function renameResourceReference(string $category, string $operator, string $from, string $to): self
    {
        $from = trim($from);
        $to = trim($to);

        if ($from === '' || $to === '') {
            throw new \InvalidArgumentException('Resource names must not be empty.');
        }

        if ($from === $to) {
            return $this;
        }

        return $this->rewriteStreamsAndResources(
            static fn (ImportedContentOperation $operation): ImportedContentOperation => self::rewriteNamedResourceOperand(
                $operation,
                $operator,
                0,
                $from,
                $to
            ),
            static fn (?array $resources): ?array => self::renameResourceDictionaryEntry($resources, $category, $from, $to),
        );
    }

    private static function translateOperation(ImportedContentOperation $operation, float $dx, float $dy): ImportedContentOperation
    {
        $operands = $operation->operands;

        $operands = match ($operation->operator) {
            'Tm', 'cm' => self::translateLastCoordinatePair($operands, $dx, $dy),
            'Td', 'TD' => self::translateFirstCoordinatePair($operands, $dx, $dy),
            'm', 'l' => self::translateAllCoordinatePairs($operands, $dx, $dy),
            'c', 'v', 'y' => self::translateAllCoordinatePairs($operands, $dx, $dy),
            're' => self::translateRectangleOperands($operands, $dx, $dy),
            default => $operands,
        };

        return new ImportedContentOperation($operation->operator, $operands);
    }

    private static function rewriteSingleNumericOperandOperation(
        ImportedContentOperation $operation,
        string $operator,
        float $value
    ): ImportedContentOperation {
        if ($operation->operator !== $operator) {
            return $operation;
        }

        return new ImportedContentOperation($operation->operator, [$value]);
    }

    private static function rewritePathPaintingOperation(
        ImportedContentOperation $operation,
        string $operator
    ): ImportedContentOperation {
        if (!in_array($operation->operator, ['S', 's', 'f', 'F', 'f*', 'B', 'B*', 'b', 'b*', 'n'], true)) {
            return $operation;
        }

        return new ImportedContentOperation($operator, []);
    }

    private static function rewriteClippingRuleOperation(
        ImportedContentOperation $operation,
        string $operator
    ): ImportedContentOperation {
        if (!in_array($operation->operator, ['W', 'W*'], true)) {
            return $operation;
        }

        return new ImportedContentOperation($operator, []);
    }

    private static function rewriteAutoClosePathPaintingOperation(
        ImportedContentOperation $operation,
        bool $close
    ): ImportedContentOperation {
        $operator = match ($operation->operator) {
            'S', 's' => $close ? 's' : 'S',
            'B', 'b' => $close ? 'b' : 'B',
            'B*', 'b*' => $close ? 'b*' : 'B*',
            default => null,
        };

        if ($operator === null) {
            return $operation;
        }

        return new ImportedContentOperation($operator, []);
    }

    /**
     * @param list<float> $pattern
     */
    private static function rewriteDashPatternOperation(
        ImportedContentOperation $operation,
        array $pattern,
        float $phase
    ): ImportedContentOperation {
        if ($operation->operator !== 'd') {
            return $operation;
        }

        return new ImportedContentOperation($operation->operator, [$pattern, $phase]);
    }

    private static function rewriteColorOperation(
        ImportedContentOperation $operation,
        string $grayscaleOperator,
        string $rgbOperator,
        string $cmykOperator,
        Color $color
    ): ImportedContentOperation {
        if (!in_array($operation->operator, [$grayscaleOperator, $rgbOperator, $cmykOperator], true)) {
            return $operation;
        }

        if ($color->isGray()) {
            return new ImportedContentOperation($grayscaleOperator, $color->components());
        }

        if ($color->isCmyk()) {
            return new ImportedContentOperation($cmykOperator, $color->components());
        }

        return new ImportedContentOperation($rgbOperator, $color->components());
    }

    private static function rewriteNamedResourceOperand(
        ImportedContentOperation $operation,
        string $operator,
        int $operandIndex,
        string $from,
        string $to
    ): ImportedContentOperation {
        if ($operation->operator !== $operator || !isset($operation->operands[$operandIndex])) {
            return $operation;
        }

        $operand = $operation->operands[$operandIndex];

        if (!$operand instanceof PdfName || $operand->value !== $from) {
            return $operation;
        }

        $operands = $operation->operands;
        $operands[$operandIndex] = new PdfName($to);

        return new ImportedContentOperation($operation->operator, $operands);
    }

    private static function rewriteTextFontSizeOperation(
        ImportedContentOperation $operation,
        float $size
    ): ImportedContentOperation {
        if ($operation->operator !== 'Tf' || count($operation->operands) < 2) {
            return $operation;
        }

        $resource = $operation->operands[0];

        if (!$resource instanceof PdfName) {
            return $operation;
        }

        return new ImportedContentOperation($operation->operator, [$resource, $size]);
    }

    private static function rewriteTextPositionOperation(
        ImportedContentOperation $operation,
        float $x,
        float $y
    ): ImportedContentOperation {
        return match ($operation->operator) {
            'Td', 'TD' => new ImportedContentOperation($operation->operator, [$x, $y]),
            'Tm' => count($operation->operands) >= 6
                ? new ImportedContentOperation($operation->operator, [
                    $operation->operands[0],
                    $operation->operands[1],
                    $operation->operands[2],
                    $operation->operands[3],
                    $x,
                    $y,
                ])
                : $operation,
            default => $operation,
        };
    }

    private static function translateTextPositionOperation(
        ImportedContentOperation $operation,
        float $dx,
        float $dy
    ): ImportedContentOperation {
        return match ($operation->operator) {
            'Td', 'TD' => count($operation->operands) >= 2
                ? new ImportedContentOperation($operation->operator, [
                    self::numericOperandValue($operation->operands[0]) + $dx,
                    self::numericOperandValue($operation->operands[1]) + $dy,
                ])
                : $operation,
            'Tm' => count($operation->operands) >= 6
                ? new ImportedContentOperation($operation->operator, [
                    $operation->operands[0],
                    $operation->operands[1],
                    $operation->operands[2],
                    $operation->operands[3],
                    self::numericOperandValue($operation->operands[4]) + $dx,
                    self::numericOperandValue($operation->operands[5]) + $dy,
                ])
                : $operation,
            default => $operation,
        };
    }

    private static function rewriteTextMatrixOperation(
        ImportedContentOperation $operation,
        float $a,
        float $b,
        float $c,
        float $d,
        float $e,
        float $f,
    ): ImportedContentOperation {
        if ($operation->operator !== 'Tm') {
            return $operation;
        }

        return new ImportedContentOperation($operation->operator, [$a, $b, $c, $d, $e, $f]);
    }

    private static function rewriteNamedIntentOperation(
        ImportedContentOperation $operation,
        string $intent
    ): ImportedContentOperation {
        if ($operation->operator !== 'ri') {
            return $operation;
        }

        return new ImportedContentOperation($operation->operator, [new PdfName($intent)]);
    }

    private static function rewriteGraphicsMatrixOperation(
        ImportedContentOperation $operation,
        float $a,
        float $b,
        float $c,
        float $d,
        float $e,
        float $f,
    ): ImportedContentOperation {
        if ($operation->operator !== 'cm') {
            return $operation;
        }

        return new ImportedContentOperation($operation->operator, [$a, $b, $c, $d, $e, $f]);
    }

    private static function numericOperandValue(mixed $value): float
    {
        return is_int($value) || is_float($value) ? (float) $value : 0.0;
    }

    /**
     * @param list<mixed> $operands
     * @return list<mixed>
     */
    private static function translateLastCoordinatePair(array $operands, float $dx, float $dy): array
    {
        $count = count($operands);

        if ($count < 2 || !self::isNumericOperand($operands[$count - 2]) || !self::isNumericOperand($operands[$count - 1])) {
            return $operands;
        }

        $operands[$count - 2] = (float) $operands[$count - 2] + $dx;
        $operands[$count - 1] = (float) $operands[$count - 1] + $dy;

        return $operands;
    }

    /**
     * @param list<mixed> $operands
     * @return list<mixed>
     */
    private static function translateFirstCoordinatePair(array $operands, float $dx, float $dy): array
    {
        if (count($operands) < 2 || !self::isNumericOperand($operands[0]) || !self::isNumericOperand($operands[1])) {
            return $operands;
        }

        $operands[0] = (float) $operands[0] + $dx;
        $operands[1] = (float) $operands[1] + $dy;

        return $operands;
    }

    /**
     * @param list<mixed> $operands
     * @return list<mixed>
     */
    private static function translateAllCoordinatePairs(array $operands, float $dx, float $dy): array
    {
        if (count($operands) < 2) {
            return $operands;
        }

        for ($i = 0; $i + 1 < count($operands); $i += 2) {
            if (!self::isNumericOperand($operands[$i]) || !self::isNumericOperand($operands[$i + 1])) {
                return $operands;
            }

            $operands[$i] = (float) $operands[$i] + $dx;
            $operands[$i + 1] = (float) $operands[$i + 1] + $dy;
        }

        return $operands;
    }

    /**
     * @param list<mixed> $operands
     * @return list<mixed>
     */
    private static function translateRectangleOperands(array $operands, float $dx, float $dy): array
    {
        if (count($operands) < 2 || !self::isNumericOperand($operands[0]) || !self::isNumericOperand($operands[1])) {
            return $operands;
        }

        $operands[0] = (float) $operands[0] + $dx;
        $operands[1] = (float) $operands[1] + $dy;

        return $operands;
    }

    private static function isNumericOperand(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    private static function renameResourceDictionaryEntry(?array $resources, string $category, string $from, string $to): ?array
    {
        if ($resources === null || !isset($resources[$category]) || !is_array($resources[$category])) {
            return $resources;
        }

        if (!array_key_exists($from, $resources[$category])) {
            return $resources;
        }

        $resources[$category][$to] = $resources[$category][$from];
        unset($resources[$category][$from]);

        return $resources;
    }

    /**
     * @param array<int, string> $dependentObjects
     */
    private static function rewriteExtGStateResources(?array $resources, array &$dependentObjects, string $key, mixed $value): ?array
    {
        if ($resources === null || !isset($resources['ExtGState']) || !is_array($resources['ExtGState'])) {
            return $resources;
        }

        foreach ($resources['ExtGState'] as $name => $entry) {
            if (is_array($entry)) {
                $entry[$key] = $value;
                $resources['ExtGState'][$name] = $entry;
                continue;
            }

            if ($entry instanceof PdfReference && isset($dependentObjects[$entry->objectNumber])) {
                $dependentObjects[$entry->objectNumber] = self::rewriteSerializedDictionaryNumericValue(
                    $dependentObjects[$entry->objectNumber],
                    $key,
                    $value,
                );
            }
        }

        return $resources;
    }

    private static function rewriteSerializedDictionaryNumericValue(string $serialized, string $key, mixed $value): string
    {
        $offset = 0;
        $parser = new PdfValueParser($serialized);
        $parsed = $parser->parseValue($offset);

        if (!is_array($parsed)) {
            return $serialized;
        }

        $parsed[$key] = $value;

        return self::serializePdfValue($parsed);
    }

    private static function assertAlphaValue(float $alpha): void
    {
        if ($alpha < 0.0 || $alpha > 1.0) {
            throw new \InvalidArgumentException('Alpha must be between 0 and 1.');
        }
    }

    /**
     * @param array<int, string> $dependentObjects
     * @param list<int> $rootObjectNumbers
     * @return array<int, string>
     */
    private function removeDependentObjectGraph(array $dependentObjects, array $rootObjectNumbers): array
    {
        $remove = [];
        $queue = $rootObjectNumbers;
        $queueIndex = 0;

        while (isset($queue[$queueIndex])) {
            $objectNumber = $queue[$queueIndex++];

            if (isset($remove[$objectNumber])) {
                continue;
            }

            $remove[$objectNumber] = true;

            foreach (self::referencedObjectNumbersInSerializedValue($dependentObjects[$objectNumber] ?? '') as $referencedObjectNumber) {
                if (!isset($remove[$referencedObjectNumber])) {
                    $queue[] = $referencedObjectNumber;
                }
            }
        }

        foreach (array_keys($remove) as $objectNumber) {
            unset($dependentObjects[$objectNumber]);
        }

        return $dependentObjects;
    }

    /**
     * @return list<int>
     */
    private static function referencedObjectNumbers(mixed $value): array
    {
        if ($value instanceof PdfReference) {
            return [$value->objectNumber];
        }

        if (!is_array($value)) {
            return [];
        }

        $objectNumbers = [];

        foreach ($value as $item) {
            foreach (self::referencedObjectNumbers($item) as $objectNumber) {
                $objectNumbers[$objectNumber] = true;
            }
        }

        return array_map(static fn (int|string $objectNumber): int => (int) $objectNumber, array_keys($objectNumbers));
    }

    /**
     * @return list<int>
     */
    private static function referencedObjectNumbersInSerializedValue(string $value): array
    {
        if (preg_match_all('/\b(\d+)\s+\d+\s+R\b/', $value, $matches) !== 1) {
            return [];
        }

        return array_values(array_unique(array_map(static fn (string $objectNumber): int => (int) $objectNumber, $matches[1])));
    }

    private static function annotationSubtype(mixed $annotation, array $dependentObjects): ?string
    {
        if ($annotation instanceof PdfReference) {
            return self::annotationSubtypeInSerializedValue($dependentObjects[$annotation->objectNumber] ?? null);
        }

        if (!is_array($annotation) || !isset($annotation['Subtype'])) {
            return null;
        }

        if ($annotation['Subtype'] instanceof PdfName) {
            return $annotation['Subtype']->value;
        }

        return is_string($annotation['Subtype']) ? $annotation['Subtype'] : null;
    }

    private static function annotationSubtypeInSerializedValue(?string $value): ?string
    {
        if ($value === null || preg_match('/\/Subtype\s*\/([^\s<>\[\]\(\)\/%]+)/', $value, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function rewriteImportedAnnotations(callable $transform): self
    {
        $source = $this->page->importedSource();

        if ($source === null) {
            return $this;
        }

        $annotations = $source->pageDictionary['Annots'] ?? null;

        if (!is_array($annotations)) {
            return $this;
        }

        $dependentObjects = $source->dependentObjects;
        $rewrittenAnnotations = [];

        foreach ($annotations as $annotation) {
            $rewrittenAnnotations[] = $transform($annotation, $dependentObjects);
        }

        $pageDictionary = $source->pageDictionary;
        $pageDictionary['Annots'] = $rewrittenAnnotations;

        $this->page->setImportedSource(new ImportedPageSource(
            objectNumber: $source->objectNumber,
            pageDictionary: $pageDictionary,
            resourceDictionary: $source->resourceDictionary,
            contentStreams: $source->contentStreams,
            dependentObjects: $dependentObjects,
            warnings: $source->warnings,
        ));

        return $this;
    }

    private static function replaceAnnotationLiteralStringValue(
        mixed $annotation,
        array &$dependentObjects,
        string $key,
        string $search,
        string $replace,
    ): mixed {
        if ($annotation instanceof PdfReference) {
            if (!isset($dependentObjects[$annotation->objectNumber])) {
                return $annotation;
            }

            $dependentObjects[$annotation->objectNumber] = self::replaceLiteralStringValueInSerializedObject(
                $dependentObjects[$annotation->objectNumber],
                $key,
                $search,
                $replace
            );

            return $annotation;
        }

        if (!is_array($annotation)) {
            return $annotation;
        }

        $stringValue = self::literalStringValue($annotation[$key] ?? null);

        if ($stringValue !== null) {
            $annotation[$key] = new PdfLiteralString(str_replace($search, $replace, $stringValue));
        }

        return $annotation;
    }

    private static function replaceLinkAnnotationUri(
        mixed $annotation,
        array &$dependentObjects,
        string $search,
        string $replace,
    ): mixed {
        if ($annotation instanceof PdfReference) {
            if (!isset($dependentObjects[$annotation->objectNumber])) {
                return $annotation;
            }

            $serialized = $dependentObjects[$annotation->objectNumber];
            $actionObjectNumber = self::matchReferenceObjectNumberInSerializedObject($serialized, 'A');

            if ($actionObjectNumber !== null && isset($dependentObjects[$actionObjectNumber])) {
                $dependentObjects[$actionObjectNumber] = self::replaceLiteralStringValueInSerializedObject(
                    $dependentObjects[$actionObjectNumber],
                    'URI',
                    $search,
                    $replace
                );

                return $annotation;
            }

            $dependentObjects[$annotation->objectNumber] = self::replaceLiteralStringValueInSerializedObject(
                $serialized,
                'URI',
                $search,
                $replace
            );

            return $annotation;
        }

        if (!is_array($annotation)) {
            return $annotation;
        }

        if (isset($annotation['A']) && is_array($annotation['A']) && isset($annotation['A']['URI'])) {
            $uri = self::literalStringValue($annotation['A']['URI']);

            if ($uri !== null) {
                $annotation['A']['URI'] = new PdfLiteralString(str_replace($search, $replace, $uri));
            }
        }

        return $annotation;
    }

    /**
     * @param list<mixed> $destination
     */
    private static function replaceLinkAnnotationPageDestination(
        mixed $annotation,
        array &$dependentObjects,
        array $destination,
    ): mixed {
        if ($annotation instanceof PdfReference) {
            if (!isset($dependentObjects[$annotation->objectNumber])) {
                return $annotation;
            }

            $serialized = self::replaceSerializedDestinationArray(
                $dependentObjects[$annotation->objectNumber],
                'Dest',
                $destination
            );

            $actionObjectNumber = self::matchReferenceObjectNumberInSerializedObject($serialized, 'A');

            if ($actionObjectNumber !== null && isset($dependentObjects[$actionObjectNumber])) {
                $dependentObjects[$actionObjectNumber] = self::replaceSerializedGoToActionDestination(
                    $dependentObjects[$actionObjectNumber],
                    $destination
                );
            } else {
                $serialized = self::replaceSerializedGoToActionDestination($serialized, $destination);
            }

            $dependentObjects[$annotation->objectNumber] = $serialized;

            return $annotation;
        }

        if (!is_array($annotation)) {
            return $annotation;
        }

        if (isset($annotation['Dest']) && is_array($annotation['Dest'])) {
            $annotation['Dest'] = $destination;
        }

        if (
            isset($annotation['A'])
            && is_array($annotation['A'])
            && self::nameValue($annotation['A']['S'] ?? null) === 'GoTo'
        ) {
            $annotation['A']['D'] = $destination;
        }

        return $annotation;
    }

    private static function replaceLiteralStringValueInSerializedObject(
        string $serializedObject,
        string $key,
        string $search,
        string $replace,
    ): string {
        if (preg_match(sprintf('/\/%s\s*\(/', preg_quote($key, '/')), $serializedObject, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $serializedObject;
        }

        $fullMatch = $matches[0][0];
        $matchOffset = $matches[0][1];
        $stringStart = $matchOffset + strlen($fullMatch) - 1;
        $stringEnd = self::findLiteralStringEnd($serializedObject, $stringStart);

        if ($stringEnd === null) {
            return $serializedObject;
        }

        $value = self::readLiteralStringAt($serializedObject, $stringStart);

        if ($value === null) {
            return $serializedObject;
        }

        return substr($serializedObject, 0, $stringStart)
            . '(' . self::escapeLiteralString(str_replace($search, $replace, $value)) . ')'
            . substr($serializedObject, $stringEnd + 1);
    }

    /**
     * @return list<mixed>
     */
    private static function pageDestinationArray(
        int $pageObjectNumber,
        ?float $left,
        ?float $top,
        ?float $zoom,
    ): array {
        return [
            new PdfReference($pageObjectNumber, 0),
            'XYZ',
            $left,
            $top,
            $zoom,
        ];
    }

    /**
     * @param list<mixed> $destination
     */
    private static function replaceSerializedDestinationArray(
        string $serializedObject,
        string $key,
        array $destination,
    ): string {
        $replacement = sprintf('/%s %s', $key, self::serializePdfValue($destination));

        return preg_replace(
            sprintf('/\/%s\s*\[[^\]]*\]/', preg_quote($key, '/')),
            $replacement,
            $serializedObject,
            1
        ) ?? $serializedObject;
    }

    private static function serializePdfValue(mixed $value): string
    {
        static $serializer;

        $serializer ??= new PdfValueSerializer();

        return $serializer->serialize($value);
    }

    /**
     * @param list<mixed> $destination
     */
    private static function replaceSerializedGoToActionDestination(string $serializedObject, array $destination): string
    {
        if (!preg_match('/\/S\s*\/GoTo\b/', $serializedObject)) {
            return $serializedObject;
        }

        return self::replaceSerializedDestinationArray($serializedObject, 'D', $destination);
    }

    private static function nameValue(mixed $value): ?string
    {
        if ($value instanceof PdfName) {
            return $value->value;
        }

        return is_string($value) ? $value : null;
    }

    private static function literalStringValue(mixed $value): ?string
    {
        if ($value instanceof PdfLiteralString) {
            return $value->value;
        }

        return is_string($value) ? $value : null;
    }

    private static function matchReferenceObjectNumberInSerializedObject(string $serializedObject, string $key): ?int
    {
        if (preg_match(sprintf('/\/%s\s*(\d+)\s+\d+\s+R\b/', preg_quote($key, '/')), $serializedObject, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private static function readLiteralStringAt(string $source, int $offset): ?string
    {
        if (($source[$offset] ?? null) !== '(') {
            return null;
        }

        $offset++;
        $depth = 1;
        $value = '';
        $length = strlen($source);

        while ($offset < $length) {
            $char = $source[$offset++];

            if ($char === '\\') {
                if ($offset >= $length) {
                    break;
                }

                $escaped = $source[$offset++];
                $value .= match ($escaped) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'b' => "\x08",
                    'f' => "\x0C",
                    '(', ')', '\\' => $escaped,
                    default => $escaped,
                };
                continue;
            }

            if ($char === '(') {
                $depth++;
                $value .= $char;
                continue;
            }

            if ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return $value;
                }

                $value .= $char;
                continue;
            }

            $value .= $char;
        }

        return null;
    }

    private static function findLiteralStringEnd(string $source, int $offset): ?int
    {
        if (($source[$offset] ?? null) !== '(') {
            return null;
        }

        $offset++;
        $depth = 1;
        $length = strlen($source);

        while ($offset < $length) {
            $char = $source[$offset++];

            if ($char === '\\') {
                $offset++;
                continue;
            }

            if ($char === '(') {
                $depth++;
                continue;
            }

            if ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return $offset - 1;
                }
            }
        }

        return null;
    }

    private static function escapeLiteralString(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }

    public function done(): ImportedPageCollection
    {
        return $this->collection;
    }
}
