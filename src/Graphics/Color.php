<?php

declare(strict_types=1);

namespace PdfToolkit\Graphics;

final readonly class Color
{
    public function __construct(
        public float $r,
        public float $g,
        public float $b,
        public ?float $k = null,
        public string $space = 'rgb',
    ) {
        if (!in_array($this->space, ['rgb', 'gray', 'cmyk'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported color space: %s', $this->space));
        }

        foreach ($this->components() as $component) {
            if ($component < 0.0 || $component > 1.0) {
                throw new \InvalidArgumentException('Color components must be between 0 and 1.');
            }
        }

        if ($this->space === 'cmyk' && $this->k === null) {
            throw new \InvalidArgumentException('CMYK colors require a key/black component.');
        }
    }

    public static function black(): self
    {
        return self::gray(0.0);
    }

    public static function white(): self
    {
        return self::gray(1.0);
    }

    public static function gray(float $gray): self
    {
        return new self($gray, $gray, $gray, space: 'gray');
    }

    public static function rgb(float $r, float $g, float $b): self
    {
        return new self($r, $g, $b);
    }

    public static function cmyk(float $c, float $m, float $y, float $k): self
    {
        return new self($c, $m, $y, $k, 'cmyk');
    }

    public function isGray(): bool
    {
        return $this->space === 'gray';
    }

    public function isCmyk(): bool
    {
        return $this->space === 'cmyk';
    }

    public function fillOperator(): string
    {
        return match ($this->space) {
            'gray' => 'g',
            'cmyk' => 'k',
            default => 'rg',
        };
    }

    public function strokeOperator(): string
    {
        return match ($this->space) {
            'gray' => 'G',
            'cmyk' => 'K',
            default => 'RG',
        };
    }

    /**
     * @return list<float>
     */
    public function components(): array
    {
        return match ($this->space) {
            'gray' => [$this->r],
            'cmyk' => [$this->r, $this->g, $this->b, $this->k ?? 0.0],
            default => [$this->r, $this->g, $this->b],
        };
    }
}
