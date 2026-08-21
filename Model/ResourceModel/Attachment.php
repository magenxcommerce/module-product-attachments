<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductAttachments\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Thin CRUD over `magenx_product_attachment` — plain `ResourceConnection`
 * queries, no `AbstractModel`/`AbstractCollection`. The module already keeps
 * everything else (uploads, mailing) free of that machinery; a single small
 * table doesn't need an entity manager, a factory, and a collection class to
 * do `SELECT ... WHERE product_id = ?`.
 *
 * A row is a plain associative array shaped like the table:
 * `attachment_id, product_id, type, file, external_url, title, mime_type,
 * size, sort_order, created_at`.
 */
class Attachment
{
    private const TABLE = 'magenx_product_attachment';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * Every row of one product, ordered for display.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getByProductId(int $productId): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE))
            ->where('product_id = ?', $productId)
            ->order('sort_order ASC')
            ->order('attachment_id ASC');

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[(int) $row['attachment_id']] = $row;
        }

        return $rows;
    }

    /**
     * One row, or null if it does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function getById(int $attachmentId): ?array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE))
            ->where('attachment_id = ?', $attachmentId);

        $row = $connection->fetchRow($select);

        return $row === false ? null : $row;
    }

    /**
     * Insert a new row, return its id.
     *
     * @param array<string, mixed> $row
     */
    public function insert(array $row): int
    {
        $connection = $this->resource->getConnection();
        $connection->insert($this->resource->getTableName(self::TABLE), $row);

        return (int) $connection->lastInsertId($this->resource->getTableName(self::TABLE));
    }

    /**
     * Update an existing row by id.
     *
     * @param array<string, mixed> $row
     */
    public function update(int $attachmentId, array $row): void
    {
        $connection = $this->resource->getConnection();
        $connection->update(
            $this->resource->getTableName(self::TABLE),
            $row,
            $connection->quoteInto('attachment_id = ?', $attachmentId)
        );
    }

    /**
     * Delete one row. Returns false if it did not exist.
     */
    public function delete(int $attachmentId): bool
    {
        $connection = $this->resource->getConnection();

        return (bool) $connection->delete(
            $this->resource->getTableName(self::TABLE),
            $connection->quoteInto('attachment_id = ?', $attachmentId)
        );
    }
}
