<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductAttachments\Model;

use Magenx\ProductAttachments\Model\ResourceModel\Attachment as AttachmentResource;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\File\Mime;
use Magento\Framework\Filesystem;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Attachment ROWS live in `magenx_product_attachment` (title, type, order —
 * see `Model\ResourceModel\Attachment`); attachment BYTES for `type=upload`
 * rows still live on disk under pub/media, exactly as before this table
 * existed. This class is the seam between the two: row CRUD delegates to the
 * resource model, disk I/O (`read()`, `getUrl()`, `getMimeType()`,
 * `moveDirectory()`) stays local.
 *
 * A file is described by its path BELOW the media directory (`SKU-1/manual.pdf`),
 * which is what the upload controller returns and what a row's `file` column
 * stores.
 */
class AttachmentRepository
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly AttachmentPath $path,
        private readonly Config $config,
        private readonly Mime $mime,
        private readonly StoreManagerInterface $storeManager,
        private readonly AttachmentResource $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Every attachment row of one product, keyed by attachment id, ordered
     * for display.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getProductAttachments(int $productId): array
    {
        return $this->resource->getByProductId($productId);
    }

    /**
     * One attachment row, or null if it does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function getAttachment(int $attachmentId): ?array
    {
        return $this->resource->getById($attachmentId);
    }

    /**
     * Insert or update one attachment row.
     *
     * @param array<string, mixed> $row
     */
    public function saveAttachment(?int $attachmentId, array $row): int
    {
        if ($attachmentId !== null) {
            $this->resource->update($attachmentId, $row);

            return $attachmentId;
        }

        return $this->resource->insert($row);
    }

    /**
     * Delete one attachment row, and — for a `type=upload` row — the file it
     * describes.
     */
    public function deleteAttachment(int $attachmentId): bool
    {
        $row = $this->resource->getById($attachmentId);
        if ($row === null) {
            return false;
        }

        if ($row['type'] === AttachmentType::UPLOAD && !empty($row['file'])) {
            $this->delete((string) $row['file']);
        }

        return $this->resource->delete($attachmentId);
    }

    /**
     * Raw bytes of one attachment, or null if it cannot be read.
     */
    public function read(string $fileBelowBase): ?string
    {
        $file = $this->path->normalizeFile($fileBelowBase);
        if ($file === '') {
            return null;
        }

        try {
            $read = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
            $mediaPath = $this->path->toMediaPath($file);

            return $read->isFile($mediaPath) ? $read->readFile($mediaPath) : null;
        } catch (FileSystemException $e) {
            $this->logger->warning(
                '[magenx_product_attachments] could not read "' . $fileBelowBase . '": ' . $e->getMessage()
            );

            return null;
        }
    }

    /**
     * Delete one attachment. Returns false if it was refused or absent.
     */
    public function delete(string $fileBelowBase): bool
    {
        $file = $this->path->normalizeFile($fileBelowBase);
        if ($file === '') {
            return false;
        }

        try {
            $write = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
            $mediaPath = $this->path->toMediaPath($file);

            return $write->isFile($mediaPath) && $write->delete($mediaPath);
        } catch (FileSystemException $e) {
            $this->logger->warning(
                '[magenx_product_attachments] could not delete "' . $fileBelowBase . '": ' . $e->getMessage()
            );

            return false;
        }
    }

    /**
     * Follow a SKU rename so the product keeps its uploaded files.
     *
     * Files are moved one by one and a name already taken in the destination is
     * left alone: the destination folder can legitimately be non-empty (someone
     * dropped files in under the new SKU before the rename), and overwriting
     * there would destroy a file nobody asked to replace. A row left behind by
     * a clash is not repointed either — its `file` column still resolves,
     * because the file itself did not move.
     */
    public function moveDirectory(int $productId, string $fromSku, string $toSku): void
    {
        $fromSegment = $this->path->sanitizeSegment($fromSku);
        $toSegment = $this->path->sanitizeSegment($toSku);
        $from = $this->path->getUploadDirectory($fromSku);
        $to = $this->path->getUploadDirectory($toSku);
        if ($from === $to) {
            return;
        }

        $moved = [];

        try {
            $write = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
            if (!$write->isDirectory($from)) {
                return;
            }

            $write->create($to);
            foreach ($write->read($from) as $entry) {
                if (!$write->isFile($entry)) {
                    continue;
                }

                $name = $this->path->getFileName($entry);
                $target = $to . '/' . $name;
                if ($write->isExist($target)) {
                    $this->logger->warning(
                        '[magenx_product_attachments] left "' . $entry . '" behind: "' . $target . '" already exists'
                    );
                    continue;
                }

                $write->renameFile($entry, $target);
                $moved[$name] = true;
            }

            // Only when nothing is left: a skipped clash must not be deleted.
            if ($write->read($from) === []) {
                $write->delete($from);
            }
        } catch (FileSystemException $e) {
            $this->logger->warning(
                '[magenx_product_attachments] could not move "' . $from . '" to "' . $to . '": ' . $e->getMessage()
            );

            return;
        }

        $this->repointMovedRows($productId, $fromSegment, $toSegment, $moved);
    }

    /**
     * A moved file's row still names its OLD folder in `file` — point it at
     * the new one, but only for the files that actually moved (a clash left
     * behind stays where its row already says it is).
     *
     * @param array<string, true> $movedFileNames keyed by bare filename
     */
    private function repointMovedRows(int $productId, string $fromSegment, string $toSegment, array $movedFileNames): void
    {
        if ($movedFileNames === []) {
            return;
        }

        foreach ($this->resource->getByProductId($productId) as $attachmentId => $row) {
            if (($row['type'] ?? null) !== AttachmentType::UPLOAD || empty($row['file'])) {
                continue;
            }

            $file = (string) $row['file'];
            $prefix = $fromSegment . '/';
            $name = $this->path->getFileName($file);
            if (!str_starts_with($file, $prefix) || !isset($movedFileNames[$name])) {
                continue;
            }

            $this->resource->update($attachmentId, ['file' => $toSegment . '/' . $name]);
        }
    }

    /**
     * Absolute path of the directory uploads for this SKU go to, created if needed.
     *
     * @throws FileSystemException
     */
    public function prepareUploadDirectory(string $sku): string
    {
        $write = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $directory = $this->path->getUploadDirectory($sku);
        $write->create($directory);

        return $write->getAbsolutePath($directory);
    }

    /**
     * Public URL of a media-relative path.
     */
    public function getUrl(string $mediaPath): string
    {
        try {
            $base = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
        } catch (\Exception $e) {
            return '';
        }

        return rtrim($base, '/') . '/' . ltrim($mediaPath, '/');
    }

    /**
     * MIME type of a media-relative path, guessed from content, never from the
     * name the uploader supplied.
     */
    public function getMimeType(string $mediaPath): string
    {
        try {
            $read = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);

            return $this->mime->getMimeType($read->getAbsolutePath($mediaPath));
        } catch (\Exception $e) {
            return 'application/octet-stream';
        }
    }

    /**
     * Size in bytes of a media-relative path, or null if it cannot be read.
     */
    public function getFileSize(string $mediaPath): ?int
    {
        try {
            $read = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);

            return $read->isFile($mediaPath) ? (int) ($read->stat($mediaPath)['size'] ?? 0) : null;
        } catch (FileSystemException $e) {
            return null;
        }
    }
}
