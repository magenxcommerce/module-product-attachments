<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductAttachments\Model;

/**
 * The two shapes a `magenx_product_attachment` row can take.
 *
 * `upload` rows point at a file below the media base (bytes on disk, exactly
 * as before this table existed); `external` rows point at a URL on another
 * site or storage bucket entirely. Both are described the same way to the
 * admin grid, GraphQL and the download resolver — only which of `file` /
 * `external_url` is populated differs.
 */
class AttachmentType
{
    public const UPLOAD = 'upload';
    public const EXTERNAL = 'external';

    private function __construct()
    {
    }

    // A stateless guard on this class's own constants; there is no instance
    // to intercept a plugin onto.
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function isValid(string $type): bool
    {
        return $type === self::UPLOAD || $type === self::EXTERNAL;
    }
}
