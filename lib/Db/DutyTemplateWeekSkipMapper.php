<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Db;

use DateTime;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<DutyTemplateWeekSkip>
 */
class DutyTemplateWeekSkipMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'zw_duty_tpl_week_skips', DutyTemplateWeekSkip::class);
    }

    /**
     * All skips of the week starting on $monday (callers normalize to Monday).
     *
     * @return DutyTemplateWeekSkip[]
     */
    public function findByWeekStart(DateTime $monday): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('week_start', $qb->createNamedParameter($monday, IQueryBuilder::PARAM_DATE)));

        return $this->findEntities($qb);
    }

    public function deleteByTemplate(int $templateId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('template_id', $qb->createNamedParameter($templateId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
