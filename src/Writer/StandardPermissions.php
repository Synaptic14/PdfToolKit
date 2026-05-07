<?php

declare(strict_types=1);

namespace PdfToolkit\Writer;

final class StandardPermissions
{
    public const PRINT = 0x0004;
    public const MODIFY = 0x0008;
    public const COPY = 0x0010;
    public const ANNOTATE = 0x0020;
    public const FILL_FORMS = 0x0100;
    public const ACCESSIBILITY = 0x0200;
    public const ASSEMBLE = 0x0400;
    public const HIGH_QUALITY_PRINT = 0x0800;

    private function __construct()
    {
    }

    /**
     * @param list<int> $permissions
     */
    public static function allow(array $permissions = [], int $revision = 4): int
    {
        $mask = $revision >= 3 ? 0xFFFFF0C0 : 0xFFFFFFC0;

        foreach ($permissions as $permission) {
            self::assertPermissionIsSupported($permission, $revision);
            $mask |= $permission;
        }

        return self::toSigned32($mask);
    }

    public static function all(int $revision = 4): int
    {
        return self::allow(self::supportedPermissions($revision), $revision);
    }

    public static function none(int $revision = 4): int
    {
        return self::allow([], $revision);
    }

    /**
     * @return list<int>
     */
    private static function supportedPermissions(int $revision): array
    {
        $permissions = [
            self::PRINT,
            self::MODIFY,
            self::COPY,
            self::ANNOTATE,
        ];

        if ($revision >= 3) {
            array_push(
                $permissions,
                self::FILL_FORMS,
                self::ACCESSIBILITY,
                self::ASSEMBLE,
                self::HIGH_QUALITY_PRINT,
            );
        }

        return $permissions;
    }

    private static function assertPermissionIsSupported(int $permission, int $revision): void
    {
        if (!in_array($permission, self::supportedPermissions($revision), true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported Standard Security permission %d for revision %d.',
                $permission,
                $revision,
            ));
        }
    }

    private static function toSigned32(int $value): int
    {
        return $value > 0x7FFFFFFF ? $value - 0x100000000 : $value;
    }
}
