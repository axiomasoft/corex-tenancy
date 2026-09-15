<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Database;

use const JSON_THROW_ON_ERROR;
use const SORT_STRING;

use Illuminate\Database\Connection;

use function array_map;
use function array_values;
use function hash;
use function json_encode;
use function ksort;

/**
 * Stable, read-only projection of the supported PostgreSQL schema surface.
 *
 * Physical database identity, ACLs, OIDs, table statistics and sequence
 * current values deliberately never enter the projection.
 */
final class SchemaCatalogueSerializer
{
    /** @return array<string, mixed> */
    public function catalogue(Connection $connection): array
    {
        $relations = $connection->select(<<<'SQL'
            SELECT n.nspname AS schema_name, c.relname AS table_name,
                   c.relpersistence AS persistence, c.relreplident AS replica_identity
            FROM pg_class c
            INNER JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relkind = 'r'
              AND n.nspname NOT IN ('pg_catalog', 'information_schema')
              AND n.nspname NOT LIKE 'pg_toast%'
            ORDER BY n.nspname COLLATE "C", c.relname COLLATE "C"
            SQL);

        $schemas = [];
        foreach ($relations as $relation) {
            $schema = (string) $relation->schema_name;
            $table = (string) $relation->table_name;
            $schemas[$schema]['name'] = $schema;
            $schemas[$schema]['tables'][] = [
                'name' => $table,
                'persistence' => (string) $relation->persistence,
                'replica_identity' => (string) $relation->replica_identity,
                'columns' => $this->columns($connection, schema: $schema, table: $table),
                'constraints' => $this->constraints($connection, schema: $schema, table: $table),
                'indexes' => $this->indexes($connection, schema: $schema, table: $table),
            ];
        }

        $extensions = array_map(static fn (object $extension): array => [
            'name' => (string) $extension->name,
            'schema' => (string) $extension->schema_name,
            'version' => (string) $extension->version,
        ], $connection->select(<<<'SQL'
            SELECT e.extname AS name, n.nspname AS schema_name, e.extversion AS version
            FROM pg_extension e
            INNER JOIN pg_namespace n ON n.oid = e.extnamespace
            ORDER BY e.extname COLLATE "C"
            SQL));

        return [
            'format_version' => 1,
            'extensions' => $extensions,
            'schemas' => array_values($schemas),
        ];
    }

    public function hash(Connection $connection): string
    {
        return hash('sha256', $this->encode($this->catalogue($connection)));
    }

    /** @param array<string, mixed> $catalogue */
    public function encode(array $catalogue): string
    {
        return json_encode($this->sortRecursively($catalogue), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return list<array<string, mixed>> */
    private function columns(Connection $connection, string $schema, string $table): array
    {
        return array_map(static fn (object $column): array => [
            'name' => (string) $column->name,
            'ordinal' => (string) $column->ordinal,
            'type' => (string) $column->type_name,
            'nullable' => (bool) $column->nullable,
            'default' => $column->default_expression,
            'identity' => (string) $column->identity_kind,
            'generated' => (string) $column->generated_kind,
            'collation' => $column->collation_name,
        ], $connection->select(
            query: <<<'SQL'
            SELECT a.attname AS name, a.attnum AS ordinal, format_type(a.atttypid, a.atttypmod) AS type_name,
                   NOT a.attnotnull AS nullable, pg_get_expr(d.adbin, d.adrelid, false) AS default_expression,
                   a.attidentity AS identity_kind, a.attgenerated AS generated_kind,
                   CASE WHEN coll.oid IS NULL THEN NULL ELSE coll.collnamespace::regnamespace::text || '.' || coll.collname END AS collation_name
            FROM pg_attribute a
            INNER JOIN pg_class c ON c.oid = a.attrelid
            INNER JOIN pg_namespace n ON n.oid = c.relnamespace
            LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
            LEFT JOIN pg_collation coll ON coll.oid = a.attcollation AND a.attcollation <> 0
            WHERE n.nspname = ? AND c.relname = ? AND a.attnum > 0 AND NOT a.attisdropped
            ORDER BY a.attnum
            SQL,
            bindings: [$schema, $table],
        ));
    }

    /** @return list<array<string, mixed>> */
    private function constraints(Connection $connection, string $schema, string $table): array
    {
        return array_map(static fn (object $constraint): array => [
            'name' => (string) $constraint->name,
            'type' => (string) $constraint->type,
            'definition' => (string) $constraint->definition,
            'validated' => (bool) $constraint->validated,
            'deferrable' => (bool) $constraint->deferrable,
            'initially_deferred' => (bool) $constraint->initially_deferred,
        ], $connection->select(
            query: <<<'SQL'
            SELECT con.conname AS name, con.contype AS type, pg_get_constraintdef(con.oid, false) AS definition,
                   con.convalidated AS validated, con.condeferrable AS deferrable, con.condeferred AS initially_deferred
            FROM pg_constraint con
            INNER JOIN pg_class c ON c.oid = con.conrelid
            INNER JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = ? AND c.relname = ?
            ORDER BY con.conname COLLATE "C"
            SQL,
            bindings: [$schema, $table],
        ));
    }

    /** @return list<array<string, mixed>> */
    private function indexes(Connection $connection, string $schema, string $table): array
    {
        return array_map(static fn (object $index): array => [
            'name' => (string) $index->name,
            'definition' => (string) $index->definition,
            'valid' => (bool) $index->valid,
            'ready' => (bool) $index->ready,
        ], $connection->select(
            query: <<<'SQL'
            SELECT i.relname AS name, pg_get_indexdef(i.oid, 0, false) AS definition,
                   idx.indisvalid AS valid, idx.indisready AS ready
            FROM pg_index idx
            INNER JOIN pg_class c ON c.oid = idx.indrelid
            INNER JOIN pg_namespace n ON n.oid = c.relnamespace
            INNER JOIN pg_class i ON i.oid = idx.indexrelid
            WHERE n.nspname = ? AND c.relname = ?
            ORDER BY i.relname COLLATE "C"
            SQL,
            bindings: [$schema, $table],
        ));
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
    }
}
