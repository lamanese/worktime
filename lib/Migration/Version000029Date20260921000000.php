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
 * Dienstplan: Vorlagen mit festen Wochentagen (0.20.0).
 * - zw_duty_job_templates.weekdays: Bitmaske (Bit 0 = Montag ... Bit 6 =
 *   Sonntag), 0 = keine festen Tage (Vorlage wie bisher beliebig oft).
 * - zw_duty_job_templates.allow_other_days: 1 = Vorlage mit festen Tagen darf
 *   auch an anderen Tagen eingeplant werden (zaehlt dort nicht), 0 = nur an
 *   den festen Tagen.
 * - zw_duty_jobs.template_id: Herkunft der Karte, damit der Plan weiss, an
 *   welchen Tagen eine Vorlage schon verteilt ist. Kein Fremdschluessel.
 * - zw_duty_tpl_week_skips: «Diese Woche ignorieren» pro Vorlage und Woche.
 * Kein Backfill, kein Downgrade.
 */
class Version000029Date20260921000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('zw_duty_job_templates')) {
            $templates = $schema->getTable('zw_duty_job_templates');
            if (!$templates->hasColumn('weekdays')) {
                $templates->addColumn('weekdays', Types::SMALLINT, [
                    'notnull' => true,
                    'default' => 0,
                ]);
            }
            if (!$templates->hasColumn('allow_other_days')) {
                $templates->addColumn('allow_other_days', Types::SMALLINT, [
                    'notnull' => true,
                    'default' => 0,
                ]);
            }
        }

        if ($schema->hasTable('zw_duty_jobs')) {
            $jobs = $schema->getTable('zw_duty_jobs');
            if (!$jobs->hasColumn('template_id')) {
                $jobs->addColumn('template_id', Types::INTEGER, ['notnull' => false]);
                $jobs->addIndex(['template_id'], 'zw_dj_template_idx');
            }
        }

        if (!$schema->hasTable('zw_duty_tpl_week_skips')) {
            $table = $schema->createTable('zw_duty_tpl_week_skips');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('template_id', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('week_start', Types::DATE, ['notnull' => true]);
            $table->addColumn('skipped_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('skipped_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['template_id', 'week_start'], 'zw_dtws_tpl_week_uniq');
        }

        return $schema;
    }
}
