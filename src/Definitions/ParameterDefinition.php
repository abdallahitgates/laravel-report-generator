<?php

namespace Abdalla\ReportGenerator\Definitions;

/**
 * A value the user supplies when running a report (period, line, ...).
 */
final class ParameterDefinition implements \JsonSerializable
{
    public const TYPES = ['string', 'number', 'date', 'date_range', 'boolean', 'select', 'multi_select'];

    /**
     * @param  array<int, array{value: mixed, label: string}>  $options  for select / multi_select
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly bool $required = false,
        public readonly mixed $default = null,
        public readonly array $options = [],
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(
                "Invalid parameter type [{$type}] for [{$key}]. Allowed: ".implode(', ', self::TYPES)
            );
        }
    }

    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'required' => $this->required,
            'default' => $this->default,
            'options' => $this->options,
        ];
    }
}
