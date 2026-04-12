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
 * Add absence_detail column and change absence_visibility default to 'none' (opt-in).
 */
class Version000011Date20260412000000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('wt_employees');

		if (!$table->hasColumn('absence_detail')) {
			$table->addColumn('absence_detail', Types::STRING, [
				'notnull' => true,
				'length' => 16,
				'default' => 'hidden',
			]);
		}

		// Change default for absence_visibility to 'none'
		$col = $table->getColumn('absence_visibility');
		$col->setDefault('none');

		return $schema;
	}

	/**
	 * Set existing employees to 'none' visibility (opt-in).
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('wt_employees')
			->set('absence_visibility', $qb->createNamedParameter('none'))
			->set('absence_detail', $qb->createNamedParameter('hidden'));
		$qb->executeStatement();

		$output->info('Reset all employees to opt-in defaults (visibility=none, detail=hidden)');
	}
}
