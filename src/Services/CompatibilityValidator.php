<?php

declare(strict_types=1);

namespace PBS\Services;

final class CompatibilityValidator
{
    /**
     * @param array<int,array<string,mixed>> $fields rows from DB (pbs_schema_fields)
     * @return array{errors:array<int,string>,warnings:array<int,string>}
     */
    public function validate_fields(array $fields): array
    {
        $errors = [];
        $warnings = [];

        $dbCols = [];
        $inp = [];

        foreach ($fields as $f) {
            $db = (string) ($f['db_column'] ?? '');
            $in = (string) ($f['input_name'] ?? '');
            $type = (string) ($f['field_type'] ?? 'text');
            $label = (string) ($f['label'] ?? $db);

            if ($db === '' || $in === '') {
                $errors[] = "Campo incompleto (db_column/input_name vuoti): {$label}";
                continue;
            }

            if (isset($dbCols[$db])) {
                $errors[] = "Collisione db_column: {$db}";
            }
            $dbCols[$db] = true;

            if (isset($inp[$in])) {
                $errors[] = "Collisione input_name: {$in}";
            }
            $inp[$in] = true;

            if (!in_array($type, ['text', 'text_area', 'email', 'url', 'html', 'array_nested'], true)) {
                $warnings[] = "Tipo non standard per {$db} ({$type}) — verrà trattato come 'text' nel generatore leggero.";
            }
        }

        if (!$fields) {
            $errors[] = 'Nessun campo nello schema.';
        }

        return [
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}

