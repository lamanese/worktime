<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
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
 * @template-extends QBMapper<DutyJob>
 */
class DutyJobMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'zw_duty_jobs', DutyJob::class);
    }

    /**
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function find(int $id): DutyJob {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * All cards between two dates (inclusive), any employee.
     *
     * @return DutyJob[]
     */
    public function findByDateRange(DateTime $from, DateTime $to): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->gte('job_date', $qb->createNamedParameter($from, IQueryBuilder::PARAM_DATE)))
            ->andWhere($qb->expr()->lte('job_date', $qb->createNamedParameter($to, IQueryBuilder::PARAM_DATE)))
            ->orderBy('job_date', 'ASC')
            ->addOrderBy('start_time', 'ASC')
            ->addOrderBy('title', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Distinct titles starting with the prefix (case-insensitive), for suggestions.
     *
     * @return string[]
     */
    public function findDistinctTitles(string $prefix, int $limit = 10): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('title')
            ->from($this->getTableName())
            ->where($qb->expr()->like(
                $qb->func()->lower('title'),
                $qb->createNamedParameter($this->db->escapeLikeParameter(mb_strtolower($prefix)) . '%')
            ))
            ->orderBy('title', 'ASC')
            ->setMaxResults($limit);

        $result = $qb->executeQuery();
        $titles = [];
        while ($row = $result->fetch()) {
            $titles[] = (string)$row['title'];
        }
        $result->closeCursor();

        return $titles;
    }
}
