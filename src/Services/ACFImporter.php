<?php

declare(strict_types=1);

namespace PBS\Services;

final class ACFImporter
{
    /**
     * Sample-post values map used to filter the imported schema to what ACF actually exposes for that post.
     *
     * @var array<string,mixed>|null
     */
    private ?array $sampleValues = null;
    private bool $onlyElements = false;
    private bool $groupElements = false;

    /**
     * Collected element definitions when $groupElements is enabled.
     *
     * @var array<string,array{label:string,kind:string,members:array<int,array<string,mixed>>}>
     */
    private array $elementGroups = [];

    /**
     * Best-effort layout labels for element layouts (e.g. 'text' => 'Titolo e testo').
     *
     * @var array<string,string>
     */
    private array $elementLayoutLabels = [];

    public function is_available(): bool
    {
        return function_exists('acf_get_field_groups') && function_exists('acf_get_fields');
    }

    /**
     * Import a normalized schema from ACF field groups attached to a post type.
     *
     * Output format:
     * - groups: array<int,array{id:mixed,title?:string,key?:string}>
     * - fields: array<int,array{db_column:string,input_name:string,label:string,field_type:string,flags:array}>
     */
    /**
     * @param array<string,mixed> $options
     */
    public function import_post_type_schema(string $postType, int $samplePostId = 0, array $options = []): array
    {
        $postType = trim($postType);
        if ($postType === '' || !$this->is_available()) {
            return [
                'groups' => [],
                'fields' => [],
            ];
        }

        $this->onlyElements = !empty($options['only_elements']);
        $this->groupElements = $this->onlyElements && !empty($options['group_elements']);
        $this->elementGroups = [];
        $this->elementLayoutLabels = [];

        $this->sampleValues = null;
        if ($samplePostId > 0) {
            // Prefer `get_fields()` because it returns values keyed by field name and includes nested structures.
            if (function_exists('get_fields')) {
                $vals = get_fields($samplePostId, false);
                if (is_array($vals) && $vals) {
                    $this->sampleValues = $vals;
                }
            }

            // Fallback: build values map from field objects.
            if ($this->sampleValues === null && function_exists('get_field_objects')) {
                $objs = get_field_objects($samplePostId, false, true);
                if (is_array($objs) && $objs) {
                    $values = [];
                    foreach ($objs as $name => $o) {
                        if (!is_array($o)) {
                            continue;
                        }
                        // `get_field_objects()` can return arrays keyed by field key in some setups.
                        // Always normalize by the actual field `name` when available.
                        $n = '';
                        if (isset($o['name']) && is_string($o['name'])) {
                            $n = (string) $o['name'];
                        } elseif (is_string($name)) {
                            $n = $name;
                        }
                        $n = trim($n);
                        if ($n === '') {
                            continue;
                        }
                        $values[$n] = $o['value'] ?? null;
                    }
                    $this->sampleValues = $values ?: null;
                }
            }
        }

        // Note: with ACF Extended, field groups can be attached to the admin archive page
        // (location param: post_type_archive) or to an options page (options_page: "<postType>-archive").
        // `acf_get_field_groups(['post_type' => ...])` won't match those, so we filter manually.
        $allGroups = acf_get_field_groups() ?: [];
        $groups = [];
        foreach ($allGroups as $g) {
            if (is_array($g) && $this->group_matches_post_type($g, $postType)) {
                $groups[] = $g;
            }
        }

        $outGroups = [];
        $fields = [];
        $seenByKey = [];
        $nameCounts = [];

        foreach ($groups as $g) {
            $outGroups[] = [
                'id' => $g['ID'] ?? null,
                'title' => $g['title'] ?? '',
                'key' => $g['key'] ?? '',
            ];

            $acfFields = [];
            if (isset($g['key']) && is_string($g['key']) && $g['key'] !== '') {
                $acfFields = acf_get_fields($g['key']) ?: [];
            }
            if (!$acfFields && isset($g['ID'])) {
                $acfFields = acf_get_fields($g['ID']) ?: [];
            }
            if (!$acfFields) {
                $acfFields = acf_get_fields($g) ?: [];
            }
            $this->expand_fields_with_values(
                $acfFields,
                [],
                $fields,
                $seenByKey,
                $nameCounts,
                $this->sampleValues
            );
        }

        if ($this->groupElements) {
            $fields = [];
            $seenByKey = [];
            $nameCounts = [];

            foreach ($this->elementGroups as $element => $def) {
                $element = sanitize_key((string) $element);
                if ($element === '') {
                    continue;
                }

                $label = (string) ($def['label'] ?? $element);
                $label = $label !== '' ? $label : $element;

                $unique = $this->unique_name($element, $nameCounts);

                $fields[] = [
                    'db_column' => $unique,
                    'input_name' => $unique,
                    'label' => $label,
                    'field_type' => 'array_nested',
                    'flags' => [
                        'backend' => false,
                        'hidden' => false,
                        'readonly' => false,
                        'serialized' => true,
                        'group' => [
                            'kind' => (string) ($def['kind'] ?? $element),
                            'mode' => 'payload',
                            'members' => array_values((array) ($def['members'] ?? [])),
                        ],
                    ],
                ];
            }
        }

        if ($this->onlyElements && !$this->groupElements) {
            $fields = array_values(array_filter($fields, function ($f): bool {
                if (!is_array($f)) {
                    return false;
                }
                $flags = $f['flags'] ?? null;
                if (!is_array($flags)) {
                    return false;
                }
                $acf = $flags['acf'] ?? null;
                if (!is_array($acf)) {
                    return false;
                }
                $path = $acf['path'] ?? null;
                if (!is_array($path)) {
                    return false;
                }
                foreach ($path as $p) {
                    if ((string) $p === 'elements') {
                        return true;
                    }
                }
                return false;
            }));
        }

        return [
            'groups' => $outGroups,
            'fields' => $fields,
        ];
    }

    /**
     * Debug helper: summarize how PBS matches field groups for a given post type.
     *
     * @return array<string,mixed>
     */
    public function debug_match_report(string $postType): array
    {
        $postType = trim($postType);
        if ($postType === '' || !$this->is_available()) {
            return [
                'post_type' => $postType,
                'acf_available' => $this->is_available(),
                'total_groups' => 0,
                'matched' => [],
                'candidates' => [],
                'acfe_post_type' => null,
                'acf_internal_post_type' => null,
                'acf_internal_post_type_post' => null,
            ];
        }

        $all = acf_get_field_groups() ?: [];
        $matched = [];
        $candidates = [];

        $acfePostType = null;
        if (function_exists('acfe_get_settings')) {
            // ACFE stores dynamic post types under "modules.post_types".
            $acfePostType = acfe_get_settings("modules.post_types.{$postType}", null);
        }

        $acfInternal = null;
        $acfInternalPost = null;
        if (function_exists('acf_get_acf_post_types')) {
            $defs = acf_get_acf_post_types();
            if (is_array($defs)) {
                foreach ($defs as $d) {
                    if (is_array($d) && isset($d['post_type']) && (string) $d['post_type'] === $postType) {
                        $acfInternal = $d;
                        break;
                    }
                }
            }
        }
        if (function_exists('acf_get_post_type_post')) {
            $p = acf_get_post_type_post($postType);
            if (is_object($p)) {
                $acfInternalPost = [
                    'ID' => (int) ($p->ID ?? 0),
                    'post_type' => (string) ($p->post_type ?? ''),
                    'post_name' => (string) ($p->post_name ?? ''),
                    'post_title' => (string) ($p->post_title ?? ''),
                ];
            }
        }

        foreach ($all as $g) {
            if (!is_array($g)) {
                continue;
            }

            $summary = $this->summarize_group($g);
            if ($this->group_matches_post_type($g, $postType)) {
                $matched[] = $summary;
                continue;
            }

            if ($this->group_is_candidate($g, $postType)) {
                $candidates[] = $summary;
            }
        }

        return [
            'post_type' => $postType,
            'acf_available' => true,
            'total_groups' => count($all),
            'matched' => $matched,
            'candidates' => $candidates,
            'acfe_post_type' => $acfePostType,
            'acf_internal_post_type' => $acfInternal,
            'acf_internal_post_type_post' => $acfInternalPost,
        ];
    }

    /**
     * Best-effort match between an ACF field group and a WP post type (including ACFE archive pages).
     *
     * @param array<string,mixed> $group
     */
    private function group_matches_post_type(array $group, string $postType): bool
    {
        $postType = trim($postType);
        if ($postType === '') {
            return false;
        }

        // ACFE sometimes stores the archive post type name in group meta.
        if (!empty($group['acfe_post_type_archive']) && (string) $group['acfe_post_type_archive'] === $postType) {
            return true;
        }

        $loc = $group['location'] ?? null;
        if (!is_array($loc)) {
            return false;
        }

        foreach ($loc as $andGroup) {
            if (!is_array($andGroup)) {
                continue;
            }

            foreach ($andGroup as $rule) {
                if (!is_array($rule)) {
                    continue;
                }

                $param = (string) ($rule['param'] ?? '');
                $op = (string) ($rule['operator'] ?? '==');
                $val = (string) ($rule['value'] ?? '');
                if ($op !== '==') {
                    continue;
                }

                if ($param === 'post_type' && $val === $postType) {
                    return true;
                }

                // ACFE/Custom conventions: allow a single group to target all post types.
                if ($param === 'post_type' && $val === 'all') {
                    return true;
                }

                // ACFE location: Post Type Archive
                if ($param === 'post_type_archive' && $val === $postType) {
                    return true;
                }

                // ACFE admin archive page is an options page: "<postType>-archive"
                if ($param === 'options_page' && $val === "{$postType}-archive") {
                    return true;
                }

                // Some setups use the post_id string as location value.
                if ($param === 'options_page' && ($val === "{$postType}_archive" || $val === "{$postType}-archive")) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Looser check used for debugging (find groups that look related but don't match our rules).
     *
     * @param array<string,mixed> $group
     */
    private function group_is_candidate(array $group, string $postType): bool
    {
        $loc = $group['location'] ?? null;
        if (!is_array($loc)) {
            return false;
        }

        foreach ($loc as $andGroup) {
            if (!is_array($andGroup)) {
                continue;
            }
            foreach ($andGroup as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $val = (string) ($rule['value'] ?? '');
                if ($val === $postType || $val === "{$postType}-archive" || $val === "{$postType}_archive") {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $group
     * @return array<string,mixed>
     */
    private function summarize_group(array $group): array
    {
        $loc = $group['location'] ?? [];
        $rules = [];
        if (is_array($loc)) {
            foreach ($loc as $andGroup) {
                if (!is_array($andGroup)) {
                    continue;
                }
                foreach ($andGroup as $rule) {
                    if (!is_array($rule)) {
                        continue;
                    }
                    $rules[] = [
                        'param' => (string) ($rule['param'] ?? ''),
                        'operator' => (string) ($rule['operator'] ?? ''),
                        'value' => (string) ($rule['value'] ?? ''),
                    ];
                }
            }
        }

        return [
            'title' => (string) ($group['title'] ?? ''),
            'key' => (string) ($group['key'] ?? ''),
            'ID' => $group['ID'] ?? null,
            'active' => (bool) ($group['active'] ?? true),
            'acfe_post_type_archive' => (string) ($group['acfe_post_type_archive'] ?? ''),
            'location_rules' => $rules,
        ];
    }

    /**
     * @param array<int,mixed> $acfFields
     * @param array<int,string> $path
     * @param array<int,array<string,mixed>> $out
     * @param array<string,bool> $seenByKey
     * @param array<string,int> $nameCounts
     */
    private function expand_fields(array $acfFields, array $path, array &$out, array &$seenByKey, array &$nameCounts): void
    {
        $this->expand_fields_with_values($acfFields, $path, $out, $seenByKey, $nameCounts, null);
    }

    /**
     * @param array<int,mixed> $acfFields
     * @param array<int,string> $path
     * @param array<int,array<string,mixed>> $out
     * @param array<string,bool> $seenByKey
     * @param array<string,int> $nameCounts
     * @param array<string,mixed>|mixed|null $valuesCtx Current values context (assoc map or list) used to filter layouts.
     */
    private function expand_fields_with_values(array $acfFields, array $path, array &$out, array &$seenByKey, array &$nameCounts, $valuesCtx): void
    {
        foreach ($acfFields as $f) {
            if (!is_array($f)) {
                continue;
            }

            $type = (string) ($f['type'] ?? 'text');
            $fieldName = (string) ($f['name'] ?? '');

            // 1) Clone: resolve referenced fields/groups and recurse
            if ($type === 'clone') {
                $this->expand_clone_field($f, $path, $out, $seenByKey, $nameCounts, $valuesCtx);
                continue;
            }

            // 2) Flexible content: layouts -> sub_fields
            if ($type === 'flexible_content') {
                $parentName = (string) ($f['name'] ?? '');
                $usePrefix = $parentName !== '';
                $nextPathBase = $usePrefix ? array_merge($path, [$parentName]) : $path;

                $usedLayouts = null;
                $rows = $this->value_for_field($valuesCtx, $parentName, (string) ($f['key'] ?? ''));
                if ($this->sampleValues !== null) {
                    $usedLayouts = $this->extract_used_layouts_from_value($rows);
                    if ($usedLayouts === null) {
                        // In sample mode, if we can't determine used layouts for this flexible, skip it
                        // to avoid importing the entire layout library.
                        continue;
                    }
                    if ($usedLayouts === []) {
                        // In sample mode, if this flexible has no rows, skip it (avoid importing the whole library).
                        continue;
                    }
                }

                $layouts = $f['layouts'] ?? [];
                if (is_array($layouts)) {
                    foreach ($layouts as $layout) {
                        if (!is_array($layout)) {
                            continue;
                        }
                        $layoutName = (string) ($layout['name'] ?? '');
                        $layoutLabel = (string) ($layout['label'] ?? $layoutName);
                        if ($parentName === 'elements' && $layoutName !== '' && $layoutLabel !== '') {
                            $this->elementLayoutLabels[$layoutName] = $layoutLabel;
                        }
                        if ($layoutName !== '' && is_array($usedLayouts) && !isset($usedLayouts[$layoutName])) {
                            continue;
                        }
                        $layoutPath = $layoutName !== '' ? array_merge($nextPathBase, [$layoutName]) : $nextPathBase;
                        $sub = $layout['sub_fields'] ?? [];
                        if (is_array($sub) && $sub) {
                            // Pass row values for this layout when available.
                            $rowCtx = null;
                            if ($this->sampleValues !== null) {
                                $rowCtx = $this->extract_values_for_layout($rows, $layoutName);
                            }
                            $this->expand_fields_with_values($sub, $layoutPath, $out, $seenByKey, $nameCounts, $rowCtx);
                        }
                    }
                }

                // flexible itself is complex, but we don't add a direct field here
                continue;
            }

            // 3) Group / Repeater: recurse into sub_fields
            if (in_array($type, ['group', 'repeater'], true)) {
                $parentName = (string) ($f['name'] ?? '');
                $nextPath = $parentName !== '' ? array_merge($path, [$parentName]) : $path;
                $sub = $f['sub_fields'] ?? [];
                if (is_array($sub) && $sub) {
                    $subCtx = $this->value_for_field($valuesCtx, $parentName, (string) ($f['key'] ?? ''));
                    $this->expand_fields_with_values($sub, $nextPath, $out, $seenByKey, $nameCounts, $subCtx);
                }

                // group/repeater themselves can be represented as serialized, but for MVP we prefer leaf fields.
                continue;
            }

            // 4) Leaf field
            $this->append_leaf_field($f, $path, $out, $seenByKey, $nameCounts);
        }
    }

    /**
     * @param array<string,mixed> $cloneField
     * @param array<int,string> $path
     * @param array<int,array<string,mixed>> $out
     * @param array<string,bool> $seenByKey
     * @param array<string,int> $nameCounts
     */
    private function expand_clone_field(array $cloneField, array $path, array &$out, array &$seenByKey, array &$nameCounts, $valuesCtx): void
    {
        $cloneList = $cloneField['clone'] ?? [];
        if (!is_array($cloneList) || !$cloneList) {
            return;
        }

        $cloneName = (string) ($cloneField['name'] ?? '');
        $prefixName = $cloneField['prefix_name'] ?? true;
        $usePrefix = (bool) $prefixName && $cloneName !== '';
        $nextPath = $usePrefix ? array_merge($path, [$cloneName]) : $path;
        $nextValuesCtx = $usePrefix ? $this->value_for_field($valuesCtx, $cloneName, (string) ($cloneField['key'] ?? '')) : $valuesCtx;

        foreach ($cloneList as $ref) {
            if (!is_string($ref) || $ref === '') {
                continue;
            }

            // Field key
            if (str_starts_with($ref, 'field_') && function_exists('acf_get_field')) {
                $field = acf_get_field($ref);
                if (is_array($field)) {
                    $this->expand_fields_with_values([$field], $nextPath, $out, $seenByKey, $nameCounts, $nextValuesCtx);
                }
                continue;
            }

            // Group key
            if (str_starts_with($ref, 'group_') && function_exists('acf_get_fields')) {
                $sub = acf_get_fields($ref);
                if (is_array($sub) && $sub) {
                    $this->expand_fields_with_values($sub, $nextPath, $out, $seenByKey, $nameCounts, $nextValuesCtx);
                }
                continue;
            }
        }
    }

    /**
     * @param array<string,mixed> $f
     * @param array<int,string> $path
     * @param array<int,array<string,mixed>> $out
     * @param array<string,bool> $seenByKey
     * @param array<string,int> $nameCounts
     */
    private function append_leaf_field(array $f, array $path, array &$out, array &$seenByKey, array &$nameCounts): void
    {
        $name = (string) ($f['name'] ?? '');
        $label = (string) ($f['label'] ?? $name);
        $type = (string) ($f['type'] ?? 'text');
        $key = (string) ($f['key'] ?? '');

        if ($name === '') {
            return;
        }

        if ($this->groupElements) {
            $element = $this->extract_element_name_from_path($path);
            if ($element !== '') {
                $this->append_group_member($element, $f, $path);
                return;
            }
        }

        $flatName = $this->onlyElements ? $this->compose_compact_name($path, $name) : $this->compose_name($path, $name);
        $dbColumnBase = sanitize_key($flatName);
        $inputNameBase = sanitize_key($flatName);

        // De-dup by field key (stable across clones); if missing key, allow duplicates
        if ($key !== '' && isset($seenByKey[$key])) {
            return;
        }
        if ($key !== '') {
            $seenByKey[$key] = true;
        }

        // Ensure unique names in the output list (collisions can happen with clones/prefix_name=false).
        // Keep db_column and input_name aligned to avoid confusing suffixes.
        $unique = $this->unique_name($dbColumnBase, $nameCounts);
        $dbColumn = $unique;
        $inputName = $unique;

        $out[] = [
            'db_column' => $dbColumn,
            'input_name' => $inputName,
            'label' => $label !== '' ? $label : $name,
            'field_type' => $this->map_acf_type($type),
            'flags' => [
                'backend' => false,
                'hidden' => false,
                'readonly' => false,
                'serialized' => $this->is_acf_complex($type),
                'acf' => [
                    'type' => $type,
                    'key' => $key,
                    'path' => $path,
                    'orig_name' => $name,
                ],
            ],
        ];
    }

    /**
     * @param array<int,string> $path
     */
    private function compose_name(array $path, string $name): string
    {
        $parts = [];
        foreach ($path as $p) {
            $p = trim((string) $p);
            if ($p !== '') {
                $parts[] = $p;
            }
        }
        $parts[] = $name;
        return implode('_', $parts);
    }

    /**
     * Compose a short identifier suitable for "form fields" rather than DB schema storage.
     *
     * Strategy:
     * - if the path contains `elements`, keep only: <layout_before_elements>_<element_layout>_<leaf_name>
     * - otherwise fall back to the leaf name only.
     *
     * @param array<int,string> $path
     */
    private function compose_compact_name(array $path, string $leafName): string
    {
        $leafName = trim($leafName);
        if ($leafName === '') {
            return 'field';
        }

        $idx = null;
        foreach ($path as $i => $p) {
            if ((string) $p === 'elements') {
                $idx = (int) $i;
                break;
            }
        }

        if ($idx === null) {
            return $leafName;
        }

        $after = trim((string) ($path[$idx + 1] ?? ''));

        $parts = [];
        if ($after !== '') {
            $parts[] = $after; // e.g. text/button
        }
        $parts[] = $leafName;

        return implode('_', $parts);
    }

    /**
     * @param array<int,string> $path
     */
    private function extract_element_name_from_path(array $path): string
    {
        $idx = null;
        foreach ($path as $i => $p) {
            if ((string) $p === 'elements') {
                $idx = (int) $i;
                break;
            }
        }
        if ($idx === null) {
            return '';
        }
        $element = trim((string) ($path[$idx + 1] ?? ''));
        return $element;
    }

    /**
     * Append a leaf field as a member of an "element group".
     *
     * @param array<string,mixed> $f
     * @param array<int,string> $path
     */
    private function append_group_member(string $element, array $f, array $path): void
    {
        $element = sanitize_key($element);
        if ($element === '') {
            return;
        }

        $label = (string) ($this->elementLayoutLabels[$element] ?? $element);
        if (!isset($this->elementGroups[$element])) {
            $this->elementGroups[$element] = [
                'label' => $label,
                'kind' => $element,
                'members' => [],
            ];
        } elseif ($label !== '' && (string) ($this->elementGroups[$element]['label'] ?? '') === $element) {
            $this->elementGroups[$element]['label'] = $label;
        }

        $name = (string) ($f['name'] ?? '');
        $rawKey = (string) ($name !== '' ? $name : ((string) ($f['key'] ?? 'field')));
        $memberKey = ComponentRegistry::normalize_member_key($element, $rawKey);
        if ($memberKey === '') {
            $memberKey = sanitize_key($rawKey);
        }
        $memberLabel = (string) ($f['label'] ?? $memberKey);
        $acfType = (string) ($f['type'] ?? 'text');

        // Contraltare "standard" (PBS) per elementi complessi (ACF).
        // I mapping dei tipi per componenti noti sono centralizzati nel registry.
        $mapped = ComponentRegistry::infer_member_type($element, $memberKey, $acfType);

        $this->elementGroups[$element]['members'][] = [
            'key' => $memberKey,
            'label' => $memberLabel,
            'field_type' => $mapped,
            'acf' => [
                'type' => $acfType,
                'key' => (string) ($f['key'] ?? ''),
                'path' => $path,
                'orig_name' => $name,
            ],
        ];
    }

    /**
     * @param array<string,int> $nameCounts
     */
    private function unique_name(string $base, array &$nameCounts): string
    {
        $base = $base !== '' ? $base : 'field';
        if (!isset($nameCounts[$base])) {
            $nameCounts[$base] = 1;
            return $base;
        }
        $nameCounts[$base]++;
        return $base . '_' . $nameCounts[$base];
    }

    /**
     * Public helper used by admin UI to show which layouts were detected in a sample post.
     *
     * @return array<string,string[]> flex_field_name => layout names
     */
    public function debug_layouts_from_post(int $postId): array
    {
        // Prefer values, not objects.
        if (function_exists('get_fields')) {
            $vals = get_fields($postId, false);
            if (is_array($vals) && $vals) {
                return $this->debug_layouts_from_values($vals);
            }
        }

        if (!function_exists('get_field_objects')) {
            return [];
        }
        $objs = get_field_objects($postId, false, true);
        if (!is_array($objs) || !$objs) {
            return [];
        }
        $values = [];
        foreach ($objs as $name => $o) {
            if (!is_array($o)) {
                continue;
            }
            $n = '';
            if (isset($o['name']) && is_string($o['name'])) {
                $n = (string) $o['name'];
            } elseif (is_string($name)) {
                $n = $name;
            }
            $n = trim($n);
            if ($n === '') {
                continue;
            }
            $values[$n] = $o['value'] ?? null;
        }
        return $this->debug_layouts_from_values($values);
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,string[]>
     */
    private function debug_layouts_from_values(array $values): array
    {
        $out = [];
        foreach ($values as $fieldName => $val) {
            if (!is_string($fieldName) || $fieldName === '') {
                continue;
            }
            $set = $this->extract_used_layouts_from_value($val);
            if ($set !== null && $set !== []) {
                $out[$fieldName] = array_keys($set);
            }
        }

        // Always provide a global view (useful when flexibles are nested inside repeaters/groups).
        $any = [];
        $this->collect_layouts_anywhere($values, $any);
        if ($any) {
            $out['*'] = array_keys($any);
        }

        return $out;
    }

    /**
     * Collect layout names from any nested value structure.
     *
     * @param mixed $value
     * @param array<string,bool> $set
     */
    private function collect_layouts_anywhere($value, array &$set): void
    {
        if (!is_array($value)) {
            return;
        }
        if (isset($value['acf_fc_layout']) && is_string($value['acf_fc_layout']) && $value['acf_fc_layout'] !== '') {
            $set[$value['acf_fc_layout']] = true;
        }
        foreach ($value as $v) {
            $this->collect_layouts_anywhere($v, $set);
        }
    }

    /**
     * Extract used layout names from a flexible-content-like value.
     *
     * @param mixed $value
     * @return array<string,bool>|null null if not a flexible value; empty array if flexible but no rows
     */
    private function extract_used_layouts_from_value($value): ?array
    {
        // ACF flexible value: list of rows, each row is array including 'acf_fc_layout'.
        if (!is_array($value)) {
            return null;
        }

        $set = [];
        $found = false;

        // list of rows OR list of lists-of-rows (e.g. flexible nested in repeater)
        if ($this->is_list($value)) {
            foreach ($value as $item) {
                if (!is_array($item)) {
                    continue;
                }

                // direct row
                if (isset($item['acf_fc_layout']) && is_string($item['acf_fc_layout']) && $item['acf_fc_layout'] !== '') {
                    $set[$item['acf_fc_layout']] = true;
                    $found = true;
                    continue;
                }

                // nested list of rows
                if ($this->is_list($item)) {
                    foreach ($item as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        if (isset($row['acf_fc_layout']) && is_string($row['acf_fc_layout']) && $row['acf_fc_layout'] !== '') {
                            $set[$row['acf_fc_layout']] = true;
                            $found = true;
                        }
                    }
                }
            }

            return $found ? $set : [];
        }

        return null;
    }

    /**
     * Pick value for a field name from a context.
     *
     * @param mixed $valuesCtx
     * @return mixed|null
     */
    /**
     * Pick value for a field (by name and/or key) from a context.
     *
     * @param mixed $valuesCtx
     * @return mixed|null
     */
    private function value_for_field($valuesCtx, string $fieldName, string $fieldKey = '')
    {
        $fieldName = trim($fieldName);
        $fieldKey = trim($fieldKey);

        if ($fieldName === '' && $fieldKey === '') {
            return null;
        }
        if ($fieldName !== '' && is_array($valuesCtx) && array_key_exists($fieldName, $valuesCtx)) {
            return $valuesCtx[$fieldName];
        }
        if ($fieldKey !== '' && is_array($valuesCtx) && array_key_exists($fieldKey, $valuesCtx)) {
            return $valuesCtx[$fieldKey];
        }
        if (is_array($valuesCtx) && !$this->is_list($valuesCtx)) {
            // Fallback for prefixed keys (clone/group prefix_name): try suffix match like "*_layouts".
            $needleName = '_' . $fieldName;
            $needleKey = $fieldKey !== '' ? '_' . $fieldKey : '';
            $candidates = [];
            foreach ($valuesCtx as $k => $v) {
                if (!is_string($k)) {
                    continue;
                }
                if ($k === $fieldName || ($fieldKey !== '' && $k === $fieldKey)) {
                    return $v;
                }
                if ($needleName !== '_' && str_ends_with($k, $needleName)) {
                    $candidates[$k] = $v;
                    continue;
                }
                // Some ACFE clone setups use compound keys like "{clone_field_key}_{cloned_field_key}".
                if ($needleKey !== '' && str_ends_with($k, $needleKey)) {
                    $candidates[$k] = $v;
                }
            }
            if ($candidates) {
                // Pick the shortest key (closest match).
                $bestKey = null;
                foreach (array_keys($candidates) as $k) {
                    if ($bestKey === null || strlen($k) < strlen($bestKey)) {
                        $bestKey = $k;
                    }
                }
                if ($bestKey !== null) {
                    return $candidates[$bestKey];
                }
            }
        }
        // If we're in a repeater-like list context, return a list of per-row values.
        if (is_array($valuesCtx) && $this->is_list($valuesCtx)) {
            $out = [];
            foreach ($valuesCtx as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (array_key_exists($fieldName, $row)) {
                    $out[] = $row[$fieldName];
                    continue;
                }
                if ($fieldKey !== '' && array_key_exists($fieldKey, $row)) {
                    $out[] = $row[$fieldKey];
                    continue;
                }
                // Fallback for prefixed keys inside row arrays.
                $needleName = '_' . $fieldName;
                $needleKey = $fieldKey !== '' ? '_' . $fieldKey : '';
                $candidates = [];
                foreach ($row as $k => $v) {
                    if (!is_string($k)) {
                        continue;
                    }
                    if ($needleName !== '_' && str_ends_with($k, $needleName)) {
                        $candidates[$k] = $v;
                        continue;
                    }
                    if ($needleKey !== '' && str_ends_with($k, $needleKey)) {
                        $candidates[$k] = $v;
                    }
                }
                if ($candidates) {
                    $bestKey = null;
                    foreach (array_keys($candidates) as $k) {
                        if ($bestKey === null || strlen($k) < strlen($bestKey)) {
                            $bestKey = $k;
                        }
                    }
                    if ($bestKey !== null) {
                        $out[] = $candidates[$bestKey];
                    }
                }
            }
            return $out ?: null;
        }
        return null;
    }

    /**
     * Checks if a values context includes the given field (either as key or within list rows).
     *
     * @param mixed $valuesCtx
     */
    private function values_context_has_field($valuesCtx, string $fieldName): bool
    {
        if ($fieldName === '') {
            return false;
        }
        if (is_array($valuesCtx) && array_key_exists($fieldName, $valuesCtx)) {
            return true;
        }
        if (is_array($valuesCtx) && $this->is_list($valuesCtx)) {
            foreach ($valuesCtx as $row) {
                if (is_array($row) && array_key_exists($fieldName, $row)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Extract values context for a specific flexible layout name.
     *
     * @param mixed $rows
     * @return array<int,array<string,mixed>>|null list of row arrays for that layout
     */
    private function extract_values_for_layout($rows, string $layoutName): ?array
    {
        if ($layoutName === '' || !is_array($rows) || !$this->is_list($rows)) {
            return null;
        }
        $out = [];
        foreach ($rows as $item) {
            if (!is_array($item)) {
                continue;
            }

            // direct row
            if (isset($item['acf_fc_layout']) && (string) $item['acf_fc_layout'] === $layoutName) {
                $out[] = $item;
                continue;
            }

            // nested list of rows
            if ($this->is_list($item)) {
                foreach ($item as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    if (isset($row['acf_fc_layout']) && (string) $row['acf_fc_layout'] === $layoutName) {
                        $out[] = $row;
                    }
                }
            }
        }
        return $out ?: null;
    }

    private function is_list(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    private function map_acf_type(string $acfType): string
    {
        // Mapping “leggero” verso i tipi usati negli standard (da raffinare).
        return match ($acfType) {
            'textarea' => 'text_area',
            'url' => 'url',
            'email' => 'email',
            'wysiwyg' => 'html',
            'repeater', 'group', 'flexible_content' => 'array_nested',
            'checkbox' => 'text',
            'true_false' => 'bool',
            default => 'text',
        };
    }

    private function is_acf_complex(string $acfType): bool
    {
        return in_array($acfType, ['repeater', 'group', 'flexible_content'], true);
    }
}
