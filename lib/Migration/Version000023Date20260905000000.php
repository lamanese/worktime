<?php

/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Zeitwerk\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Regionscodes nach ISO 3166-2 (0.18.0): federal_state traegt kuenftig
 * laenderpraefigierte Codes (DE-BY, CH-ZH), weil Schweizer Kantone dazukommen
 * und Zweibuchstaben-Codes kollidieren (BE, SH, NW). Beide Spalten werden von
 * 2 auf 8 Zeichen verbreitert, Bestandswerte XX -> DE-XX (auch die
 * Firmeneinstellung default_federal_state). Idempotent: Werte mit Bindestrich
 * werden nie angefasst. Kein Downgrade auf 0.17.x nach dieser Migration.
 * Unbekannte Codes (nicht in LEGACY_CODES) werden nicht umgeschrieben, ihre
 * Anzahl wird als Warnung gemeldet.
 */
class Version000023Date20260905000000 extends SimpleMigrationStep {

    private const TABLES = ['zw_employees', 'zw_holidays'];

    private const LEGACY_CODES = ['BW', 'BY', 'BE', 'BB', 'HB', 'HH', 'HE', 'MV', 'NI', 'NW', 'RP', 'SL', 'SN', 'ST', 'SH', 'TH'];

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        foreach (self::TABLES as $tableName) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }
            $table = $schema->getTable($tableName);
            if (!$table->hasColumn('federal_state')) {
                continue;
            }
            $column = $table->getColumn('federal_state');
            if ((int)$column->getLength() < 8) {
                $column->setLength(8);
            }
            if ($tableName === 'zw_employees') {
                $column->setDefault('DE-BY');
            }
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $this->db->beginTransaction();
        try {
            $changed = 0;

            foreach (self::TABLES as $tableName) {
                foreach (self::LEGACY_CODES as $code) {
                    $qb = $this->db->getQueryBuilder();
                    $qb->update($tableName)
                        ->set('federal_state', $qb->createNamedParameter('DE-' . $code))
                        ->where($qb->expr()->eq('federal_state', $qb->createNamedParameter($code)));
                    $changed += $qb->executeStatement();
                }
            }

            foreach (self::LEGACY_CODES as $code) {
                $qb = $this->db->getQueryBuilder();
                $qb->update('zw_company_settings')
                    ->set('setting_value', $qb->createNamedParameter('DE-' . $code))
                    ->where($qb->expr()->eq('setting_key', $qb->createNamedParameter('default_federal_state')))
                    ->andWhere($qb->expr()->eq('setting_value', $qb->createNamedParameter($code)));
                $changed += $qb->executeStatement();
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        foreach (self::TABLES as $tableName) {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('id'))
                ->from($tableName)
                ->where($qb->expr()->notLike('federal_state', $qb->createNamedParameter('%-%')));
            $result = $qb->executeQuery();
            $remaining = (int)$result->fetchOne();
            $result->closeCursor();
            if ($remaining > 0) {
                $output->warning("$tableName: $remaining rows keep an unknown region code without country prefix (not one of the 16 German states) – review them in the holiday settings, see FEIERTAGE.md");
            }
        }

        $output->info("Region codes migrated to ISO 3166-2 (DE-XX): $changed rows updated");
    }
}
