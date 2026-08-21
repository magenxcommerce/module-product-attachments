<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductAttachments\Model\Resolver;

use Magenx\ProductAttachments\Model\AttachmentPath;
use Magenx\ProductAttachments\Model\AttachmentRepository;
use Magenx\ProductAttachments\Model\Config;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Resolves ProductInterface.magenx_attachments.
 *
 * Deliberately a PLAIN resolver, not a BatchResolverInterface, mirroring
 * StripeIntegration\PaymentsGraphQl's ProductSubscription: this field is
 * PDP-only by fragment discipline (selected on PRODUCT_FIELDS in the theme,
 * never on the card/linked-product fragments that ride category and search
 * grids — see fragments.ts). If that discipline is ever broken and this
 * field ends up on a grid fragment, convert to BatchResolverInterface first
 * — do not let it fan out per-row the way custom_attributesV2 once did.
 *
 * Returns metadata only — id, title, type, extension, mime type, size.
 * There is deliberately NO url/path/file field: the real location is never
 * handed to the browser, only to Resolver\ProductAttachmentDownload, which
 * is itself reachable only server-to-server (kept off the persisted-query
 * allowlist, never called from a browser).
 */
class ProductAttachments implements ResolverInterface
{
    public function __construct(
        private readonly AttachmentRepository $attachmentRepository,
        private readonly AttachmentPath $path,
        private readonly Config $config
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        if (empty($value['model'])) {
            return null;
        }

        $product = $value['model'];
        $productId = method_exists($product, 'getId') ? (int) $product->getId() : 0;
        if ($productId <= 0) {
            return null;
        }

        $storeId = $context instanceof ContextInterface
            ? $context->getExtensionAttributes()->getStore()->getId()
            : null;
        if (!$this->config->isShowOnStorefront($storeId !== null ? (int) $storeId : null)) {
            return null;
        }

        $rows = [];
        foreach ($this->attachmentRepository->getProductAttachments($productId) as $attachmentId => $row) {
            $rows[] = [
                'id' => $attachmentId,
                'title' => (string) ($row['title'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
                'file_extension' => $this->extensionOf($row),
                'mime_type' => $row['mime_type'] ?? null,
                'size' => isset($row['size']) ? (int) $row['size'] : null,
            ];
        }

        return $rows === [] ? null : $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function extensionOf(array $row): ?string
    {
        if (!empty($row['file'])) {
            $extension = $this->path->getExtension($this->path->getFileName((string) $row['file']));

            return $extension !== '' ? $extension : null;
        }

        if (!empty($row['external_url'])) {
            // Reading the path segment off a merchant-entered external URL,
            // not a Magento URL.
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            $path = (string) (parse_url((string) $row['external_url'], PHP_URL_PATH) ?: '');
            $extension = $this->path->getExtension($this->path->getFileName($path));

            return $extension !== '' ? $extension : null;
        }

        return null;
    }
}
