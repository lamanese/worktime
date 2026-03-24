<?php

declare(strict_types=1);

namespace OCA\WorkTime\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create wt_work_schedules table and migrate existing employee data.
 */
class Version000009Date20260318000000 extends SimpleMigrationStep {

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('wt_work_schedules')) {
            $table = $schema->createTable('wt_work_schedules');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('employee_id', Types::INTEGER, [
                'notnull' => true,
            ]);
            $table->addColumn('valid_from', Types::DATE, [
                'notnull' => true,
            ]);
            $table->addColumn('mon_hours', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 4,
                'scale' => 2,
                'default' => 8.00,
            ]);
            $table->addColumn('tue_hours', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 4,
                'scale' => 2,
                'default' => 8.00,
            ]);
            $table->addColumn('wed_hours', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 4,
                'scale' => 2,
                'default' => 8.00,
            ]);
            $table->addColumn('thu_hours', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 4,
                'scale' => 2,
                'default' => 8.00,
            ]);
            $table->addColumn('fri_hours', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 4,
                'scale' => 2,
                'default' => 8.00,
            ]);
            $table->addColumn('sat_hours', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 4,
                'scale' => 2,
                'default' => 0.00,
            ]);
            $table->addColumn('sun_hours', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 4,
                'scale' => 2,
                'default' => 0.00,
            ]);
            $table->addColumn('vacation_days', Types::INTEGER, [
                'notnull' => true,
                'default' => 30,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['employee_id', 'valid_from'], 'wt_ws_emp_valid_idx');
            $table->addIndex(['employee_id'], 'wt_ws_emp_idx');
        }

        return $schema;
    }

    /**
     * Migrate existing employee data into work schedules.
     * For each employee, create one schedule with valid_from = entry_date (or 2020-01-01).
     * Daily hours = weekly_hours / 5 for Mon-Fri, 0 for Sat/Sun.
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'weekly_hours', 'vacation_days', 'entry_date')
            ->from('wt_employees');

        $result = $qb->executeQuery();
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        while ($row = $result->fetch()) {
            $weeklyHours = (float)$row['weekly_hours'];
            $dailyHours = round($weeklyHours / 5, 2);
            $validFrom = $row['entry_date'] ?? '2020-01-01';

            $insert = $this->db->getQueryBuilder();
            $insert->insert('wt_work_schedules')
                ->values([
                    'employee_id' => $insert->createNamedParameter((int)$row['id'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                    'valid_from' => $insert->createNamedParameter($validFrom),
                    'mon_hours' => $insert->createNamedParameter(number_format($dailyHours, 2, '.', '')),
                    'tue_hours' => $insert->createNamedParameter(number_format($dailyHours, 2, '.', '')),
                    'wed_hours' => $insert->createNamedParameter(number_format($dailyHours, 2, '.', '')),
                    'thu_hours' => $insert->createNamedParameter(number_format($dailyHours, 2, '.', '')),
                    'fri_hours' => $insert->createNamedParameter(number_format($dailyHours, 2, '.', '')),
                    'sat_hours' => $insert->createNamedParameter('0.00'),
                    'sun_hours' => $insert->createNamedParameter('0.00'),
                    'vacation_days' => $insert->createNamedParameter((int)$row['vacation_days'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                    'created_at' => $insert->createNamedParameter($now),
                    'updated_at' => $insert->createNamedParameter($now),
                ]);
            $insert->executeStatement();
        }

        $result->closeCursor();
        $output->info('Migrated existing employees to work schedules');
    }
}
