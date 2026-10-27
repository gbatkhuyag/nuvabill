<?php

namespace App\Contracts;

use App\Models\Product;

/**
 * A server module that asks the client for details when they order, for example an SSH key or a
 * hostname. Core shows the fields on the order form, checks them with core's rules and the
 * module's own, keeps the values on the service (encrypted), and the module reads them in
 * create() with Module::orderField(). The manifest must list "order-fields" in its permissions.
 * Since Nuvabill 0.7.0.
 */
interface HasOrderFields
{
    /**
     * The fields for one product, keyed by a name of lowercase letters, digits and underscores
     * (at most 20 fields). Must not call the server: the store shows them on every visit.
     *
     * - label: the field's name, already translated.
     * - type: "text" (one line, up to 190 characters), "textarea" (up to "max", at most 16384),
     *   "select" (one of "options", value => label) or "ssh-key" (public keys, one per line).
     * - required, help, max: as on other forms. A text field allows at most 190 characters.
     * - rules: more Laravel rules for the value, such as ['regex:/^[a-z0-9-]+$/']. They can only
     *   make the check stricter: core's own rules always run first.
     * - secret: the value is never shown again, not to the client and not to staff.
     *
     * @return array<string, array{label: string, type: string, help?: string, required?: bool, max?: int, options?: array<string, string>, rules?: list<mixed>, secret?: bool}>
     */
    public function orderFields(Product $product): array;
}
