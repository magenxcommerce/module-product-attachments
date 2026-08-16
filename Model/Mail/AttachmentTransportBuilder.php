<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductAttachments\Model\Mail;

use Magenx\ProductAttachments\Model\Config;
use Magento\Framework\Mail\AddressConverter;
use Magento\Framework\Mail\EmailMessage;
use Magento\Framework\Mail\EmailMessageInterfaceFactory;
use Magento\Framework\Mail\MessageInterface;
use Magento\Framework\Mail\MessageInterfaceFactory;
use Magento\Framework\Mail\MimeMessageInterfaceFactory;
use Magento\Framework\Mail\MimePartInterfaceFactory;
use Magento\Framework\Mail\Template\FactoryInterface;
use Magento\Framework\Mail\Template\SenderResolverInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterfaceFactory;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Api\Data\StoreInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\Multipart\MixedPart;

/**
 * A TransportBuilder that appends product attachments to sales emails.
 *
 * Wired in through etc/di.xml as the `transportBuilder` argument of
 * Magento\Sales\Model\Order\Email\SenderBuilder — and nowhere else, so no mail
 * outside the sales emails passes through this class at all.
 *
 * WHY A SUBCLASS AND NOT A PLUGIN
 *
 * Since 2.4.7 the message is assembled in TransportBuilder::prepareMessage()
 * out of a PRIVATE `$messageData` array, and the MimeMessage it builds keeps
 * only the first TextPart it is given. There is no seam a plugin can use: the
 * public getTransport() has already produced the finished message, and the
 * parts array is unreachable. prepareMessage() itself is protected, which is
 * the one extension point left — so this class lets the parent build exactly
 * the message it always built, then adds a `multipart/mixed` wrapper around the
 * finished Symfony body. The rendered HTML, the headers, the addresses and the
 * subject are untouched, which is what keeps this compatible with SMTP modules
 * and with whatever else rewrites Magento mail.
 *
 * Attaching is best-effort. Anything that goes wrong here is logged and
 * swallowed: a missing file must never be the reason a customer's order
 * confirmation is not sent.
 */
class AttachmentTransportBuilder extends TransportBuilder
{
    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        FactoryInterface $templateFactory,
        MessageInterface $message,
        SenderResolverInterface $senderResolver,
        ObjectManagerInterface $objectManager,
        TransportInterfaceFactory $mailTransportFactory,
        private readonly SalesEmailTypeResolver $emailTypeResolver,
        private readonly OrderAttachmentProvider $attachmentProvider,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        ?MessageInterfaceFactory $messageFactory = null,
        ?EmailMessageInterfaceFactory $emailMessageInterfaceFactory = null,
        ?MimeMessageInterfaceFactory $mimeMessageInterfaceFactory = null,
        ?MimePartInterfaceFactory $mimePartInterfaceFactory = null,
        ?AddressConverter $addressConverter = null
    ) {
        parent::__construct(
            $templateFactory,
            $message,
            $senderResolver,
            $objectManager,
            $mailTransportFactory,
            $messageFactory,
            $emailMessageInterfaceFactory,
            $mimeMessageInterfaceFactory,
            $mimePartInterfaceFactory,
            $addressConverter
        );
    }

    /**
     * @inheritDoc
     */
    protected function prepareMessage()
    {
        // Read before the parent runs: getTransport() calls reset() straight
        // after prepareMessage(), and the identifier/vars/options are gone by
        // the time any caller could look at them.
        $templateIdentifier = (string) $this->templateIdentifier;
        $templateVars = is_array($this->templateVars) ? $this->templateVars : [];
        $templateOptions = is_array($this->templateOptions) ? $this->templateOptions : [];

        parent::prepareMessage();

        try {
            $this->appendAttachments($templateIdentifier, $templateVars, $templateOptions);
        } catch (\Throwable $e) {
            $this->logger->warning('[magenx_product_attachments] could not attach product files: ' . $e->getMessage());
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $templateVars
     * @param array<string, mixed> $templateOptions
     */
    private function appendAttachments(
        string $templateIdentifier,
        array $templateVars,
        array $templateOptions
    ): void {
        $order = $templateVars['order'] ?? null;
        if (!$order instanceof OrderInterface) {
            return;
        }

        $storeId = $this->resolveStoreId($templateOptions, $order);
        if (!$this->config->isEnabled($storeId)) {
            return;
        }

        $type = $this->emailTypeResolver->resolve($templateIdentifier, $storeId);
        if ($type === null || !in_array($type, $this->config->getAttachToTypes($storeId), true)) {
            return;
        }

        if (!$this->message instanceof EmailMessage) {
            return;
        }

        $parts = $this->attachmentProvider->getParts($order, $storeId);
        if ($parts === []) {
            return;
        }

        $symfonyMessage = $this->message->getSymfonyMessage();
        $body = $symfonyMessage->getBody();
        if ($body === null) {
            return;
        }

        // multipart/mixed { original body, attachment, attachment, ... } — the
        // original body keeps its own type, so an HTML template stays HTML and
        // a text one stays text.
        $symfonyMessage->setBody(new MixedPart($body, ...$parts));
    }

    /**
     * @param array<string, mixed> $templateOptions
     */
    private function resolveStoreId(array $templateOptions, OrderInterface $order): int
    {
        $store = $templateOptions['store'] ?? null;
        if ($store instanceof StoreInterface) {
            return (int) $store->getId();
        }

        if (is_numeric($store)) {
            return (int) $store;
        }

        return (int) $order->getStoreId();
    }
}
