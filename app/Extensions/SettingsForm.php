<?php

namespace App\Extensions;

use App\Extensions\Servers\ModuleConfig;
use App\Http\SecretInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The settings form of a gateway, registrar or add-on, as the extension describes it in
 * settingsFields(). A secret field (type "password", or marked "secret") is never filled in again:
 * left empty, the saved value stays, and after a form error it is not kept in the session.
 */
final class SettingsForm
{
    /**
     * Check the posted settings[] and give the validated input. On an error the form comes back
     * with what was typed, except the secret fields.
     *
     * @param  array<string, array<string, mixed>>  $fields  The extension's settingsFields().
     * @param  array<string, mixed>  $current  The saved settings.
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validate(Request $request, array $fields, array $current, bool $enabled, int $textareaMax = 5000): array
    {
        $rules = ['enabled' => ['boolean']];

        foreach ($fields as $key => $field) {
            $required = ($field['required'] ?? false) && $enabled && ! (self::isSecret($field) && filled($current[$key] ?? null));
            $rules["settings.{$key}"] = array_filter([
                $required ? 'required' : 'nullable',
                'string',
                ($field['type'] ?? null) === 'textarea' ? 'max:'.$textareaMax : 'max:1000',
                isset($field['options']) ? 'in:'.implode(',', array_keys($field['options'])) : null,
            ]);
        }

        $labels = collect($fields)->mapWithKeys(fn (array $field, string $key): array => ["settings.{$key}" => $field['label'] ?? $key])->all();
        $validator = Validator::make($request->all(), $rules, [], $labels);

        if ($validator->fails()) {
            throw new ValidationException($validator, $request->expectsJson() ? null : back()
                ->withErrors($validator)
                ->withInput($request->except([...SecretInput::names(), ...self::secretInputKeys($fields)])));
        }

        return $validator->validated();
    }

    /**
     * The settings to save: a secret field left empty keeps its saved value.
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $input  The validated input.
     * @param  array<string, mixed>  $current  The saved settings.
     * @return array<string, mixed>
     */
    public static function values(array $fields, array $input, array $current): array
    {
        $settings = [];

        foreach ($fields as $key => $field) {
            $value = $input['settings'][$key] ?? null;
            $settings[$key] = (self::isSecret($field) && blank($value)) ? ($current[$key] ?? null) : $value;
        }

        return $settings;
    }

    /**
     * The request keys of the secret fields, such as "settings.secret_key".
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @return list<string>
     */
    public static function secretInputKeys(array $fields, string $prefix = 'settings'): array
    {
        return array_values(array_map(
            fn (int|string $key): string => "{$prefix}.{$key}",
            array_keys(array_filter($fields, fn (mixed $field): bool => is_array($field) && self::isSecret($field))),
        ));
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private static function isSecret(array $field): bool
    {
        return ModuleConfig::isSecret($field);
    }
}
