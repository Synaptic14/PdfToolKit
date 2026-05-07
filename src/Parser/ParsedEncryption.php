<?php

declare(strict_types=1);

namespace PdfToolkit\Parser;

final readonly class ParsedEncryption
{
    public function __construct(
        private string $filter,
        private int $version,
        private int $revision,
        private int $keyLengthBits,
        private int $permissions,
        private ?string $authenticatedAs,
        private bool $openedWithPassword,
        /** @var list<string> */
        private array $cryptFilterNames,
        /** @var array<string, string> */
        private array $cryptFilters,
        /** @var array<string, string> */
        private array $cryptFilterAuthEvents,
        /** @var array<string, int> */
        private array $cryptFilterKeyLengthBits,
        private ?string $embeddedFileFilterName,
        private string $stringFilterName,
        private string $streamFilterName,
        private ?string $embeddedFileMethod,
        private string $stringMethod,
        private string $streamMethod,
        private bool $encryptMetadata,
    ) {
    }

    public function filter(): string
    {
        return $this->filter;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function revision(): int
    {
        return $this->revision;
    }

    public function keyLengthBits(): int
    {
        return $this->keyLengthBits;
    }

    public function permissions(): int
    {
        return $this->permissions;
    }

    public function authenticatedAs(): ?string
    {
        return $this->authenticatedAs;
    }

    public function openedWithPassword(): bool
    {
        return $this->openedWithPassword;
    }

    /**
     * @return list<string>
     */
    public function cryptFilterNames(): array
    {
        return $this->cryptFilterNames;
    }

    /**
     * @return array<string, string>
     */
    public function cryptFilters(): array
    {
        return $this->cryptFilters;
    }

    /**
     * @return array<string, string>
     */
    public function cryptFilterAuthEvents(): array
    {
        return $this->cryptFilterAuthEvents;
    }

    /**
     * @return array<string, int>
     */
    public function cryptFilterKeyLengthBits(): array
    {
        return $this->cryptFilterKeyLengthBits;
    }

    public function embeddedFileFilterName(): ?string
    {
        return $this->embeddedFileFilterName;
    }

    public function stringFilterName(): string
    {
        return $this->stringFilterName;
    }

    public function streamFilterName(): string
    {
        return $this->streamFilterName;
    }

    public function embeddedFileMethod(): ?string
    {
        return $this->embeddedFileMethod;
    }

    public function stringMethod(): string
    {
        return $this->stringMethod;
    }

    public function streamMethod(): string
    {
        return $this->streamMethod;
    }

    public function encryptMetadata(): bool
    {
        return $this->encryptMetadata;
    }
}
