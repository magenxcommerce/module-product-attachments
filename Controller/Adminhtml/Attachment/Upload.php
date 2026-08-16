<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductAttachments\Controller\Adminhtml\Attachment;

use Magenx\ProductAttachments\Model\AttachmentPath;
use Magenx\ProductAttachments\Model\AttachmentRepository;
use Magenx\ProductAttachments\Model\Config;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\MediaStorage\Model\File\UploaderFactory;

/**
 * Receives one file from the product form's uploader.
 *
 * The file goes straight to its final home — pub/media/<dir>/<sku>/ — instead of
 * a tmp folder promoted on save. That is what makes an upload here and a file
 * dropped in over SFTP the same thing: there is one location, and nothing has
 * to reconcile a staging area with it. The cost is that a file uploaded into a
 * form the admin then abandons stays on disk; it is visible and deletable the
 * next time that product is opened.
 *
 * Guarded by Magento_Catalog::products — whoever may edit the product may
 * attach to it — plus the admin form key that Magento validates on every
 * backend POST.
 */
class Upload extends Action implements HttpPostActionInterface
{
    /**
     * @see etc/acl.xml — no bespoke resource: this is product editing.
     */
    public const ADMIN_RESOURCE = 'Magento_Catalog::products';

    public function __construct(
        Context $context,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly AttachmentRepository $attachmentRepository,
        private readonly AttachmentPath $path,
        private readonly Config $config,
        private readonly UploaderFactory $uploaderFactory,
        private readonly JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        try {
            if (!$this->config->isEnabled($this->resolveStoreId())) {
                throw new LocalizedException(__('Product attachments are disabled.'));
            }

            $productId = (int) $this->getRequest()->getParam('product_id');
            if ($productId <= 0) {
                throw new LocalizedException(__('Save the product before adding attachments.'));
            }

            $sku = (string) $this->productRepository->getById($productId)->getSku();
            $upload = $this->resolveUploadedFile();

            $uploader = $this->uploaderFactory->create(['fileId' => $upload]);
            $uploader->setAllowedExtensions($this->config->getAllowedExtensions());
            $uploader->setAllowRenameFiles(true);
            // No dispersion: a merchant browsing pub/media over SFTP should see
            // manual.pdf under the SKU, not m/a/manual.pdf.
            $uploader->setFilesDispersion(false);
            $uploader->setAllowCreateFolders(true);

            $saved = $uploader->save($this->attachmentRepository->prepareUploadDirectory($sku));
            if (!is_array($saved) || empty($saved['file'])) {
                throw new LocalizedException(__('The file could not be saved.'));
            }

            $name = $this->path->getFileName((string) $saved['file']);
            $file = $this->path->sanitizeSegment($sku) . '/' . $name;
            $mediaPath = $this->path->toMediaPath($file);

            return $result->setData([
                'name' => $name,
                'file' => $file,
                'url' => $this->attachmentRepository->getUrl($mediaPath),
                'size' => (int) ($saved['size'] ?? 0),
                'type' => $this->attachmentRepository->getMimeType($mediaPath),
            ]);
        } catch (\Throwable $e) {
            // The uploader component reads `error` off the JSON body and shows
            // it against the file name, so a rejection has to come back 200.
            return $result->setData(['error' => $e->getMessage(), 'errorcode' => $e->getCode()]);
        }
    }

    /**
     * The one uploaded file, as a flat $_FILES entry.
     *
     * Handed to the uploader as an ARRAY rather than as a `$_FILES` key,
     * because the key form cannot express what this component posts. With
     * `isMultipleFiles` on, the field's input is named `<field>[]`, so PHP
     * pivots the entry into one list per attribute: `tmp_name` is a list of
     * paths, not a path. `Magento\Framework\File\Uploader::__construct` then
     * calls `file_exists()` on it and dies with
     * "file_exists(): Argument #1 ($filename) must be of type string, array
     * given". Flattening to the first file is correct rather than lossy: the
     * component uploads sequentially, one file per request.
     *
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     * @throws LocalizedException
     */
    private function resolveUploadedFile(): array
    {
        $request = $this->getRequest();
        $files = $request instanceof Http ? $request->getFiles()->toArray() : [];
        if ($files === []) {
            throw new LocalizedException(__('No file was uploaded.'));
        }

        // param_name is what the component says it posted under; it does not
        // always agree with the input's real name, so it is a hint, not a key.
        $hint = (string) $request->getParam('param_name', '');
        $key = $hint !== '' && isset($files[$hint]) ? $hint : (string) array_key_first($files);
        $file = $this->flattenToFirstFile(is_array($files[$key] ?? null) ? $files[$key] : []);

        if ((string) $file['tmp_name'] === '') {
            throw new LocalizedException(__('No file was uploaded.'));
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            // Chiefly UPLOAD_ERR_INI_SIZE: PHP discards the body before Magento
            // sees it, and without this the failure reads as an empty upload.
            throw new LocalizedException(
                __('"%1" was not received by the server (upload error %2).', $file['name'], $file['error'])
            );
        }

        // The component checks the size limit in the browser; this is the half
        // a crafted POST cannot skip.
        $maxSize = $this->config->getMaxFileSize();
        if ($file['size'] > $maxSize) {
            throw new LocalizedException(
                __('"%1" is larger than the %2 byte limit.', $file['name'], $maxSize)
            );
        }

        return $file;
    }

    /**
     * Collapse a possibly-pivoted $_FILES entry down to its first file.
     *
     * @param array<string, mixed> $file
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    private function flattenToFirstFile(array $file): array
    {
        $flat = [];

        foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $attribute) {
            $value = $file[$attribute] ?? null;
            // `<field>[]` gives one level, `<field>[0][file]` gives more.
            while (is_array($value)) {
                $value = $value === [] ? null : reset($value);
            }
            $flat[$attribute] = $value;
        }

        return [
            'name' => (string) $flat['name'],
            'type' => (string) $flat['type'],
            'tmp_name' => (string) $flat['tmp_name'],
            'error' => (int) ($flat['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) $flat['size'],
        ];
    }

    /**
     * Store the product form was opened in, so the feature flag is read in the
     * same scope the merchant set it in.
     */
    private function resolveStoreId(): int
    {
        return (int) $this->getRequest()->getParam('store', 0);
    }
}
