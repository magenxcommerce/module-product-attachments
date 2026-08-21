<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductAttachments\Model\Config\Source;

use Magenx\ProductAttachments\Model\AttachmentType as Type;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * The "Type" column of the Product Attachments grid.
 */
class AttachmentType implements OptionSourceInterface
{
    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Type::UPLOAD, 'label' => __('Uploaded File')],
            ['value' => Type::EXTERNAL, 'label' => __('External Link')],
        ];
    }
}
