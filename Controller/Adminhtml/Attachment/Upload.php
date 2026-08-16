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
            $productId = (int) $this->getRequest()->getParam('product_id');
            if ($productId <= 0) {
                throw new LocalizedException(__('Save the product before adding attachments.'));
            }

            $sku = (string) $this->productRepository->getById($productId)->getSku();
            $fileId = $this->resolveFileId();
            $this->assertWithinSizeLimit($fileId);

            $uploader = $this->uploaderFactory->create(['fileId' => $fileId]);
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

            $name = basename((string) $saved['file']);
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
     * Which $_FILES key holds the upload.
     *
     * Magento's file-uploader posts the field's own input name and sends
     * `param_name` alongside it, but the name differs between the jQuery and
     * the Uppy paths of that component, so the posted hint is used when it
     * resolves and the single uploaded file otherwise.
     *
     * @throws LocalizedException
     */
    private function resolveFileId(): string
    {
        $request = $this->getRequest();
        $files = $request instanceof Http ? $request->getFiles()->toArray() : [];

        $hint = (string) $request->getParam('param_name', '');
        if ($hint !== '' && isset($files[$hint])) {
            return $hint;
        }

        $keys = array_keys($files);
        if ($keys === []) {
            throw new LocalizedException(__('No file was uploaded.'));
        }

        return (string) $keys[0];
    }

    /**
     * The uploader component checks the size limit in the browser; this is the
     * half that a crafted POST cannot skip.
     *
     * @throws LocalizedException
     */
    private function assertWithinSizeLimit(string $fileId): void
    {
        $request = $this->getRequest();
        $file = $request instanceof Http ? ($request->getFiles()->toArray()[$fileId] ?? []) : [];
        $maxSize = $this->config->getMaxFileSize();

        if ((int) ($file['size'] ?? 0) > $maxSize) {
            throw new LocalizedException(
                __('"%1" is larger than the %2 byte limit.', (string) ($file['name'] ?? ''), $maxSize)
            );
        }
    }
}
