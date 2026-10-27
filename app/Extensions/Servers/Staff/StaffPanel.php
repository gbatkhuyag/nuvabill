<?php

namespace App\Extensions\Servers\Staff;

/**
 * A module's card on the admin service page (HasStaffPanel): facts as plain text (shown escaped,
 * never as HTML) and the staff actions for this service.
 */
final readonly class StaffPanel
{
    /**
     * @param  string  $title  The card's title, already translated.
     * @param  list<array{label: string, value: string|int}>  $rows  At most 30 are shown. Never put a password, key or token in them.
     * @param  list<StaffAction>  $actions  At most 10 are shown.
     * @param  string|null  $notice  A short note above the actions, such as "The node is in maintenance".
     */
    public function __construct(
        public string $title,
        public array $rows = [],
        public array $actions = [],
        public ?string $notice = null,
    ) {}

    /**
     * The rows core can show.
     *
     * @return list<array{label: string, value: string}>
     */
    public function checkedRows(): array
    {
        $rows = [];

        foreach ($this->rows as $row) {
            if (count($rows) >= 30) {
                break;
            }

            if (is_array($row) && is_string($row['label'] ?? null) && (is_string($row['value'] ?? null) || is_int($row['value'] ?? null))) {
                $rows[] = ['label' => mb_substr($row['label'], 0, 120), 'value' => mb_substr((string) $row['value'], 0, 1000)];
            }
        }

        return $rows;
    }

    /**
     * The actions core can show, keyed by key. A key used twice keeps the first.
     *
     * @return array<string, StaffAction>
     */
    public function checkedActions(): array
    {
        $actions = [];

        foreach ($this->actions as $action) {
            if (count($actions) >= 10) {
                break;
            }

            if ($action instanceof StaffAction && preg_match(StaffAction::KEY_PATTERN, $action->key) === 1 && ! isset($actions[$action->key])) {
                $actions[$action->key] = $action;
            }
        }

        return $actions;
    }
}
