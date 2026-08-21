<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductAttachments\Observer;

use Magenx\ProductAttachments\Model\AttachmentPath;
use Magenx\ProductAttachments\Model\AttachmentRepository;
use Magenx\ProductAttachments\Model\AttachmentType;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Makes the "Product Attachments" fieldset authoritative on save.
 *
 * Three things happen, in this order:
 *
 *  1. Files sitting in the bulk upload buffer (posted separately from the
 *     metadata grid — see the Ui modifier's doc block for why the two are
 *     split) that don't already have a row become new `upload` rows, titled
 *     after their filename.
 *  2. The metadata grid is diffed against the DB: rows the admin removed are
 *     deleted (and, for `upload` rows, their file goes with them); rows still
 *     present are inserted or updated.
 *  3. A SKU rename moves `upload` rows' folder on disk and repoints their
 *     `file` column — done LAST, so steps 1-2 (which read/write file paths
 *     relative to the OLD SKU, because that's still the folder actually on
 *     disk at that point) see a consistent state.
 *
 * All three are gated on the metadata field being PRESENT in the product's
 * data — an import, a REST call or a mass attribute update never carries it,
 * and must not be read as "no attachments": that would empty the catalogue's
 * attachments on the first mass action.
 */
class SyncProductAttachments implements ObserverInterface
{
    /**
     * Field name of the metadata grid (dynamicRows: attachment_id, type,
     * title, file, external_url, sort_order) and the data key it posts back
     * under.
     */
    public const FORM_FIELD = 'magenx_product_attachments';

    /**
     * Field name of the bulk upload buffer (plain multi-file uploader:
     * {file, name, url, size, type} per entry). Always empty on page load —
     * see the Ui modifier — so whatever is here on save is freshly uploaded.
     */
    public const UPLOAD_FIELD = 'magenx_product_attachments_upload';

    private const MAX_EXTERNAL_URL_LENGTH = 1024;

    public function __construct(
        private readonly AttachmentRepository $attachmentRepository,
        private readonly AttachmentPath $path,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getData('product');
        if (!$product instanceof Product || !$product->hasData(self::FORM_FIELD)) {
            return;
        }

        $productId = (int) $product->getId();
        $sku = (string) $product->getSku();
        // getOrigData still holds the loaded row here: nothing between
        // _beforeSave and this event refreshes it.
        $originalSku = trim((string) ($product->getOrigData(ProductInterface::SKU) ?? ''));
        if ($originalSku === '') {
            $originalSku = $sku;
        }

        try {
            $existing = $this->attachmentRepository->getProductAttachments($productId);

            $rows = $this->withUploadedFilesAsRows($product, $existing);
            $this->syncRows($productId, $existing, $rows);

            if ($originalSku !== $sku) {
                // After the sync, not before: rows just inserted for this
                // save's uploads still name the OLD SKU's folder, because
                // that is where Upload.php actually wrote them.
                $this->attachmentRepository->moveDirectory($productId, $originalSku, $sku);
            }
        } catch (\Throwable $e) {
            // A sync that could not complete is a tidiness problem, not a
            // reason to lose the product edit itself.
            $this->logger->warning(
                '[magenx_product_attachments] sync failed for product ' . $productId . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * The posted metadata rows, plus one synthetic row per freshly uploaded
     * file that isn't already described by an existing row or another posted
     * row — this is what turns "uploaded, then saved" into a row appearing.
     *
     * @param array<int, array<string, mixed>> $existing keyed by attachment_id
     * @return array<int, array<string, mixed>>
     */
    private function withUploadedFilesAsRows(Product $product, array $existing): array
    {
        $rows = $this->getPostedRows($product);

        $known = [];
        foreach ($existing as $row) {
            if (($row['type'] ?? null) === AttachmentType::UPLOAD && !empty($row['file'])) {
                $known[(string) $row['file']] = true;
            }
        }
        foreach ($rows as $row) {
            $file = $this->path->normalizeFile((string) ($row['file'] ?? ''));
            if ($file !== '') {
                $known[$file] = true;
            }
        }

        $nextSortOrder = $this->nextSortOrder($existing, $rows);

        foreach ($this->getPostedUploads($product) as $upload) {
            $file = $this->path->normalizeFile((string) ($upload['file'] ?? ''));
            if ($file === '' || isset($known[$file])) {
                continue;
            }

            $known[$file] = true;
            $rows[] = [
                'attachment_id' => 0,
                'type' => AttachmentType::UPLOAD,
                'title' => $this->path->getFileName($file),
                'file' => $file,
                'external_url' => '',
                'sort_order' => $nextSortOrder++,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getPostedRows(Product $product): array
    {
        $posted = $product->getData(self::FORM_FIELD);
        $rows = [];

        foreach (is_array($posted) ? $posted : [] as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getPostedUploads(Product $product): array
    {
        $posted = $product->getData(self::UPLOAD_FIELD);
        $uploads = [];

        foreach (is_array($posted) ? $posted : [] as $upload) {
            if (is_array($upload)) {
                $uploads[] = $upload;
            }
        }

        return $uploads;
    }

    /**
     * @param array<int, array<string, mixed>> $existing
     * @param array<int, array<string, mixed>> $rows
     */
    private function nextSortOrder(array $existing, array $rows): int
    {
        $max = -1;
        foreach ([...$existing, ...$rows] as $row) {
            $max = max($max, (int) ($row['sort_order'] ?? 0));
        }

        return $max + 1;
    }

    /**
     * Insert/update every posted row, delete every existing row no longer
     * posted.
     *
     * @param array<int, array<string, mixed>> $existing keyed by attachment_id
     * @param array<int, array<string, mixed>> $rows
     */
    private function syncRows(int $productId, array $existing, array $rows): void
    {
        $kept = [];

        foreach ($rows as $row) {
            $attachmentId = (int) ($row['attachment_id'] ?? 0);
            $isExisting = $attachmentId > 0 && isset($existing[$attachmentId]);

            $data = $this->normalizeRow($productId, $row);
            if ($data === null) {
                // Incomplete row (e.g. Type left as External Link with no
                // URL entered): dropped rather than failing the whole save.
                continue;
            }

            $this->attachmentRepository->saveAttachment($isExisting ? $attachmentId : null, $data);
            if ($isExisting) {
                $kept[$attachmentId] = true;
            }
        }

        foreach (array_keys($existing) as $attachmentId) {
            if (!isset($kept[$attachmentId])) {
                $this->attachmentRepository->deleteAttachment($attachmentId);
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function normalizeRow(int $productId, array $row): ?array
    {
        $type = (string) ($row['type'] ?? AttachmentType::UPLOAD);
        if (!AttachmentType::isValid($type)) {
            $type = AttachmentType::UPLOAD;
        }

        $title = trim((string) ($row['title'] ?? ''));
        $sortOrder = (int) ($row['sort_order'] ?? 0);

        if ($type === AttachmentType::UPLOAD) {
            $file = $this->path->normalizeFile((string) ($row['file'] ?? ''));
            if ($file === '') {
                return null;
            }

            $mediaPath = $this->path->toMediaPath($file);

            return [
                'product_id' => $productId,
                'type' => AttachmentType::UPLOAD,
                'file' => $file,
                'external_url' => null,
                'title' => $title !== '' ? $title : $this->path->getFileName($file),
                'mime_type' => $this->attachmentRepository->getMimeType($mediaPath),
                'size' => $this->attachmentRepository->getFileSize($mediaPath),
                'sort_order' => $sortOrder,
            ];
        }

        $url = $this->normalizeExternalUrl((string) ($row['external_url'] ?? ''));
        if ($url === null) {
            return null;
        }

        return [
            'product_id' => $productId,
            'type' => AttachmentType::EXTERNAL,
            'file' => null,
            'external_url' => $url,
            'title' => $title !== '' ? $title : (string) (parse_url($url, PHP_URL_HOST) ?: __('External Link')),
            'mime_type' => null,
            'size' => null,
            'sort_order' => $sortOrder,
        ];
    }

    /**
     * `http(s)` absolute URLs only — no `javascript:`, `file:`, `data:`, and
     * no bare relative path. Re-checked, independently, wherever this URL is
     * about to be fetched (the download resolver): this is the admin-side
     * gate, not the only one.
     */
    private function normalizeExternalUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > self::MAX_EXTERNAL_URL_LENGTH) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true) || $parts['host'] === '') {
            return null;
        }

        return $url;
    }
}
