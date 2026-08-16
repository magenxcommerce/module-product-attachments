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
use Psr\Log\LoggerInterface;

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
        private readonly JsonFactory $jsonFactory,
        private readonly LoggerInterface $logger
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
            $fileId = $this->resolveFileId();

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
     * The `$_FILES` identifier of the uploaded file, checked before it is used.
     *
     * The field's input is named after its data scope, so this component posts
     * under `product[magenx_product_attachments]`, not under a plain key. Two
     * consequences, and both bit earlier versions of this controller:
     *
     * 1. `$_FILES` holds ONE entry, `product`, whose every attribute is an
     *    array keyed by the inner name. Magento's request object then
     *    re-maps that (`Laminas\Http\PhpEnvironment\Request::mapPhpFiles()`)
     *    into a nested tree — `['product']['magenx_product_attachments']` —
     *    so the attributes are one level DEEPER than a naive read expects.
     *    Hence the walk below rather than a lookup.
     * 2. The bracketed string is exactly what
     *    `Magento\Framework\File\Uploader::_setUploadFileId()` parses, so the
     *    identifier is rebuilt from the path found and handed over as a
     *    STRING. Passing the mapped array instead would work only where PHP's
     *    `upload_tmp_dir` is one of the handful of directories
     *    `validateFileId()` allows — a host-dependent trap.
     *
     * `param_name` is deliberately not trusted as the key: the component sends
     * it, but which of its two upload paths ran decides what it holds.
     *
     * @throws LocalizedException
     */
    private function resolveFileId(): string
    {
        $request = $this->getRequest();
        $files = $request instanceof Http ? $request->getFiles()->toArray() : [];
        $found = $this->findUploadedFile($files);

        if ($found === null) {
            // Log the shape: without it, "no file" is indistinguishable from
            // "posted under a name this walk did not recognise".
            $this->logger->warning(
                '[magenx_product_attachments] upload carried no recognisable file part; keys: '
                . ($files === [] ? '(none)' : implode(', ', array_keys($files)))
            );

            throw new LocalizedException(__('No file was uploaded.'));
        }

        ['path' => $path, 'info' => $info] = $found;
        $name = (string) ($info['name'] ?? '');
        $error = (int) ($info['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            // Chiefly UPLOAD_ERR_INI_SIZE: PHP drops the body before Magento
            // sees it, leaving tmp_name empty. Reported before the emptiness is
            // noticed, so the message names the real cause.
            throw new LocalizedException(
                __('"%1" was not received by the server (upload error %2).', $name, $error)
            );
        }

        // The component checks the size limit in the browser; this is the half
        // a crafted POST cannot skip.
        $maxSize = $this->config->getMaxFileSize();
        if ((int) ($info['size'] ?? 0) > $maxSize) {
            throw new LocalizedException(__('"%1" is larger than the %2 byte limit.', $name, $maxSize));
        }

        return $this->toFileId($path);
    }

    /**
     * Depth-first search for the first file node in the mapped tree.
     *
     * A node is a file when it carries a scalar `tmp_name` — true even for a
     * failed upload, where `tmp_name` is an empty string and `error` says why.
     *
     * @param array<string|int, mixed> $node
     * @param string[] $path
     * @return array{path: string[], info: array<string, mixed>}|null
     */
    private function findUploadedFile(array $node, array $path = []): ?array
    {
        if (array_key_exists('tmp_name', $node) && !is_array($node['tmp_name'])) {
            return ['path' => $path, 'info' => $node];
        }

        foreach ($node as $key => $child) {
            if (!is_array($child)) {
                continue;
            }

            $found = $this->findUploadedFile($child, array_merge($path, [(string) $key]));
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Rebuild the posted field name: ['product', 'attachments'] becomes
     * `product[attachments]`, a single-segment path stays as it is.
     *
     * @param string[] $path
     */
    private function toFileId(array $path): string
    {
        if ($path === []) {
            throw new LocalizedException(__('No file was uploaded.'));
        }

        $first = (string) array_shift($path);

        return $path === [] ? $first : $first . '[' . implode('][', $path) . ']';
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
