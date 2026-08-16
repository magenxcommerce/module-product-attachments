<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductAttachments\Model\Mail;

use Magenx\ProductAttachments\Model\AttachmentRepository;
use Magenx\ProductAttachments\Model\Config;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Turns the products in an order into MIME parts.
 *
 * Symfony's DataPart is built directly rather than through Magento's
 * MimePartInterfaceFactory, and that is not a shortcut: since 2.4.7 Magento's
 * own MimeMessage keeps only the FIRST TextPart it is handed and drops
 * everything else (\Magento\Framework\Mail\MimeMessage::__construct), so a
 * MimePart wrapping a DataPart would be silently discarded on its way into the
 * message. The Symfony part is the thing that survives.
 *
 * Every cap here is a fail-soft: an oversize or unreadable file is skipped and
 * logged, never a reason for the customer's order confirmation not to arrive.
 */
class OrderAttachmentProvider
{
    public function __construct(
        private readonly AttachmentRepository $attachmentRepository,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return DataPart[] empty when the order has no attachable files
     */
    public function getParts(OrderInterface $order, ?int $storeId = null): array
    {
        $files = $this->collectFiles($order);
        if ($files === []) {
            return [];
        }

        $maxCount = $this->config->getMaxCount($storeId);
        $maxFileSize = $this->config->getMaxFileSize();
        $remaining = $this->config->getMaxTotalSize($storeId);
        $parts = [];

        foreach ($files as $file) {
            if (count($parts) >= $maxCount) {
                $this->logger->warning(sprintf(
                    '[magenx_product_attachments] order %s: dropped %d attachment(s) over the per-email limit of %d',
                    (string) $order->getIncrementId(),
                    count($files) - count($parts),
                    $maxCount
                ));
                break;
            }

            if ($file['size'] > $maxFileSize) {
                $this->logger->warning(sprintf(
                    '[magenx_product_attachments] order %s: skipped "%s" (%d bytes, per-file limit %d)',
                    (string) $order->getIncrementId(),
                    $file['file'],
                    $file['size'],
                    $maxFileSize
                ));
                continue;
            }

            if ($file['size'] > $remaining) {
                // Skip, do not stop: a small manual after a large video is still
                // worth sending.
                $this->logger->warning(sprintf(
                    '[magenx_product_attachments] order %s: skipped "%s", %d bytes left in the per-email budget',
                    (string) $order->getIncrementId(),
                    $file['file'],
                    $remaining
                ));
                continue;
            }

            $content = $this->attachmentRepository->read($file['file']);
            if ($content === null || $content === '') {
                continue;
            }

            $parts[] = new DataPart(
                $content,
                $file['name'],
                $file['type'] !== '' ? $file['type'] : 'application/octet-stream',
                'base64'
            );
            $remaining -= $file['size'];
        }

        return $parts;
    }

    /**
     * Attachments of every product in the order, de-duplicated.
     *
     * Keyed by path below the media directory, so ordering the same product
     * twice — or two configurable children of one parent that share a folder —
     * mails the file once.
     *
     * @return array<string, array{name: string, file: string, url: string, size: int, type: string}>
     */
    private function collectFiles(OrderInterface $order): array
    {
        $files = [];
        $seenProducts = [];

        foreach ($order->getItems() ?? [] as $item) {
            if (!$item instanceof OrderItemInterface) {
                continue;
            }

            // Child rows are read too, not skipped. A configurable's manual can
            // sit on the configurable or on the simple that was actually bought
            // — both conventions exist in real catalogues, and de-duplication
            // makes reading both free when a merchant uses only one.
            $sku = (string) $item->getSku();
            $productId = (int) $item->getProductId();
            $key = $sku . '|' . $productId;
            if (isset($seenProducts[$key])) {
                continue;
            }
            $seenProducts[$key] = true;

            $files += $this->attachmentRepository->getProductFiles($sku, $productId);
        }

        return $files;
    }
}
