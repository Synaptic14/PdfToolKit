<?php

declare(strict_types=1);

namespace PdfToolkit\Writer;

final readonly class WriteOptions
{
    public function __construct(
        public bool $compressStreams = false,
        public ?string $userPassword = null,
        public ?string $ownerPassword = null,
        public int $permissions = -4,
        public int $encryptionRevision = 2,
        public string $encryptionMethod = 'RC4',
        public bool $encryptMetadata = true,
        public bool $useExplicitCryptFilters = false,
        public bool $encryptStrings = true,
        public bool $encryptStreams = true,
        public ?string $embeddedFileFilterName = null,
        public ?string $embeddedFileAuthEvent = null,
    ) {
    }
}
