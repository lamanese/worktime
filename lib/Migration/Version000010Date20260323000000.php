<?php

declare(strict_types=1);

namespace OCA\WorkTime\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add working_days_per_week column to wt_employees.
 */
class Version000010Date20260323000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('wt_employees');

        if (!$table->hasColumn('working_days_per_week')) {
            $table->addColumn('working_days_per_week', Types::INTEGER, [
                'notnull' => true,
                'default' => 5,
            ]);
        }

        return $schema;
    }
}
