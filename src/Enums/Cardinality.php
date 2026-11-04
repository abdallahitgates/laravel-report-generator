<?php

namespace Abdalla\ReportGenerator\Enums;

enum Cardinality: string
{
    case OneToOne = 'one_to_one';
    case OneToMany = 'one_to_many';
    case ManyToOne = 'many_to_one';

    /** Joining in this direction can multiply rows of the left side. */
    public function fansOut(): bool
    {
        return $this === self::OneToMany;
    }

    public function inverse(): self
    {
        return match ($this) {
            self::OneToOne => self::OneToOne,
            self::OneToMany => self::ManyToOne,
            self::ManyToOne => self::OneToMany,
        };
    }
}
