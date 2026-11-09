<?php

namespace Abdalla\ReportGenerator\Topics;

use Illuminate\Contracts\Container\Container;

/**
 * The topics the host application declared: registered one by one
 * (ReportGenerator::topics([...])) and/or discovered from folders
 * (config report-generator.topics.paths).
 */
final class TopicRegistry
{
    /** @var list<class-string<Topic>|Topic> */
    private array $pending = [];

    /** @var array<string, Topic>|null by key */
    private ?array $resolved = null;

    public function __construct(private readonly Container $container)
    {
    }

    /** @param  iterable<class-string<Topic>|Topic>  $topics */
    public function register(iterable $topics): void
    {
        foreach ($topics as $topic) {
            if (is_string($topic) && ! is_subclass_of($topic, Topic::class)) {
                throw new InvalidTopicException("[{$topic}] must extend ".Topic::class.'.');
            }
            $this->pending[] = $topic;
        }
        $this->resolved = null;
    }

    /** @return array<string, Topic> */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $topics = [];
        $classes = [];
        foreach ([...$this->discovered(), ...$this->pending] as $topic) {
            $instance = is_string($topic) ? $this->container->make($topic) : $topic;
            $class = $instance::class;
            if (isset($classes[$class])) {
                continue; // registered and discovered: same topic
            }
            $key = $instance->key();
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
                throw new InvalidTopicException("Topic key [{$key}] of {$class} must be lowercase letters, digits and underscores.");
            }
            if (isset($topics[$key])) {
                throw new InvalidTopicException("Two topics use the key [{$key}]: ".$topics[$key]::class." and {$class}. Set \$key on one of them.");
            }
            $topics[$key] = $instance;
            $classes[$class] = $key;
        }

        foreach ($topics as $topic) {
            $names = [];
            foreach ($topic->relations() as $relation) {
                if (isset($names[$relation->name])) {
                    throw new InvalidTopicException($topic::class." declares the relation [{$relation->name}] twice.");
                }
                $names[$relation->name] = true;
                if (! $relation->isThrough() && ! isset($classes[$relation->topic])) {
                    throw new InvalidTopicException(
                        $topic::class." relation [{$relation->name}] points to {$relation->topic}, which is not a registered topic."
                    );
                }
            }
        }

        $this->resolved = $topics;
        try {
            foreach ($topics as $topic) {
                foreach ($topic->relations() as $relation) {
                    if ($relation->isThrough()) {
                        $this->hops($topic, $relation);
                    }
                }
            }
        } catch (InvalidTopicException $e) {
            $this->resolved = null;
            throw $e;
        }

        return $topics;
    }

    /** A relation of a topic by name, or null. */
    public function relation(Topic $topic, string $name): ?Relation
    {
        foreach ($topic->relations() as $relation) {
            if ($relation->name === $name) {
                return $relation;
            }
        }

        return null;
    }

    /** The topic a relation leads to (for a through relation: the far end). */
    public function target(Topic $from, Relation $relation): Topic
    {
        $hops = $this->hops($from, $relation);

        return end($hops)[2];
    }

    /**
     * The plain relations a relation walks: one hop, or two for a through
     * relation, each as [from topic, relation, to topic].
     *
     * @return list<array{0: Topic, 1: Relation, 2: Topic}>
     */
    public function hops(Topic $from, Relation $relation): array
    {
        if (! $relation->isThrough()) {
            return [[$from, $relation, $this->byClass($relation->topic)]];
        }

        $via = $this->relation($from, (string) $relation->via);
        if ($via === null || $via->isThrough()) {
            throw new InvalidTopicException($from::class." relation [{$relation->name}] goes through [{$relation->via}], which is not a plain relation of the topic.");
        }
        $middle = $this->byClass($via->topic);
        $then = $this->relation($middle, (string) $relation->then);
        if ($then === null || $then->isThrough()) {
            throw new InvalidTopicException($from::class." relation [{$relation->name}] continues with [{$relation->then}], which is not a plain relation of ".$middle::class.'.');
        }

        return [[$from, $via, $middle], [$middle, $then, $this->byClass($then->topic)]];
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function get(string $key): Topic
    {
        return $this->all()[$key] ?? throw new InvalidTopicException("No topic with key [{$key}].");
    }

    /** @param  class-string<Topic>  $class */
    public function byClass(string $class): Topic
    {
        foreach ($this->all() as $topic) {
            if ($topic::class === $class) {
                return $topic;
            }
        }

        throw new InvalidTopicException("{$class} is not a registered topic.");
    }

    public function isEmpty(): bool
    {
        return $this->all() === [];
    }

    public function forget(): void
    {
        $this->resolved = null;
    }

    /** @return list<class-string<Topic>> */
    private function discovered(): array
    {
        $classes = [];
        foreach ((array) config('report-generator.topics.paths', []) as $path => $namespace) {
            $directory = str_starts_with($path, '/') ? $path : base_path($path);
            if (! is_dir($directory)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen(rtrim($directory, '/')) + 1, -4);
                $class = rtrim($namespace, '\\').'\\'.str_replace('/', '\\', $relative);
                if (class_exists($class) && is_subclass_of($class, Topic::class) && ! (new \ReflectionClass($class))->isAbstract()) {
                    $classes[] = $class;
                }
            }
        }
        sort($classes);

        return $classes;
    }
}
