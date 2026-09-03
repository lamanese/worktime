<?php

/**
 * SPDX-FileCopyrightText: 2026 Axel Deffner <axel@cpcmomentum.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<MonthStatus>
 */
class MonthStatusMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'zw_month_status', MonthStatus::class);
    }

    public function findByEmployeeAndMonth(int $employeeId, int $year, int $month): ?MonthStatus {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('month', $qb->createNamedParameter($month, IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * @param int[] $employeeIds
     * @return array<int, MonthStatus> keyed by employee id
     */
    public function findByEmployeeIdsAndMonth(array $employeeIds, int $year, int $month): array {
        if (empty($employeeIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('employee_id', $qb->createNamedParameter($employeeIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('month', $qb->createNamedParameter($month, IQueryBuilder::PARAM_INT)));

        $result = [];
        foreach ($this->findEntities($qb) as $row) {
            $result[$row->getEmployeeId()] = $row;
        }
        return $result;
    }

    /**
     * @param int[] $employeeIds
     * @return array<int, array<int, MonthStatus>> [employeeId][month]
     */
    public function findByEmployeeIdsAndYear(array $employeeIds, int $year): array {
        if (empty($employeeIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('employee_id', $qb->createNamedParameter($employeeIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)));

        $result = [];
        foreach ($this->findEntities($qb) as $row) {
            $result[$row->getEmployeeId()][$row->getMonth()] = $row;
        }
        return $result;
    }

    /**
     * @param int[] $employeeIds
     * @return MonthStatus[] ordered by year, month
     */
    public function findByStatusForEmployeeIds(string $status, array $employeeIds): array {
        if (empty($employeeIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter($status)))
            ->andWhere($qb->expr()->in('employee_id', $qb->createNamedParameter($employeeIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->orderBy('year', 'ASC')
            ->addOrderBy('month', 'ASC');

        return $this->findEntities($qb);
    }

    public function deleteByEmployeeId(int $employeeId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('employee_id', $qb->createNamedParameter($employeeId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
