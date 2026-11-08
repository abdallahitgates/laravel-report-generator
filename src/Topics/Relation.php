<?php

namespace Abdalla\ReportGenerator\Topics;

/**
 * How a topic connects to another, under a name report builders see.
 *
 *   Relation::belongsTo('employee', 'Employee', UsersTopic::class, 'user_id')   // visits.user_id -> users.id
 *   Relation::hasMany('products', 'Products detailed', VisitProductsTopic::class, 'visit_id')  // visit_products.visit_id -> visits.id
 */
final class Relation
{
    public const BELONGS_TO = 'belongsTo';
    public const HAS_MANY = 'hasMany';
    public const HAS_ONE = 'hasOne';

    /**
     * @param  class-string<Topic>  $topic
     * @param  string  $foreignKey  belongsTo: column on this topic; hasMany / hasOne: column on the other topic
     * @param  string|null  $otherKey  belongsTo: key on the other topic; hasMany / hasOne: key on this topic (default: primary key)
     */
    private function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $topic,
        public readonly string $type,
        public readonly string $foreignKey,
        public readonly ?string $otherKey,
    ) {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/i', $name)) {
            throw new InvalidTopicException("Relation name [{$name}] must be letters, digits and underscores.");
        }
    }

    /** @param  class-string<Topic>  $topic */
    public static function belongsTo(string $name, string $label, string $topic, string $foreignKey, ?string $ownerKey = null): self
    {
        return new self($name, $label, $topic, self::BELONGS_TO, $foreignKey, $ownerKey);
    }

    /** @param  class-string<Topic>  $topic */
    public static function hasMany(string $name, string $label, string $topic, string $foreignKey, ?string $localKey = null): self
    {
        return new self($name, $label, $topic, self::HAS_MANY, $foreignKey, $localKey);
    }

    /** @param  class-string<Topic>  $topic */
    public static function hasOne(string $name, string $label, string $topic, string $foreignKey, ?string $localKey = null): self
    {
        return new self($name, $label, $topic, self::HAS_ONE, $foreignKey, $localKey);
    }
}
