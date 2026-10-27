<?php

namespace App\Extensions\Servers\Staff;

use Illuminate\Validation\Rule;

/**
 * A button on a module's card on the admin service page (HasStaffPanel), with an optional small
 * form. Core shows only what is declared, checks the fields and sends only them to the module.
 */
final readonly class StaffAction
{
    /**
     * Field types core can show.
     *
     * @var list<string>
     */
    public const FIELD_TYPES = ['text', 'number', 'select', 'checkbox'];

    /**
     * An action key: 1 to 40 lowercase letters, digits or dashes.
     */
    public const KEY_PATTERN = '/^[a-z0-9][a-z0-9-]{0,39}\z/';

    /**
     * A field name: a lowercase letter, then up to 39 lowercase letters, digits or underscores.
     */
    public const FIELD_PATTERN = '/^[a-z][a-z0-9_]{0,39}\z/';

    /**
     * @param  string  $key  For example "migrate".
     * @param  string  $label  The button text, already translated.
     * @param  array<string, array{label: string, type: string, help?: string, required?: bool, options?: array<string, string>, max?: int}>  $fields  At most 10. Text values are at most "max" (default and most 190) characters, numbers are whole numbers, and a select only takes one of its options.
     * @param  string|null  $confirm  A question staff confirm first, already translated.
     * @param  bool  $danger  Shown as a red button.
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $fields = [],
        public ?string $confirm = null,
        public bool $danger = false,
    ) {}

    /**
     * The fields core can show, with bad ones left out.
     *
     * @return array<string, array{label: string, type: string, help: string|null, required: bool, options: array<string, string>, max: int}>
     */
    public function checkedFields(): array
    {
        $fields = [];

        foreach ($this->fields as $name => $field) {
            if (count($fields) >= 10) {
                break;
            }

            if (! is_string($name) || preg_match(self::FIELD_PATTERN, $name) !== 1 || ! is_array($field)
                || ! is_string($field['label'] ?? null) || ! in_array($field['type'] ?? null, self::FIELD_TYPES, true)) {
                continue;
            }

            $options = [];

            foreach ((array) ($field['options'] ?? []) as $value => $label) {
                if (is_string($label) && count($options) < 200) {
                    $options[(string) $value] = $label;
                }
            }

            if ($field['type'] === 'select' && $options === []) {
                continue;
            }

            $fields[$name] = [
                'label' => $field['label'],
                'type' => $field['type'],
                'help' => is_string($field['help'] ?? null) ? $field['help'] : null,
                'required' => ($field['required'] ?? false) === true,
                'options' => $options,
                'max' => max(1, min(190, is_int($field['max'] ?? null) ? $field['max'] : 190)),
            ];
        }

        return $fields;
    }

    /**
     * Laravel rules for the checked fields.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->checkedFields() as $name => $field) {
            $rules[$name] = match ($field['type']) {
                'checkbox' => ['nullable', 'boolean'],
                'number' => [$field['required'] ? 'required' : 'nullable', 'integer', 'between:-2147483648,2147483647'],
                'select' => [$field['required'] ? 'required' : 'nullable', 'string', Rule::in(array_map('strval', array_keys($field['options'])))],
                default => [$field['required'] ? 'required' : 'nullable', 'string', 'max:'.$field['max']],
            };
        }

        return $rules;
    }
}
