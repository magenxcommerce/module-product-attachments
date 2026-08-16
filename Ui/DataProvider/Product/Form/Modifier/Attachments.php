<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductAttachments\Ui\DataProvider\Product\Form\Modifier;

use Magenx\ProductAttachments\Model\AttachmentPath;
use Magenx\ProductAttachments\Model\AttachmentRepository;
use Magenx\ProductAttachments\Model\Config;
use Magenx\ProductAttachments\Observer\SyncProductAttachments;
use Magento\Catalog\Model\Locator\LocatorInterface;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Framework\UrlInterface;
use Magento\Ui\Component\Container;
use Magento\Ui\Component\Form\Element\DataType\Text;
use Magento\Ui\Component\Form\Field;
use Magento\Ui\Component\Form\Fieldset;

/**
 * Adds the "Product Attachments" fieldset to the product edit form.
 *
 * The list it shows is a directory listing, not a stored value: files dropped
 * into pub/media by hand appear here on the next page load with no import step,
 * which is the whole point of keeping the filesystem authoritative.
 *
 * Uploading needs a saved product, because the destination folder is named
 * after the SKU and a new product has neither a SKU nor an id yet. Rather than
 * upload to a tmp area and promote it on save — a second source of truth, and
 * the thing this module exists to avoid — the fieldset explains itself and
 * waits.
 */
class Attachments extends AbstractModifier
{
    private const GROUP_NAME = 'magenx_product_attachments';
    private const FIELD_NAME = SyncProductAttachments::FORM_FIELD;
    private const UPLOAD_URL = 'magenx_productattachments/attachment/upload';

    public function __construct(
        private readonly LocatorInterface $locator,
        private readonly AttachmentRepository $attachmentRepository,
        private readonly AttachmentPath $path,
        private readonly Config $config,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function modifyData(array $data): array
    {
        $product = $this->locator->getProduct();
        $productId = (int) $product->getId();
        if ($productId <= 0 || !$this->config->isEnabled($this->getStoreId())) {
            return $data;
        }

        $files = $this->attachmentRepository->getProductFiles((string) $product->getSku(), $productId);
        $data[$productId][self::DATA_SOURCE_DEFAULT][self::FIELD_NAME] = array_values($files);

        return $data;
    }

    /**
     * @inheritDoc
     */
    public function modifyMeta(array $meta): array
    {
        // The feature flag is a master switch, not an email-only one: with it
        // off the fieldset is absent from the form altogether, so the admin is
        // never offered an upload whose files would go nowhere.
        if (!$this->config->isEnabled($this->getStoreId())) {
            return $meta;
        }

        $product = $this->locator->getProduct();
        $productId = (int) $product->getId();

        $meta[self::GROUP_NAME] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'label' => __('Product Attachments'),
                        'componentType' => Fieldset::NAME,
                        'dataScope' => self::DATA_SCOPE_PRODUCT,
                        'collapsible' => true,
                        'opened' => false,
                        'sortOrder' => 160,
                    ],
                ],
            ],
            'children' => $productId > 0
                ? $this->getUploaderChildren($product->getSku() !== null ? (string) $product->getSku() : '', $productId)
                : $this->getUnsavedProductChildren(),
        ];

        return $meta;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getUploaderChildren(string $sku, int $productId): array
    {
        return [
            'magenx_product_attachments_notice' => $this->getNotice(
                __(
                    'Files are read from <code>pub/media/%1/</code> and <code>pub/media/%2/</code>. '
                    . 'Anything copied into either folder outside the admin shows up here too, and is '
                    . 'attached to the sales emails configured under Stores &gt; Configuration &gt; Magenx &gt; '
                    . 'Product Attachments.',
                    $this->path->getUploadDirectory($sku),
                    $this->path->getBaseDirectory() . '/' . $productId
                )
            ),
            self::FIELD_NAME => [
                'arguments' => [
                    'data' => [
                        'config' => [
                            'label' => __('Files'),
                            'componentType' => Field::NAME,
                            'formElement' => 'fileUploader',
                            'component' => 'Magento_Ui/js/form/element/file-uploader',
                            'elementTmpl' => 'ui/form/element/uploader/uploader',
                            'dataType' => Text::NAME,
                            'dataScope' => self::FIELD_NAME,
                            'sortOrder' => 20,
                            'isMultipleFiles' => true,
                            'placeholderType' => 'document',
                            'allowedExtensions' => implode(' ', $this->config->getAllowedExtensions()),
                            'maxFileSize' => $this->config->getMaxFileSize(),
                            'uploaderConfig' => [
                                'url' => $this->urlBuilder->getUrl(
                                    self::UPLOAD_URL,
                                    ['product_id' => $productId, 'store' => $this->getStoreId()]
                                ),
                            ],
                            'notice' => __(
                                'An upload is stored immediately. Removing a file from this list deletes it '
                                . 'when the product is saved.'
                            ),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getUnsavedProductChildren(): array
    {
        return [
            'magenx_product_attachments_notice' => $this->getNotice(
                __('Save the product first — its attachment folder is named after the SKU.')
            ),
        ];
    }

    /**
     * Store view the form is being edited in, 0 for the default scope.
     */
    private function getStoreId(): int
    {
        return (int) $this->locator->getStore()->getId();
    }

    /**
     * @param \Magento\Framework\Phrase $content
     * @return array<string, array<string, mixed>>
     */
    private function getNotice($content): array
    {
        return [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType' => Container::NAME,
                        'component' => 'Magento_Ui/js/form/components/html',
                        'additionalClasses' => 'admin__field-note',
                        'content' => $content,
                        'sortOrder' => 10,
                    ],
                ],
            ],
        ];
    }
}
