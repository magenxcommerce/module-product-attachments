<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductAttachments\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * The sales emails that can carry product attachments.
 *
 * Deliberately the four entity emails only. The comment/update variants
 * (`sales_email/order_comment/*` and friends) are excluded: they are sent
 * repeatedly over an order's life, and re-attaching the same manuals to each
 * one is how a shop ends up on a spam list.
 */
class EmailType implements OptionSourceInterface
{
    public const ORDER = 'order';
    public const INVOICE = 'invoice';
    public const SHIPMENT = 'shipment';
    public const CREDITMEMO = 'creditmemo';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return [self::ORDER, self::INVOICE, self::SHIPMENT, self::CREDITMEMO];
    }

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::ORDER, 'label' => __('Order Confirmation')],
            ['value' => self::INVOICE, 'label' => __('Invoice')],
            ['value' => self::SHIPMENT, 'label' => __('Shipment')],
            ['value' => self::CREDITMEMO, 'label' => __('Credit Memo')],
        ];
    }
}
