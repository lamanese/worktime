<?php

/**
 * SPDX-FileCopyrightText: 2026 Axel Deffner <axel@cpcmomentum.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add performance indexes for report batch queries.
 */
class Version000012Date20260504000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Index on zw_time_entries (employee_id, date) for monthly/year range queries
		$timeEntries = $schema->getTable('zw_time_entries');
		if (!$timeEntries->hasIndex('zw_te_emp_date_idx')) {
			$timeEntries->addIndex(['employee_id', 'date'], 'zw_te_emp_date_idx');
		}

		// Index on zw_time_entries (status) for status filtering
		if (!$timeEntries->hasIndex('zw_te_status_idx')) {
			$timeEntries->addIndex(['status'], 'zw_te_status_idx');
		}

		// Index on zw_absences (employee_id, start_date, end_date) for range queries
		$absences = $schema->getTable('zw_absences');
		if (!$absences->hasIndex('zw_abs_emp_dates_idx')) {
			$absences->addIndex(['employee_id', 'start_date', 'end_date'], 'zw_abs_emp_dates_idx');
		}

		// Index on zw_holidays (federal_state, date) for monthly holiday lookups
		$holidays = $schema->getTable('zw_holidays');
		if (!$holidays->hasIndex('zw_hol_state_date_idx')) {
			$holidays->addIndex(['federal_state', 'date'], 'zw_hol_state_date_idx');
		}

		return $schema;
	}
}
