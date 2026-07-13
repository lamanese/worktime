<?php

/**
 * SPDX-FileCopyrightText: 2026 Axel Deffner <axel@cpcmomentum.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<AuditLog>
 */
class AuditLogMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'zw_audit_logs', AuditLog::class);
    }

    /**
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function find(int $id): AuditLog {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * @return AuditLog[]
     */
    public function findByUser(string $userId, int $limit = 100): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    /**
     * @return AuditLog[]
     */
    public function findByEntity(string $entityType, int $entityId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)))
            ->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * @return AuditLog[]
     */
    public function findByDateRange(DateTime $startDate, DateTime $endDate, int $limit = 1000): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->gte('created_at', $qb->createNamedParameter($startDate, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
            ->andWhere($qb->expr()->lte('created_at', $qb->createNamedParameter($endDate, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    /**
     * @return AuditLog[]
     */
    public function findByAction(string $action, int $limit = 100): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('action', $qb->createNamedParameter($action)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    /**
     * @return AuditLog[]
     */
    public function findRecent(int $limit = 50): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    /**
     * @return AuditLog[]
     */
    public function findFiltered(
        ?string $action = null,
        ?string $entityType = null,
        ?DateTime $from = null,
        ?DateTime $to = null,
        int $limit = 500,
        int $offset = 0,
        ?string $userId = null
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($action !== null) {
            $qb->andWhere($qb->expr()->eq('action', $qb->createNamedParameter($action)));
        }
        if ($entityType !== null) {
            $qb->andWhere($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)));
        }
        if ($from !== null) {
            $qb->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($from, IQueryBuilder::PARAM_DATETIME_MUTABLE)));
        }
        if ($to !== null) {
            $to = clone $to;
            $to->setTime(23, 59, 59);
            $qb->andWhere($qb->expr()->lte('created_at', $qb->createNamedParameter($to, IQueryBuilder::PARAM_DATETIME_MUTABLE)));
        }
        if ($userId !== null) {
            $qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        }

        return $this->findEntities($qb);
    }

    public function deleteOlderThan(DateTime $date): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->lt('created_at', $qb->createNamedParameter($date, IQueryBuilder::PARAM_DATETIME_MUTABLE)));

        return $qb->executeStatement();
    }
}
