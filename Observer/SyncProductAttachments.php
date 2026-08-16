<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductAttachments\Observer;

use Magenx\ProductAttachments\Model\AttachmentPath;
use Magenx\ProductAttachments\Model\AttachmentRepository;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Makes the product form's attachment list authoritative on save.
 *
 * Uploads are written straight to their final folder by the upload controller,
 * so a save has only two things left to do:
 *
 *  1. delete the files the admin removed from the list;
 *  2. follow a SKU rename, so files uploaded under the old SKU stay with the
 *     product.
 *
 * Both are gated on the form field being PRESENT in the product's data. An
 * import, a REST call, a mass-attribute-update or a third-party save never
 * carries `magenx_product_attachments`, and must not be read as "the admin
 * cleared the list" — that would delete every file in the store's catalogue on
 * the first mass action.
 */
class SyncProductAttachments implements ObserverInterface
{
    /**
     * Field name on the product form, and the data key it posts back under.
     */
    public const FORM_FIELD = 'magenx_product_attachments';

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
            $this->deleteRemovedFiles($product, $originalSku, $productId);

            if ($originalSku !== $sku) {
                // After the deletions, not before: the submitted list was
                // rendered against the OLD SKU's folder, so its paths only
                // resolve while that folder still carries its old name.
                $this->attachmentRepository->moveDirectory($originalSku, $sku);
            }
        } catch (\Throwable $e) {
            // A file that could not be deleted is a tidiness problem. Failing
            // the save would lose the product edit itself.
            $this->logger->warning(
                '[magenx_product_attachments] sync failed for product ' . $productId . ': ' . $e->getMessage()
            );
        }
    }

    private function deleteRemovedFiles(Product $product, string $originalSku, int $productId): void
    {
        $submitted = $product->getData(self::FORM_FIELD);
        $keep = [];

        foreach (is_array($submitted) ? $submitted : [] as $row) {
            if (!is_array($row) || !isset($row['file'])) {
                continue;
            }

            $file = $this->path->normalizeFile((string) $row['file']);
            if ($file !== '') {
                $keep[$file] = true;
            }
        }

        foreach ($this->attachmentRepository->getProductFiles($originalSku, $productId) as $file => $info) {
            if (!isset($keep[$file])) {
                $this->attachmentRepository->delete($file);
            }
        }
    }
}
