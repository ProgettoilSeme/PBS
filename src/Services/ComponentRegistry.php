<?php

declare(strict_types=1);

namespace PBS\Services;

/**
 * ComponentRegistry
 *
 * Centralizza la “standardizzazione” dei campi complessi importati da ACF.
 *
 * - Un "gruppo" (field_type=array_nested) può avere `flags.group.kind`:
 *   - `generic` (gruppo generico creato manualmente)
 *   - `<kind>` (component/element ACF, es. `text`, `button`, ...)
 *
 * Se `kind` è conosciuto, PBS può mappare i sotto-campi (members) in modo coerente
 * (key normalizzate + tipi PBS standard).
 */
final class ComponentRegistry
{
    /**
     * Lista componenti standard (kinds) con i members attesi.
     *
     * @return array<string,array{
     *   label:string,
     *   members:array<string,array{label:string,field_type:string}>
     * }>
     */
    public static function components(): array
    {
        return [
            // Standard "Text": occhiello + corpo wysiwyg
            'text' => [
                'label' => 'Text',
                'members' => [
                    'boxtext' => ['label' => 'Boxtext', 'field_type' => 'text'],
                    'p' => ['label' => 'Testo', 'field_type' => 'html'],
                ],
            ],

            // Standard "Button": CTA complessa
            'button' => [
                'label' => 'Button',
                'members' => [
                    'icon' => ['label' => 'Icon', 'field_type' => 'text'],
                    'title' => ['label' => 'Titolo', 'field_type' => 'text'],
                    'link' => ['label' => 'Link', 'field_type' => 'url'],
                    'target_blank' => ['label' => 'Target blank', 'field_type' => 'bool'],
                    'hidden_text' => ['label' => 'Nascondi testo', 'field_type' => 'bool'],
                ],
            ],
        ];
    }

    public static function is_known_kind(string $kind): bool
    {
        $kind = sanitize_key($kind);
        return $kind !== '' && isset(self::components()[$kind]);
    }

    /**
     * Normalizza un kind: se vuoto -> `generic`.
     */
    public static function normalize_group_kind(string $kind): string
    {
        $kind = sanitize_key($kind);
        return $kind !== '' ? $kind : 'generic';
    }

    /**
     * Normalizza la key di un member dentro a un gruppo complesso.
     * In ambienti con clone/prefix ACF, la key può arrivare come `text_boxtext` o `button_title`.
     */
    public static function normalize_member_key(string $groupKind, string $rawKey): string
    {
        $groupKind = sanitize_key($groupKind);
        $k = sanitize_key($rawKey);
        if ($k === '') {
            return '';
        }

        if ($groupKind !== '' && str_starts_with($k, $groupKind . '_')) {
            $k = substr($k, strlen($groupKind) + 1);
            $k = sanitize_key((string) $k);
        }

        return $k;
    }

    /**
     * Inferisce il tipo PBS di un member, usando prima il registry e poi un fallback ACF->PBS.
     */
    public static function infer_member_type(string $groupKind, string $memberKey, string $acfType): string
    {
        $groupKind = sanitize_key($groupKind);
        $memberKey = sanitize_key($memberKey);
        $acfType = sanitize_key($acfType);

        $components = self::components();
        if ($groupKind !== '' && $memberKey !== '' && isset($components[$groupKind]['members'][$memberKey])) {
            return (string) ($components[$groupKind]['members'][$memberKey]['field_type'] ?? 'text');
        }

        // Fallback: mapping "leggero" ACF -> PBS types (da raffinare).
        return match ($acfType) {
            'textarea' => 'text_area',
            'url' => 'url',
            'email' => 'email',
            'wysiwyg' => 'html',
            'true_false' => 'bool',
            'repeater', 'group', 'flexible_content' => 'array_nested',
            default => 'text',
        };
    }
}
