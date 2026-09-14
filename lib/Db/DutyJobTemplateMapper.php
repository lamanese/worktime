<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<DutyJobTemplate>
 */
class DutyJobTemplateMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'zw_duty_job_templates', DutyJobTemplate::class);
    }

    /**
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function find(int $id): DutyJobTemplate {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * All templates, visible and hidden, for the settings table.
     *
     * @return DutyJobTemplate[]
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('title', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Only the templates shown in the week-view sidebar. `sort_order` exists in
     * the schema but is reserved (spec §10.1): ordering is by title for now.
     *
     * @return DutyJobTemplate[]
     */
    public function findVisible(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('is_visible', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->orderBy('title', 'ASC');

        return $this->findEntities($qb);
    }
}
