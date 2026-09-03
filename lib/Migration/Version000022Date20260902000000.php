<?php

/**
 * SPDX-FileCopyrightText: 2026 Axel Deffner <axel@cpcmomentum.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Migration;

use Closure;
use DateTime;
use OCA\Zeitwerk\Db\MonthStatus;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Monatsstatus als eigener Datensatz (zw_month_status), damit Monate ohne
 * Zeiteintraege (nur Abwesenheiten) eingereicht und genehmigt werden koennen.
 * Backfill: fuer jeden Mitarbeiter-Monat mit Zeiteintraegen wird der bisherige,
 * aus den Eintraegen abgeleitete Status uebernommen (Entwurf-Monate ohne Zeile).
 */
class Version000022Date20260902000000 extends SimpleMigrationStep {

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('zw_month_status')) {
            $table = $schema->createTable('zw_month_status');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('employee_id', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('year', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('month', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => MonthStatus::STATUS_DRAFT]);
            $table->addColumn('submitted_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('submitted_by', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('approved_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('approved_by', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['employee_id', 'year', 'month'], 'zw_ms_emp_ym_idx');
            $table->addIndex(['status'], 'zw_ms_status_idx');
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        // Idempotent: skip when rows already exist (re-run after a partial upgrade).
        $check = $this->db->getQueryBuilder();
        $check->select($check->func()->count('id'))->from('zw_month_status');
        $result = $check->executeQuery();
        $existing = (int)$result->fetchOne();
        $result->closeCursor();
        if ($existing > 0) {
            $output->info('zw_month_status already populated, backfill skipped');
            return;
        }

        // Aggregation-plus-insert runs in one transaction so a failure halfway through
        // does not leave a partial backfill that the idempotency check above would
        // then silently treat as "already done" on the next run.
        $this->db->beginTransaction();
        try {
            // Aggregate per employee/month in PHP (portable: no YEAR()/MONTH() SQL functions).
            $qb = $this->db->getQueryBuilder();
            $qb->select('employee_id', 'date', 'status', 'submitted_at', 'submitted_by', 'approved_at', 'approved_by')
                ->from('zw_time_entries');
            $result = $qb->executeQuery();

            $months = [];
            while ($row = $result->fetch()) {
                $date = new DateTime((string)$row['date']);
                $key = $row['employee_id'] . '-' . $date->format('Y') . '-' . $date->format('n');
                if (!isset($months[$key])) {
                    $months[$key] = [
                        'employeeId' => (int)$row['employee_id'],
                        'year' => (int)$date->format('Y'),
                        'month' => (int)$date->format('n'),
                        'summary' => ['draft' => 0, 'submitted' => 0, 'approved' => 0, 'rejected' => 0],
                        'submittedAt' => null, 'submittedBy' => null,
                        'approvedAt' => null, 'approvedBy' => null,
                    ];
                }
                $status = (string)$row['status'];
                if (isset($months[$key]['summary'][$status])) {
                    $months[$key]['summary'][$status]++;
                }
                // earliest submission, latest approval (same rule as the old inbox/reopen lists)
                if (!empty($row['submitted_at']) && ($months[$key]['submittedAt'] === null || $row['submitted_at'] < $months[$key]['submittedAt'])) {
                    $months[$key]['submittedAt'] = (string)$row['submitted_at'];
                    $months[$key]['submittedBy'] = $row['submitted_by'] !== null ? (int)$row['submitted_by'] : null;
                }
                if (!empty($row['approved_at']) && $status === MonthStatus::STATUS_APPROVED
                    && ($months[$key]['approvedAt'] === null || $row['approved_at'] > $months[$key]['approvedAt'])) {
                    $months[$key]['approvedAt'] = (string)$row['approved_at'];
                    $months[$key]['approvedBy'] = $row['approved_by'] !== null ? (int)$row['approved_by'] : null;
                }
            }
            $result->closeCursor();

            $now = new DateTime();
            $inserted = 0;
            foreach ($months as $m) {
                $status = MonthStatus::deriveFromSummary($m['summary']);
                if ($status === MonthStatus::STATUS_DRAFT) {
                    continue;
                }
                $insert = $this->db->getQueryBuilder();
                $insert->insert('zw_month_status')
                    ->values([
                        'employee_id' => $insert->createNamedParameter($m['employeeId'], IQueryBuilder::PARAM_INT),
                        'year' => $insert->createNamedParameter($m['year'], IQueryBuilder::PARAM_INT),
                        'month' => $insert->createNamedParameter($m['month'], IQueryBuilder::PARAM_INT),
                        'status' => $insert->createNamedParameter($status),
                        'submitted_at' => $insert->createNamedParameter($m['submittedAt'] !== null ? new DateTime($m['submittedAt']) : null, IQueryBuilder::PARAM_DATETIME_MUTABLE),
                        'submitted_by' => $insert->createNamedParameter($m['submittedBy'], IQueryBuilder::PARAM_INT),
                        'approved_at' => $insert->createNamedParameter($status === MonthStatus::STATUS_APPROVED && $m['approvedAt'] !== null ? new DateTime($m['approvedAt']) : null, IQueryBuilder::PARAM_DATETIME_MUTABLE),
                        'approved_by' => $insert->createNamedParameter($status === MonthStatus::STATUS_APPROVED ? $m['approvedBy'] : null, IQueryBuilder::PARAM_INT),
                        'created_at' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE),
                        'updated_at' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE),
                    ]);
                $insert->executeStatement();
                $inserted++;
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $output->info("Backfilled $inserted month status rows from time entries");
    }
}
