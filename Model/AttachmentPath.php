<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductAttachments\Model;

/**
 * Builds and sanitises the media paths a product's attachments live under.
 *
 * Layout (relative to pub/media):
 *
 *     <media_directory>/<sanitised sku>/manual.pdf
 *     <media_directory>/<product id>/manual.pdf
 *
 * BOTH are read, always. The SKU folder is what the product page uploads into
 * and what a human recognises when dropping files in over SFTP; the numeric id
 * folder is the stable one, because a SKU can be edited. Reading both means
 * neither convention has to be chosen up front, and files survive a SKU rename
 * even when the rename hook does not run (an import, a direct SQL update).
 *
 * A "file" in this module's API is always the path BELOW the media directory —
 * `SKU-123/manual.pdf` — never an absolute path and never a bare filename: two
 * products can hold files of the same name, and the same product can hold one
 * in each of its two folders.
 */
class AttachmentPath
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * The configured media sub-folder, e.g. `product_attachments`.
     */
    public function getBaseDirectory(): string
    {
        return $this->config->getMediaDirectory();
    }

    /**
     * Where the product edit page uploads to, relative to pub/media.
     */
    public function getUploadDirectory(string $sku): string
    {
        return $this->getBaseDirectory() . '/' . $this->sanitizeSegment($sku);
    }

    /**
     * Every directory read for one product, relative to pub/media.
     *
     * @return string[]
     */
    public function getProductDirectories(string $sku, int $productId): array
    {
        $base = $this->getBaseDirectory();
        $directories = [];

        $skuSegment = $this->sanitizeSegment($sku);
        if ($skuSegment !== '') {
            $directories[$skuSegment] = $base . '/' . $skuSegment;
        }

        if ($productId > 0) {
            $directories[(string) $productId] = $base . '/' . $productId;
        }

        return array_values($directories);
    }

    /**
     * Media-relative path of a file identified below the media directory.
     */
    public function toMediaPath(string $fileBelowBase): string
    {
        return $this->getBaseDirectory() . '/' . ltrim($fileBelowBase, '/');
    }

    /**
     * Strip the media directory back off a media-relative path.
     */
    public function toFileBelowBase(string $mediaPath): string
    {
        $prefix = $this->getBaseDirectory() . '/';

        return str_starts_with($mediaPath, $prefix) ? substr($mediaPath, strlen($prefix)) : $mediaPath;
    }

    /**
     * Is this a well-formed `<directory>/<filename>` pair we are willing to touch?
     *
     * Everything that reaches delete() or read() comes from an admin form post,
     * so it is attacker-influenced in the same way any admin input is. Rebuilding
     * the value from its two sanitised segments means a crafted
     * `../../app/etc/env.php` cannot address anything outside the media
     * directory, whatever the form sent.
     */
    public function isValidFile(string $fileBelowBase): bool
    {
        return $this->normalizeFile($fileBelowBase) === trim($fileBelowBase, '/');
    }

    /**
     * Reduce a `<directory>/<filename>` pair to its sanitised form, or '' if it
     * cannot be one.
     */
    public function normalizeFile(string $fileBelowBase): string
    {
        $segments = preg_split('#[\\\\/]#', trim($fileBelowBase, '/'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($segments) !== 2) {
            return '';
        }

        $directory = $this->sanitizeSegment($segments[0]);
        $name = $this->sanitizeSegment($segments[1]);

        return $directory === '' || $name === '' ? '' : $directory . '/' . $name;
    }

    /**
     * Last segment of a path.
     *
     * Hand-rolled rather than basename(): the Magento coding standard
     * discourages it, and the same split already has to happen for
     * sanitizeSegment() anyway.
     */
    public function getFileName(string $path): string
    {
        $segments = preg_split('#[\\\\/]#', trim($path), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $segments === [] ? '' : (string) $segments[count($segments) - 1];
    }

    /**
     * Lowercased extension of a file name, without the dot.
     */
    public function getExtension(string $fileName): string
    {
        $position = strrpos($fileName, '.');

        return $position === false ? '' : strtolower(substr($fileName, $position + 1));
    }

    /**
     * Reduce a value to a safe single path segment.
     *
     * Only the last path segment survives, then everything outside a strict
     * allowlist — which includes both directory separators — is replaced, and
     * leading dots are stripped so nothing can resolve to "." or "..". A SKU
     * such as `24-MB01/../..` therefore cannot name a directory outside the
     * media directory.
     */
    public function sanitizeSegment(string $value): string
    {
        $segments = preg_split('#[\\\\/]#', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [''];
        $value = (string) $segments[count($segments) - 1];
        $value = preg_replace('/[^A-Za-z0-9._-]/', '_', $value) ?? '';
        $value = ltrim($value, '.');

        return substr($value, 0, 120);
    }
}
