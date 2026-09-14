<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Dienstplan (Wochenplan, 0.19.0):
 * - zw_duty_jobs: eine Auftragskarte pro Mitarbeiter und Tag
 * - zw_employees.in_duty_roster: 1 = Mitarbeiter erscheint als Zeile im Plan
 * Kein Backfill, kein Downgrade.
 */
class Version000025Date20260914000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('zw_duty_jobs')) {
            $table = $schema->createTable('zw_duty_jobs');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('employee_id', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('job_date', Types::DATE, ['notnull' => true]);
            $table->addColumn('start_time', Types::STRING, ['notnull' => false, 'length' => 5, 'default' => null]);
            $table->addColumn('duration_minutes', Types::INTEGER, ['notnull' => false, 'default' => null]);
            $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 200]);
            $table->addColumn('note', Types::STRING, ['notnull' => false, 'length' => 500, 'default' => null]);
            $table->addColumn('on_call', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['employee_id', 'job_date'], 'zw_dj_emp_date_idx');
            $table->addIndex(['job_date'], 'zw_dj_date_idx');
        }

        if ($schema->hasTable('zw_employees')) {
            $employees = $schema->getTable('zw_employees');
            if (!$employees->hasColumn('in_duty_roster')) {
                $employees->addColumn('in_duty_roster', Types::SMALLINT, [
                    'notnull' => true,
                    'default' => 0,
                ]);
            }
        }

        return $schema;
    }
}
