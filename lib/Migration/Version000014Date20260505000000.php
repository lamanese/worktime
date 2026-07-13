<?php

/**
 * SPDX-FileCopyrightText: 2026 Axel Deffner <axel@cpcmomentum.com>
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
 * Add lock and cancellation support to zw_yearly_carryover for compliance hardening.
 *
 * New columns:
 * - locked_at: timestamp when carryover was locked (immutable after lock)
 * - cancelled_at: timestamp when carryover was cancelled (soft-delete for audit trail)
 * - cancelled_by: user who cancelled
 * - replaces_id: FK to the cancelled carryover this entry replaces
 *
 * The existing unique index (employee_id, year) is dropped and replaced with a
 * partial-style approach: business logic ensures only one active (non-cancelled) record
 * per employee+year.
 */
class Version000014Date20260505000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('zw_yearly_carryover');

		if (!$table->hasColumn('locked_at')) {
			$table->addColumn('locked_at', Types::DATETIME, [
				'notnull' => false,
			]);
		}

		if (!$table->hasColumn('cancelled_at')) {
			$table->addColumn('cancelled_at', Types::DATETIME, [
				'notnull' => false,
			]);
		}

		if (!$table->hasColumn('cancelled_by')) {
			$table->addColumn('cancelled_by', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
		}

		if (!$table->hasColumn('replaces_id')) {
			$table->addColumn('replaces_id', Types::BIGINT, [
				'notnull' => false,
				'length' => 8,
			]);
		}

		// Drop old unique index and replace with non-unique index
		// Business logic enforces one active record per employee+year
		if ($table->hasIndex('zw_yc_emp_year_idx')) {
			$table->dropIndex('zw_yc_emp_year_idx');
		}
		$table->addIndex(['employee_id', 'year'], 'zw_yc_emp_year_idx');

		return $schema;
	}
}
