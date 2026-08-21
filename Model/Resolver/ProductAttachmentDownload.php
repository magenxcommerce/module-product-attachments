<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductAttachments\Model\Resolver;

use Magenx\ProductAttachments\Model\AttachmentPath;
use Magenx\ProductAttachments\Model\AttachmentRepository;
use Magenx\ProductAttachments\Model\AttachmentType;
use Magenx\ProductAttachments\Model\Config;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Resolves Query.magenxProductAttachmentDownload(id).
 *
 * SERVER-ONLY by convention, not by secret: this must NEVER be added to a
 * storefront persisted-query allowlist or called from a browser, because it
 * is the one place that resolves an attachment id back to a real, fetchable
 * location, and the Next.js /api/download/[id] route exists specifically so
 * the browser never has to see that. There is no shared-secret gate here —
 * Magento's GraphQL endpoint is only reachable from the storefront's private
 * network, and every attachment this resolves is already meant to be a
 * public download once resolved, so a secret would guard nothing that
 * network placement + the allowlist omission don't already.
 *
 * A plain resolver: this is a single root-query lookup by id, not a field
 * riding a list of sibling objects, so BatchResolverInterface buys nothing
 * here.
 */
class ProductAttachmentDownload implements ResolverInterface
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
        $attachmentId = (int) ($args['id'] ?? 0);
        if ($attachmentId <= 0) {
            return null;
        }

        $row = $this->attachmentRepository->getAttachment($attachmentId);
        if ($row === null) {
            return null;
        }

        $productId = (int) ($row['product_id'] ?? 0);
        if ($productId <= 0 || !$this->config->isShowOnStorefront()) {
            return null;
        }

        return $row['type'] === AttachmentType::UPLOAD
            ? $this->uploadPayload($row)
            : $this->externalPayload($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function uploadPayload(array $row): ?array
    {
        $file = (string) ($row['file'] ?? '');
        $normalized = $this->path->normalizeFile($file);
        if ($normalized === '') {
            return null;
        }

        $mediaPath = $this->path->toMediaPath($normalized);
        $url = $this->attachmentRepository->getUrl($mediaPath);
        if ($url === '') {
            return null;
        }

        return [
            'type' => AttachmentType::UPLOAD,
            'file_url' => $url,
            'filename' => $this->filenameFor($row, $this->path->getFileName($normalized)),
            'mime_type' => $row['mime_type'] ?? $this->attachmentRepository->getMimeType($mediaPath),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function externalPayload(array $row): ?array
    {
        $url = (string) ($row['external_url'] ?? '');
        // Re-validating the stored external URL's shape before it is
        // resolved, not a Magento URL.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true) || $parts['host'] === ''
        ) {
            return null;
        }

        $name = $this->path->getFileName((string) ($parts['path'] ?? ''));

        return [
            'type' => AttachmentType::EXTERNAL,
            'file_url' => $url,
            'filename' => $this->filenameFor($row, $name),
            'mime_type' => $row['mime_type'] ?? null,
        ];
    }

    /**
     * The row's title if it already looks like a filename (has an
     * extension), else the title with the real file's extension appended —
     * so a customer's downloaded file opens with the right application even
     * when the admin typed a plain label like "Installation Manual".
     *
     * @param array<string, mixed> $row
     */
    private function filenameFor(array $row, string $fallbackName): string
    {
        $title = trim((string) ($row['title'] ?? ''));
        $extension = $this->path->getExtension($fallbackName);

        if ($title === '') {
            return $fallbackName !== '' ? $fallbackName : 'download';
        }

        if ($extension !== '' && $this->path->getExtension($title) === strtolower($extension)) {
            return $title;
        }

        return $extension !== '' ? $title . '.' . $extension : $title;
    }
}
