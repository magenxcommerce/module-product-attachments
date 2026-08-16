<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductAttachments\Model\Mail;

use Magenx\ProductAttachments\Model\Config\Source\EmailType;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Answers "which sales email is being sent?" from the template identifier.
 *
 * The template vars cannot answer it: an order confirmation and an order
 * comment email both carry `order` and `comment`, so keying off the vars
 * attaches the files to every order update too.
 *
 * The identifier can, because the senders read it from exactly these config
 * paths (Magento\Sales\Model\Order\Email\Container\*Identity::getTemplateId).
 * Resolving through config rather than against the hard-coded
 * `sales_email_order_template` handles is also what makes a merchant's custom
 * template — selected in Stores > Configuration > Sales Emails — resolve
 * correctly instead of silently losing its attachments.
 */
class SalesEmailTypeResolver
{
    /**
     * @var array<string, string[]>
     */
    private const TEMPLATE_PATHS = [
        EmailType::ORDER => [
            'sales_email/order/template',
            'sales_email/order/guest_template',
        ],
        EmailType::INVOICE => [
            'sales_email/invoice/template',
            'sales_email/invoice/guest_template',
        ],
        EmailType::SHIPMENT => [
            'sales_email/shipment/template',
            'sales_email/shipment/guest_template',
        ],
        EmailType::CREDITMEMO => [
            'sales_email/creditmemo/template',
            'sales_email/creditmemo/guest_template',
        ],
    ];

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    /**
     * @return string|null one of the EmailType constants, or null for every other
     *                     email — comment/update variants included.
     */
    public function resolve(string $templateIdentifier, ?int $storeId = null): ?string
    {
        $templateIdentifier = trim($templateIdentifier);
        if ($templateIdentifier === '') {
            return null;
        }

        foreach (self::TEMPLATE_PATHS as $type => $paths) {
            foreach ($paths as $path) {
                $configured = trim((string) $this->scopeConfig->getValue(
                    $path,
                    ScopeInterface::SCOPE_STORE,
                    $storeId
                ));

                if ($configured !== '' && $configured === $templateIdentifier) {
                    return $type;
                }
            }
        }

        return null;
    }
}
