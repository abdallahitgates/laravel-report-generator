<?php

namespace Abdalla\ReportGenerator\Schema\Relationships;

use Abdalla\ReportGenerator\Enums\Cardinality;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Reads belongsTo / hasOne / hasMany definitions from the host's Eloquent
 * models. Legacy databases often have no foreign keys, but the models know
 * how tables connect.
 *
 * Only methods whose source calls one of those three builders are invoked,
 * so arbitrary model methods never run. Building a relation object does not
 * query the database.
 */
final class EloquentRelationshipScanner
{
    private const RELATION_CALL = '/\$this\s*->\s*(belongsTo|hasOne|hasMany)\s*\(/';

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param  array<string, bool>  $paths  directory => recurse
     * @return list<InferredRelationship>
     */
    public function scan(array $paths): array
    {
        $this->warnings = [];
        $edges = [];

        foreach ($this->modelClasses($paths) as $class) {
            try {
                $model = new $class();
            } catch (Throwable $e) {
                $this->warnings[] = "Skipped {$class}: ".$e->getMessage();
                continue;
            }

            foreach ($this->relationMethods($class) as $method) {
                try {
                    $relation = $model->{$method}();
                } catch (Throwable) {
                    continue;
                }

                if ($edge = $this->toEdge($model, $relation)) {
                    $edges[] = $edge;
                }
            }
        }

        return $edges;
    }

    /**
     * The belongsTo / hasOne / hasMany relations one model declares.
     *
     * @param  class-string<Model>  $class
     * @return list<array{method: string, type: string, related: class-string<Model>, foreign_key: string, other_key: string}>
     */
    public function relationsOf(string $class): array
    {
        $model = new $class();
        $found = [];

        foreach ($this->relationMethods($class) as $method) {
            try {
                $relation = $model->{$method}();
            } catch (Throwable) {
                continue;
            }

            if ($relation instanceof BelongsTo) {
                $found[] = ['method' => $method, 'type' => 'belongsTo', 'related' => $relation->getRelated()::class,
                    'foreign_key' => $relation->getForeignKeyName(), 'other_key' => $relation->getOwnerKeyName()];
            } elseif ($relation instanceof HasOne || $relation instanceof HasMany) {
                $found[] = ['method' => $method, 'type' => $relation instanceof HasOne ? 'hasOne' : 'hasMany', 'related' => $relation->getRelated()::class,
                    'foreign_key' => $relation->getForeignKeyName(), 'other_key' => $relation->getLocalKeyName()];
            }
        }

        return $found;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    private function toEdge(Model $model, mixed $relation): ?InferredRelationship
    {
        if ($relation instanceof BelongsTo) {
            return new InferredRelationship(
                $model->getTable(), $relation->getForeignKeyName(),
                $relation->getRelated()->getTable(), $relation->getOwnerKeyName(),
                Cardinality::ManyToOne, InferredRelationship::ORIGIN_ELOQUENT, 0.9,
            );
        }

        // HasOne / HasMany are stored from the child side, like every edge.
        if ($relation instanceof HasOne || $relation instanceof HasMany) {
            return new InferredRelationship(
                $relation->getRelated()->getTable(), $relation->getForeignKeyName(),
                $model->getTable(), $relation->getLocalKeyName(),
                $relation instanceof HasOne ? Cardinality::OneToOne : Cardinality::ManyToOne,
                InferredRelationship::ORIGIN_ELOQUENT, 0.9,
            );
        }

        return null;
    }

    /** @return list<string> */
    private function relationMethods(string $class): array
    {
        $methods = [];
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()
                || $method->getNumberOfRequiredParameters() > 0
                || str_starts_with($method->getDeclaringClass()->getName(), 'Illuminate\\')
                || ! $method->getFileName()
            ) {
                continue;
            }

            $lines = @file($method->getFileName());
            if ($lines === false) {
                continue;
            }

            $source = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
            if (preg_match(self::RELATION_CALL, $source)) {
                $methods[] = $method->getName();
            }
        }

        return $methods;
    }

    /**
     * @param  array<string, bool>  $paths
     * @return list<class-string<Model>>
     */
    private function modelClasses(array $paths): array
    {
        $classes = [];

        foreach ($paths as $path => $recursive) {
            $directory = str_starts_with($path, '/') ? $path : base_path($path);
            if (! is_dir($directory)) {
                continue;
            }

            foreach ($this->phpFiles($directory, (bool) $recursive) as $file) {
                $class = $this->classInFile($file);
                if ($class === null) {
                    continue;
                }

                try {
                    if (! class_exists($class) || ! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
                        continue;
                    }
                } catch (Throwable $e) {
                    $this->warnings[] = "Skipped {$class}: ".$e->getMessage();
                    continue;
                }

                $classes[$class] = true;
            }
        }

        return array_keys($classes);
    }

    /** @return iterable<string> */
    private function phpFiles(string $directory, bool $recursive): iterable
    {
        if (! $recursive) {
            yield from glob(rtrim($directory, '/').'/*.php') ?: [];

            return;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }

    /** Reads namespace and class name without loading the file. */
    private function classInFile(string $file): ?string
    {
        $source = @file_get_contents($file, length: 8192);
        if ($source === false
            || ! preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)/m', $source, $class)
        ) {
            return null;
        }

        $namespace = preg_match('/^\s*namespace\s+([^;\s]+)\s*;/m', $source, $ns) ? $ns[1].'\\' : '';

        return $namespace.$class[1];
    }
}
