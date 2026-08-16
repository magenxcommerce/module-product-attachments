<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductAttachments\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed reader over magenx_product_attachments/**.
 *
 * Store-scoped where the value is a merchandising decision (what gets attached
 * to which email, how much of it); global where it describes the filesystem or
 * what is safe to accept at all.
 */
class Config
{
    public const XML_ENABLED = 'magenx_product_attachments/general/enabled';
    public const XML_MEDIA_DIRECTORY = 'magenx_product_attachments/general/media_directory';
    public const XML_ATTACH_TO = 'magenx_product_attachments/general/attach_to';
    public const XML_ALLOWED_EXTENSIONS = 'magenx_product_attachments/limits/allowed_extensions';
    public const XML_MAX_FILE_SIZE = 'magenx_product_attachments/limits/max_file_size';
    public const XML_MAX_TOTAL_SIZE = 'magenx_product_attachments/limits/max_total_size';
    public const XML_MAX_COUNT = 'magenx_product_attachments/limits/max_count';

    public const DEFAULT_MEDIA_DIRECTORY = 'product_attachments';

    /**
     * Refused whatever the merchant types into Allowed Extensions.
     *
     * pub/media is web-served. Magento ships a .htaccess there that stops PHP
     * from executing, but that file only exists for Apache and only if it was
     * deployed — an nginx install with the wrong location block, or a merchant
     * who added "php" to the allowlist because a customer wanted a code sample,
     * is one upload away from remote code execution. Denying the executable
     * types outright costs nothing and does not depend on webserver config.
     *
     * @var string[]
     */
    private const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'pht', 'phtm', 'phtml', 'phar',
        'htaccess', 'htpasswd', 'user.ini', 'ini',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh', 'ksh',
        'jsp', 'jspx', 'asp', 'aspx', 'cfm',
        'exe', 'com', 'bat', 'cmd', 'msi', 'dll', 'so', 'dylib', 'jar', 'js', 'mjs', 'html', 'htm', 'shtml', 'svg', 'xml',
    ];

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * The pub/media sub-folder holding one directory per product — GLOBAL.
     *
     * A per-store value would mean a file uploaded while store A was selected
     * lands somewhere the admin looking at store B cannot see, and an order
     * placed on either store would read only one of the two folders.
     */
    public function getMediaDirectory(): string
    {
        $value = trim((string) $this->scopeConfig->getValue(self::XML_MEDIA_DIRECTORY));
        // One segment, no traversal: this value is concatenated into a media
        // path that both the uploader and the mailer resolve.
        $value = preg_replace('/[^A-Za-z0-9._-]/', '', $value) ?? '';
        $value = trim($value, '.');

        return $value === '' ? self::DEFAULT_MEDIA_DIRECTORY : $value;
    }

    /**
     * Sales email types that carry attachments.
     *
     * @return string[] subset of the Model\Config\Source\EmailType constants
     */
    public function getAttachToTypes(?int $storeId = null): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_ATTACH_TO, ScopeInterface::SCOPE_STORE, $storeId);

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * Extensions accepted on upload and mailed out, lowercased, dots stripped.
     *
     * @return string[]
     */
    public function getAllowedExtensions(): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_ALLOWED_EXTENSIONS);
        $extensions = [];

        foreach (explode(',', $raw) as $extension) {
            $extension = strtolower(trim($extension, " \t\n\r\0\x0B."));
            if ($extension === '' || in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
                continue;
            }
            $extensions[$extension] = $extension;
        }

        return array_values($extensions);
    }

    public function isExtensionAllowed(string $extension): bool
    {
        return in_array(strtolower(ltrim($extension, '.')), $this->getAllowedExtensions(), true);
    }

    /**
     * Largest single file, in BYTES.
     */
    public function getMaxFileSize(): int
    {
        return max(1, (int) $this->scopeConfig->getValue(self::XML_MAX_FILE_SIZE));
    }

    /**
     * Largest combined attachment payload on one email, in BYTES.
     */
    public function getMaxTotalSize(?int $storeId = null): int
    {
        return max(1, (int) $this->scopeConfig->getValue(
            self::XML_MAX_TOTAL_SIZE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    public function getMaxCount(?int $storeId = null): int
    {
        return max(1, (int) $this->scopeConfig->getValue(
            self::XML_MAX_COUNT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }
}
