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
            foreach ($topic->relations() as $relation) {
                if (! isset($classes[$relation->topic])) {
                    throw new InvalidTopicException(
                        $topic::class." relation [{$relation->name}] points to {$relation->topic}, which is not a registered topic."
                    );
                }
            }
        }

        return $this->resolved = $topics;
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
