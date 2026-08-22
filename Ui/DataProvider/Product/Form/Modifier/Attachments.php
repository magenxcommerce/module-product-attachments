<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductAttachments\Ui\DataProvider\Product\Form\Modifier;

use Magenx\ProductAttachments\Model\AttachmentPath;
use Magenx\ProductAttachments\Model\AttachmentRepository;
use Magenx\ProductAttachments\Model\AttachmentType;
use Magenx\ProductAttachments\Model\Config;
use Magenx\ProductAttachments\Model\Config\Source\AttachmentType as AttachmentTypeSource;
use Magenx\ProductAttachments\Observer\SyncProductAttachments;
use Magento\Catalog\Model\Locator\LocatorInterface;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Framework\UrlInterface;
use Magento\Ui\Component\Container;
use Magento\Ui\Component\DynamicRows;
use Magento\Ui\Component\Form\Element\DataType\Number;
use Magento\Ui\Component\Form\Element\DataType\Text;
use Magento\Ui\Component\Form\Element\Input;
use Magento\Ui\Component\Form\Element\Select;
use Magento\Ui\Component\Form\Field;
use Magento\Ui\Component\Form\Fieldset;

/**
 * Adds the "Product Attachments" fieldset to the product edit form.
 *
 * A single `dynamicRows` grid of the rows in `magenx_product_attachment` —
 * title, type, sort order, and, driven by the row's own Type select via
 * `switcherConfig`, either a live file uploader (Type = Uploaded File) or an
 * External URL field (Type = External Link). Each row is self-contained:
 * picking Upload shows a click-to-upload control that writes straight to
 * pub/media/<sku>/ as soon as a file is chosen, no separate top-of-form
 * uploader or second save step required.
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
        private readonly AttachmentTypeSource $attachmentTypeSource,
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

        $rows = [];
        foreach ($this->attachmentRepository->getProductAttachments($productId) as $attachmentId => $row) {
            $type = (string) ($row['type'] ?? AttachmentType::UPLOAD);
            $file = (string) ($row['file'] ?? '');

            $rows[] = [
                'attachment_id' => $attachmentId,
                'type' => $type,
                'title' => (string) ($row['title'] ?? ''),
                // The fileUploader component expects its value as an array of
                // file descriptors (what it also gets back from an upload),
                // not a bare path — this is what makes an existing upload row
                // render as already attached instead of empty.
                'file' => $type === AttachmentType::UPLOAD && $file !== ''
                    ? [[
                        'file' => $file,
                        'name' => $this->path->getFileName($file),
                        'size' => (int) ($row['size'] ?? 0),
                        'type' => (string) ($row['mime_type'] ?? ''),
                        'url' => $this->attachmentRepository->getUrl($this->path->toMediaPath($file)),
                    ]]
                    : [],
                'external_url' => (string) ($row['external_url'] ?? ''),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
            ];
        }

        $data[$productId][self::DATA_SOURCE_DEFAULT][self::FIELD_NAME] = $rows;

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
                ? $this->getFieldsetChildren($product->getSku() !== null ? (string) $product->getSku() : '', $productId)
                : $this->getUnsavedProductChildren(),
        ];

        return $meta;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getFieldsetChildren(string $sku, int $productId): array
    {
        return [
            'magenx_product_attachments_notice' => $this->getNotice(
                __(
                    'Pick a Type for each row: "Uploaded File" shows a file field — click it to upload '
                    . 'immediately; "External Link" shows a URL field instead. Files are written to '
                    . '<code>pub/media/%1/</code>.',
                    $this->path->getUploadDirectory($sku)
                )
            ),
            self::FIELD_NAME => $this->getAttachmentsGrid($productId),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getAttachmentsGrid(int $productId): array
    {
        return [
            'arguments' => [
                'data' => [
                    'config' => [
                        'additionalClasses' => 'admin__field-wide',
                        'componentType' => DynamicRows::NAME,
                        'label' => __('Attachments'),
                        'columnsHeader' => true,
                        'columnsHeaderAfterRender' => true,
                        'component' => 'Magento_Ui/js/dynamic-rows/dynamic-rows',
                        'template' => 'ui/dynamic-rows/templates/default',
                        'addButtonLabel' => __('Add Attachment'),
                        'deleteButtonLabel' => __('Remove'),
                        'dataScope' => '',
                        'sortOrder' => 30,
                    ],
                ],
            ],
            'children' => [
                'record' => [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'componentType' => Container::NAME,
                                'isTemplate' => true,
                                'is_collection' => true,
                                'component' => 'Magento_Ui/js/dynamic-rows/record',
                                'dataScope' => '',
                            ],
                        ],
                    ],
                    'children' => $this->getAttachmentsGridColumns($productId),
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getAttachmentsGridColumns(int $productId): array
    {
        return [
            'attachment_id' => [
                'arguments' => [
                    'data' => [
                        'config' => [
                            'componentType' => Field::NAME,
                            'formElement' => 'input',
                            'dataType' => Number::NAME,
                            'dataScope' => 'attachment_id',
                            'visible' => false,
                            'sortOrder' => 0,
                        ],
                    ],
                ],
            ],
            'type' => [
                'arguments' => [
                    'data' => [
                        'config' => [
                            'label' => __('Type'),
                            'componentType' => Field::NAME,
                            'formElement' => Select::NAME,
                            'dataType' => Text::NAME,
                            'dataScope' => 'type',
                            'options' => $this->attachmentTypeSource->toOptionArray(),
                            'value' => AttachmentType::UPLOAD,
                            'sortOrder' => 10,
                            // Drives which of `file` / `external_url` is shown
                            // in this row, and clears the one being hidden so
                            // switching back later doesn't resurrect a stale
                            // value that no longer matches what's saved.
                            'switcherConfig' => [
                                'component' => 'Magento_Ui/js/form/switcher',
                                'enabled' => true,
                                'rules' => [
                                    [
                                        'value' => AttachmentType::UPLOAD,
                                        'actions' => [
                                            [
                                                'target' => '${ $.parentName }.file',
                                                'callback' => 'visible',
                                                'params' => [true],
                                            ],
                                            [
                                                'target' => '${ $.parentName }.external_url',
                                                'callback' => 'visible',
                                                'params' => [false],
                                            ],
                                            [
                                                'target' => '${ $.parentName }.external_url',
                                                'callback' => 'value',
                                                'params' => [''],
                                            ],
                                        ],
                                    ],
                                    [
                                        'value' => AttachmentType::EXTERNAL,
                                        'actions' => [
                                            [
                                                'target' => '${ $.parentName }.file',
                                                'callback' => 'visible',
                                                'params' => [false],
                                            ],
                                            [
                                                'target' => '${ $.parentName }.external_url',
                                                'callback' => 'visible',
                                                'params' => [true],
                                            ],
                                            [
                                                'target' => '${ $.parentName }.file',
                                                'callback' => 'value',
                                                'params' => [[]],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'title' => [
                'arguments' => [
                    'data' => [
                        'config' => [
                            'label' => __('Title'),
                            'componentType' => Field::NAME,
                            'formElement' => Input::NAME,
                            'dataType' => Text::NAME,
                            'dataScope' => 'title',
                            'sortOrder' => 20,
                        ],
                    ],
                ],
            ],
            'file' => [
                'arguments' => [
                    'data' => [
                        'config' => [
                            'label' => __('Uploaded File'),
                            'componentType' => Field::NAME,
                            'formElement' => 'fileUploader',
                            'component' => 'Magento_Ui/js/form/element/file-uploader',
                            'elementTmpl' => 'ui/form/element/uploader/uploader',
                            'dataType' => Text::NAME,
                            'dataScope' => 'file',
                            'isMultipleFiles' => false,
                            'placeholderType' => 'document',
                            'allowedExtensions' => implode(' ', $this->config->getAllowedExtensions()),
                            'maxFileSize' => $this->config->getMaxFileSize(),
                            'uploaderConfig' => [
                                'url' => $this->urlBuilder->getUrl(
                                    self::UPLOAD_URL,
                                    ['product_id' => $productId, 'store' => $this->getStoreId()]
                                ),
                            ],
                            'sortOrder' => 30,
                        ],
                    ],
                ],
            ],
            'external_url' => [
                'arguments' => [
                    'data' => [
                        'config' => [
                            'label' => __('External URL'),
                            'componentType' => Field::NAME,
                            'formElement' => Input::NAME,
                            'dataType' => Text::NAME,
                            'dataScope' => 'external_url',
                            'placeholder' => 'https://example.com/file.pdf',
                            'visible' => false,
                            'sortOrder' => 40,
                        ],
                    ],
                ],
            ],
            'sort_order' => [
                'arguments' => [
                    'data' => [
                        'config' => [
                            'label' => __('Sort Order'),
                            'componentType' => Field::NAME,
                            'formElement' => Input::NAME,
                            'dataType' => Number::NAME,
                            'dataScope' => 'sort_order',
                            'value' => 0,
                            'sortOrder' => 50,
                        ],
                    ],
                ],
            ],
            'actionDelete' => [
                'arguments' => [
                    'data' => [
                        'config' => [
                            'componentType' => 'actionDelete',
                            'dataType' => Text::NAME,
                            'label' => '',
                            'sortOrder' => 60,
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
