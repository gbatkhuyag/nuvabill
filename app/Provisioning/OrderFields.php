<?php

namespace App\Provisioning;

use App\Contracts\HasOrderFields;
use App\Extensions\Servers\ModuleHooks;
use App\Models\Product;
use App\Models\Service;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Fields a server module asks for on the order form (HasOrderFields), such as an SSH key.
 *
 * Core checks each declaration (bad ones are left out and logged once a day), checks the values
 * with its own rules first and the module's after, and keeps them on the service in order_fields,
 * encrypted with the app key. In the cart they are encrypted too. A field marked "secret" is
 * posted as secret_fields[key], is never written back into a form after an error, and is never
 * shown again: not to the client, not to staff, and never in a log.
 *
 * Secret values are kept only until the service is set up: once create() has activated it, they
 * are removed (forgetSecrets()), and a terminated service keeps no order fields at all. A module
 * reads a secret field in create() only.
 */
final class OrderFields
{
    public const TYPES = ['text', 'textarea', 'select', 'ssh-key'];

    /**
     * A field name: a lowercase letter, then up to 39 lowercase letters, digits or underscores.
     */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,39}\z/';

    public const MAX_FIELDS = 20;

    /**
     * The form names of plain and secret fields.
     */
    public const PLAIN = 'fields';

    public const SECRET = 'secret_fields';

    /**
     * The longest text field.
     */
    public const MAX_TEXT = 190;

    /**
     * The longest textarea or list of SSH keys.
     */
    public const MAX_LONG = 16384;

    /**
     * The longest textarea and list of SSH keys when the module gives no "max".
     */
    public const DEFAULT_LONG = 4000;

    /**
     * The most SSH keys one field takes.
     */
    public const MAX_KEYS = 10;

    /**
     * SSH public key types a field takes.
     */
    private const KEY_TYPES = [
        'ssh-ed25519', 'ssh-rsa', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521',
        'sk-ssh-ed25519@openssh.com', 'sk-ecdsa-sha2-nistp256@openssh.com',
    ];

    /**
     * The checked fields of a product's module, or none for a product without a module, a module
     * without the contract or the "order-fields" permission, or one that fails.
     *
     * @return array<string, array{label: string, type: string, help: string|null, required: bool, max: int, options: array<string, string>, rules: list<mixed>, secret: bool}>
     */
    public function for(Product $product): array
    {
        $slug = (string) $product->server_module;

        if ($slug === '') {
            return [];
        }

        $module = app(ModuleHooks::class)->modulesWith(HasOrderFields::class, 'order-fields')[$slug] ?? null;

        if ($module === null) {
            return [];
        }

        try {
            $declared = $module->orderFields($product);
        } catch (Throwable $exception) {
            report(new RuntimeException("Order fields of {$slug} for product #{$product->id}: ".class_basename($exception)));

            return [];
        }

        $fields = [];

        foreach (is_array($declared) ? $declared : [] as $key => $field) {
            if (count($fields) >= self::MAX_FIELDS) {
                break;
            }

            $problem = self::problem($key, $field);

            if ($problem !== null) {
                if (Cache::add('nuvabill:order-fields:skipped:'.$slug.':'.sha1($problem), true, 86400)) {
                    Log::warning("Server module \"{$slug}\": an order field of product #{$product->id} was left out. {$problem}");
                }

                continue;
            }

            $type = $field['type'];
            $max = is_int($field['max'] ?? null) ? $field['max'] : ($type === 'text' || $type === 'select' ? self::MAX_TEXT : self::DEFAULT_LONG);
            $options = [];

            foreach ((array) ($field['options'] ?? []) as $value => $label) {
                if (is_string($label) && mb_strlen((string) $value) <= 190 && count($options) < 200) {
                    $options[(string) $value] = mb_substr($label, 0, 190);
                }
            }

            $fields[$key] = [
                'label' => mb_substr($field['label'], 0, 120),
                'type' => $type,
                'help' => is_string($field['help'] ?? null) ? mb_substr($field['help'], 0, 300) : null,
                'required' => ($field['required'] ?? false) === true,
                'max' => max(1, min($type === 'text' || $type === 'select' ? self::MAX_TEXT : self::MAX_LONG, $max)),
                'options' => $options,
                'rules' => array_values(array_filter((array) ($field['rules'] ?? []), fn (mixed $rule): bool => is_string($rule) || is_object($rule))),
                'secret' => ($field['secret'] ?? false) === true,
            ];
        }

        return $fields;
    }

    /**
     * The fields for the store's order form, without the module's rules. Never throws. "name" is
     * the form field name (fields[key], or secret_fields[key] for a secret field) and "error" the
     * key of its error message.
     *
     * @return array<string, array{label: string, type: string, help: string|null, required: bool, max: int, options: array<string, string>, secret: bool, name: string, error: string}>
     */
    public function forStore(Product $product): array
    {
        try {
            $fields = $this->for($product);
        } catch (Throwable $exception) {
            report(new RuntimeException("Order fields of product #{$product->id}: ".class_basename($exception)));

            return [];
        }

        $shown = [];

        foreach ($fields as $key => $field) {
            $bag = self::bag($field);
            $shown[$key] = array_diff_key($field, ['rules' => true]) + ['name' => $bag.'['.$key.']', 'error' => $bag.'.'.$key];
        }

        return $shown;
    }

    /**
     * Check the values the client entered for a product, core's rules first: plain fields from
     * $input['fields'], secret ones from $input['secret_fields']. Errors are keyed like the form
     * names, for example "fields.hostname". Empty optional fields are left out; SSH keys come back
     * one per line.
     *
     * @param  array<mixed>  $input  The request input, or at least its "fields" and "secret_fields".
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    public function validate(Product $product, array $input): array
    {
        $fields = $this->for($product);

        if ($fields === []) {
            return [];
        }

        $data = [self::PLAIN => [], self::SECRET => []];
        $rules = [];
        $names = [];

        foreach ($fields as $key => $field) {
            $bag = self::bag($field);
            $value = is_array($input[$bag] ?? null) ? ($input[$bag][$key] ?? null) : null;
            // Windows line ends become plain ones, so the length and the keys are counted alike.
            $data[$bag][$key] = is_string($value) ? str_replace("\r\n", "\n", trim($value)) : $value;

            $base = [$field['required'] ? 'required' : 'nullable', 'string', 'max:'.$field['max']];

            if ($field['type'] === 'select') {
                $base[] = Rule::in(array_map('strval', array_keys($field['options'])));
            }

            if ($field['type'] === 'text') {
                // One line, no control characters.
                $base[] = 'not_regex:/[\x00-\x1F\x7F]/';
            }

            if ($field['type'] === 'ssh-key') {
                $base[] = function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && ! self::validKeys($value)) {
                        $fail(__('Enter OpenSSH public keys, one per line, at most :count.', ['count' => self::MAX_KEYS]));
                    }
                };
            }

            // The module's rules can only add to core's.
            $rules[$bag.'.'.$key] = [...$base, ...$field['rules']];
            $names[$bag.'.'.$key] = $field['label'];
        }

        $checked = Validator::make($data, $rules, [], $names)->validate();
        $clean = [];

        foreach ($fields as $key => $field) {
            $value = $checked[self::bag($field)][$key] ?? null;

            if (is_string($value) && $value !== '') {
                $clean[$key] = $field['type'] === 'ssh-key' ? implode("\n", self::keyLines($value)) : $value;
            }
        }

        return $clean;
    }

    /**
     * The order form values of a service for staff: label and value of each field the module
     * still declares, with secret ones hidden (null). Values of fields the module no longer
     * declares are hidden too, since nobody knows any more whether they were secret.
     *
     * @return list<array{label: string, value: string|null}>
     */
    public function forStaff(Service $service): array
    {
        $values = (array) $service->order_fields;

        if ($values === []) {
            return [];
        }

        $fields = $this->for($service->loadMissing('product')->product);
        $rows = [];

        foreach ($values as $key => $value) {
            $field = $fields[$key] ?? null;
            $rows[] = [
                'label' => $field['label'] ?? (string) $key,
                'value' => $field !== null && ! $field['secret'] && is_string($value) ? $value : null,
            ];
        }

        return $rows;
    }

    /**
     * Remove the secret values from a service that is set up, keeping the plain ones staff see.
     * A value of a field the module no longer declares goes too (nobody knows whether it was
     * secret), and when the module cannot list its fields every value goes.
     */
    public function forgetSecrets(Service $service): void
    {
        $values = (array) $service->order_fields;

        if ($values === []) {
            return;
        }

        try {
            $fields = $this->for($service->loadMissing('product')->product);
        } catch (Throwable $exception) {
            report(new RuntimeException("Order fields of service #{$service->id}: ".class_basename($exception)));
            $fields = [];
        }

        $kept = array_filter($values, fn (mixed $value, mixed $key): bool => isset($fields[$key]) && ! $fields[$key]['secret'], ARRAY_FILTER_USE_BOTH);

        if ($kept !== $values) {
            $service->forceFill(['order_fields' => $kept !== [] ? $kept : null])->save();
        }
    }

    /**
     * The form name a field is posted under.
     *
     * @param  array{secret: bool}  $field
     */
    private static function bag(array $field): string
    {
        return $field['secret'] ? self::SECRET : self::PLAIN;
    }

    /**
     * What is wrong with one declared field, or null.
     */
    private static function problem(mixed $key, mixed $field): ?string
    {
        if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
            return 'Its name must be a lowercase letter, then lowercase letters, digits or underscores.';
        }

        if (! is_array($field) || ! is_string($field['label'] ?? null) || trim($field['label']) === '') {
            return "The field \"{$key}\" has no label.";
        }

        if (! in_array($field['type'] ?? null, self::TYPES, true)) {
            return "The field \"{$key}\" has a type core does not know. Use ".implode(', ', self::TYPES).'.';
        }

        if ($field['type'] === 'select' && array_filter((array) ($field['options'] ?? []), 'is_string') === []) {
            return "The select field \"{$key}\" has no options.";
        }

        if (isset($field['max']) && (! is_int($field['max']) || $field['max'] < 1)) {
            return "The field \"{$key}\" has a max that is not a positive whole number.";
        }

        return null;
    }

    /**
     * Whether every non-empty line is an OpenSSH public key of a known type whose key body names
     * the same type, and there are at most MAX_KEYS of them.
     */
    private static function validKeys(string $value): bool
    {
        $lines = self::keyLines($value);

        if ($lines === [] || count($lines) > self::MAX_KEYS) {
            return false;
        }

        foreach ($lines as $line) {
            if (preg_match('#^(\S+) ([A-Za-z0-9+/]+={0,3})(?: [^\x00-\x1F\x7F]{0,200})?\z#', $line, $match) !== 1 || ! in_array($match[1], self::KEY_TYPES, true)) {
                return false;
            }

            $body = base64_decode($match[2], true);

            // The key body starts with its own type name: a 4-byte length, then the name.
            if ($body === false || strlen($body) < 4 + strlen($match[1]) || substr($body, 4, (int) unpack('N', $body)[1]) !== $match[1]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function keyLines(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $value) ?: []), fn (string $line): bool => $line !== ''));
    }
}
