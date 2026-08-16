<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductAttachments\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\File\Mime;
use Magento\Framework\Filesystem;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The filesystem IS the data store.
 *
 * There is no table of attachments, and that is the design: the merchant was
 * always going to drop files into pub/media over SFTP, so a database row would
 * be a second source of truth that a shell copy silently desynchronises. Listing
 * a directory is also all the admin form and the mailer ever need — neither
 * sorts, filters or joins.
 *
 * A file is described by its path BELOW the media directory (`SKU-1/manual.pdf`),
 * which is what the product form posts back and what delete() accepts.
 */
class AttachmentRepository
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly AttachmentPath $path,
        private readonly Config $config,
        private readonly Mime $mime,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Every attachment of one product, keyed by file path below the media dir.
     *
     * Files whose extension is not allowed are skipped rather than reported:
     * pub/media collects .DS_Store, Thumbs.db and half-finished uploads, and a
     * merchant should not have to clean those out to stop the admin nagging.
     *
     * @return array<string, array{name: string, file: string, url: string, size: int, type: string}>
     */
    public function getProductFiles(string $sku, int $productId): array
    {
        $read = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $files = [];

        foreach ($this->path->getProductDirectories($sku, $productId) as $directory) {
            try {
                if (!$read->isDirectory($directory)) {
                    continue;
                }

                foreach ($read->read($directory) as $entry) {
                    if (!$read->isFile($entry)) {
                        continue;
                    }

                    $name = $this->path->getFileName($entry);
                    if (!$this->config->isExtensionAllowed($this->path->getExtension($name))) {
                        continue;
                    }

                    $file = $this->path->toFileBelowBase($entry);
                    $files[$file] = [
                        'name' => $name,
                        'file' => $file,
                        'url' => $this->getUrl($entry),
                        'size' => (int) ($read->stat($entry)['size'] ?? 0),
                        'type' => $this->getMimeType($entry),
                    ];
                }
            } catch (FileSystemException $e) {
                $this->logger->warning(
                    '[magenx_product_attachments] could not read "' . $directory . '": ' . $e->getMessage()
                );
            }
        }

        ksort($files);

        return $files;
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
     * there would destroy a file nobody asked to replace.
     */
    public function moveDirectory(string $fromSku, string $toSku): void
    {
        $from = $this->path->getUploadDirectory($fromSku);
        $to = $this->path->getUploadDirectory($toSku);
        if ($from === $to) {
            return;
        }

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

                $target = $to . '/' . $this->path->getFileName($entry);
                if ($write->isExist($target)) {
                    $this->logger->warning(
                        '[magenx_product_attachments] left "' . $entry . '" behind: "' . $target . '" already exists'
                    );
                    continue;
                }

                $write->renameFile($entry, $target);
            }

            // Only when nothing is left: a skipped clash must not be deleted.
            if ($write->read($from) === []) {
                $write->delete($from);
            }
        } catch (FileSystemException $e) {
            $this->logger->warning(
                '[magenx_product_attachments] could not move "' . $from . '" to "' . $to . '": ' . $e->getMessage()
            );
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
}
