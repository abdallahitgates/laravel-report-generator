<?php

namespace Abdalla\ReportGenerator\Query;

use Abdalla\ReportGenerator\Enums\Ability;
use Abdalla\ReportGenerator\Enums\Aggregation;
use Abdalla\ReportGenerator\Enums\DataType;
use Abdalla\ReportGenerator\Enums\FieldRole;
use Abdalla\ReportGenerator\Query\Compiler\CompiledQuery;
use Abdalla\ReportGenerator\Query\Compiler\DialectFactory;
use Abdalla\ReportGenerator\Query\Compiler\QueryCompiler;
use Abdalla\ReportGenerator\Query\Definition\DefinitionParser;
use Abdalla\ReportGenerator\Query\Execution\ConcurrencyLimiter;
use Abdalla\ReportGenerator\Query\Execution\DatasetAccess;
use Abdalla\ReportGenerator\Query\Execution\QueryCanceller;
use Abdalla\ReportGenerator\Query\Planning\QueryPlanner;
use Abdalla\ReportGenerator\ReportGenerator;
use Abdalla\ReportGenerator\Query\Definition\DatePresets;
use Abdalla\ReportGenerator\Security\PolicyContext;
use Abdalla\ReportGenerator\Security\ScopeContext;
use Abdalla\ReportGenerator\Semantic\Published\SemanticModel;
use Abdalla\ReportGenerator\Topics\Relation;
use Abdalla\ReportGenerator\Topics\Topic;
use Abdalla\ReportGenerator\Topics\TopicModelBuilder;
use Abdalla\ReportGenerator\Topics\TopicRegistry;
use Abdalla\ReportGenerator\Security\PolicyRegistry;
use Abdalla\ReportGenerator\Semantic\Published\PublishedDataset;
use Abdalla\ReportGenerator\Semantic\Published\SemanticModelRepository;
use Abdalla\ReportGenerator\Support\Tables;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException as DatabaseQueryException;
use Illuminate\Support\Facades\Log;

/**
 * Entry point for running reports: definition in, rows out.
 *
 * Every run is checked against the published model and the user's dataset
 * access, compiled to one statement, served from the result cache when the
 * exact same statement (including the user's row policies) ran recently,
 * and otherwise executed under a concurrency slot and a timeout.
 */
final class QueryEngine
{
    private Repository $cache;

    public function __construct(
        private readonly DefinitionParser $parser,
        private readonly SemanticModelRepository $models,
        private readonly QueryPlanner $planner,
        private readonly QueryCompiler $compiler,
        private readonly DialectFactory $dialects,
        private readonly ReportGenerator $generator,
        private readonly PolicyRegistry $policies,
        private readonly DatabaseManager $db,
        CacheManager $caches,
        private readonly QueryCanceller $canceller,
        private readonly TopicRegistry $topics,
        private readonly \Abdalla\ReportGenerator\Combine\CombineRegistry $combinable,
    ) {
        $this->cache = $caches->store(config('report-generator.cache.store'));
    }

    /** Combined reports (columns of several existing reports) run here instead of in SQL. */
    public function combiner(): \Abdalla\ReportGenerator\Combine\Combiner
    {
        return new \Abdalla\ReportGenerator\Combine\Combiner($this->combinable, $this->generator, $this->cache);
    }

    /** Throws when the definition cannot run for this user (missing parameters aside). */
    public function check(array $definition, ?Authenticatable $user): void
    {
        if (\Abdalla\ReportGenerator\Combine\Combiner::isCombined($definition)) {
            $this->combiner()->check($definition, $user);

            return;
        }

        try {
            $this->compile($definition, [], $user);
        } catch (QueryException $e) {
            if ($e->errorCode !== 'MISSING_PARAMETER') {
                throw $e;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $definition  report definition JSON
     * @param  array<string, mixed>  $parameters  values for the report's parameters
     * @param  array{columns: list<string>, keys: list<mixed>}|null  $groups  drill-down state
     */
    public function compile(array $definition, array $parameters, ?Authenticatable $user, ?array $groups = null, ?int $timeoutMs = null): CompiledQuery
    {
        $parsed = $this->parser->parse($definition, $parameters);

        if ($groups !== null && $groups['columns'] !== []) {
            foreach ($groups['columns'] as $i => $alias) {
                $field = $parsed->field($alias);
                if ($field === null || $field->isMeasure() || $field->isFormula()) {
                    throw new QueryException('INVALID_GROUP', 'Rows can only be grouped by the report\'s plain fields.', "groups.columns.{$i}");
                }
            }
            if (count($groups['keys']) > count($groups['columns'])) {
                throw new QueryException('INVALID_GROUP', 'More group keys than group columns.', 'groups.keys');
            }
            $parsed = $parsed->atGroupLevel($groups['columns'], $groups['keys']);
        }

        $model = $this->models->current($user);
        $access = new DatasetAccess($this->generator, $this->policies, $user, $model);
        $plan = $this->planner->plan($parsed, $model, $access->asserter());

        $connection = $this->dataConnection();

        return $this->compiler->compile(
            $plan,
            $connection,
            $this->dialects->for($connection),
            $user,
            $timeoutMs ?? (int) config('report-generator.limits.preview_timeout_ms', 15000),
            $access->viewAll(),
        );
    }

    /**
     * One page of a report.
     *
     * @return array{columns: list<array>, rows: list<array>, page: array, meta: array}
     */
    public function rows(
        array $definition,
        array $parameters,
        ?Authenticatable $user,
        int $offset = 0,
        ?int $size = null,
        ?array $groups = null,
        ?string $queryId = null,
    ): array {
        $size = max(1, min($size ?? (int) config('report-generator.limits.preview_page_size', 200), (int) config('report-generator.limits.max_page_size', 5000)));
        $offset = max(0, $offset);
        if (\Abdalla\ReportGenerator\Combine\Combiner::isCombined($definition)) {
            return $this->combiner()->page($definition, $parameters, $user, $offset, $size);
        }
        $timeoutMs = (int) config('report-generator.limits.preview_timeout_ms', 15000);

        $compiled = $this->compile($definition, $parameters, $user, $groups, $timeoutMs);
        $query = (clone $compiled->query)->offset($offset)->limit($size + 1);
        $sql = $query->toSql();
        $bindings = $query->getBindings();
        $version = $this->models->current($user)->version;
        $cacheKey = 'report-generator:result:'.hash('sha256', implode('|', [$version, $compiled->connection->getName(), $sql, json_encode($bindings), $this->policies->fingerprint()]));

        $started = microtime(true);
        $cached = $this->cache->get($cacheKey);
        $estimate = null;

        if ($cached === null) {
            $estimate = $this->estimate($compiled, $sql, $bindings);
            $rows = (new ConcurrencyLimiter($this->cache))->run(
                $user?->getAuthIdentifier(),
                (int) ceil($timeoutMs / 1000) + 5,
                fn () => $this->execute($compiled, $query, $timeoutMs, $user, $queryId),
            );
            $rows = $this->normalizeRows($rows, $compiled->columns);
            $this->cache->put($cacheKey, ['rows' => $rows, 'estimate' => $estimate], (int) config('report-generator.cache.ttl', 600));
        } else {
            $rows = $cached['rows'];
            $estimate = $cached['estimate'];
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $hasMore = count($rows) > $size;
        $rows = array_slice($rows, 0, $size);

        $this->log($compiled, $user, $sql, $estimate, count($rows), $durationMs, $cached !== null);

        return [
            'columns' => $compiled->columns,
            'rows' => $rows,
            'page' => ['offset' => $offset, 'size' => $size, 'has_more' => $hasMore],
            'meta' => array_filter([
                'mode' => $compiled->mode,
                'duration_ms' => $durationMs,
                'cached' => $cached !== null,
                'estimated_rows' => $estimate,
                'heavy' => $estimate !== null && $estimate > (int) config('report-generator.limits.async_above_est_rows', 2_000_000),
                'model_version' => $version,
                // Only people who manage the model see the generated SQL.
                'sql' => $this->generator->allows($user, Ability::ManageModel) ? $sql : null,
            ], fn ($v) => $v !== null),
        ];
    }

    /**
     * How many rows the whole report has, and the grand total of every
     * column that adds up, in one query over the report's result.
     *
     * @return array{rows: int, totals: array<string, int|float|null>, duration_ms: int, cached: bool}
     */
    public function summary(array $definition, array $parameters, ?Authenticatable $user): array
    {
        if (\Abdalla\ReportGenerator\Combine\Combiner::isCombined($definition)) {
            return $this->combiner()->summary($definition, $user);
        }

        $timeoutMs = (int) config('report-generator.limits.preview_timeout_ms', 15000);
        $compiled = $this->compile($definition, $parameters, $user, null, $timeoutMs);
        $grammar = $compiled->connection->getQueryGrammar();

        $inner = clone $compiled->query;
        $inner->orders = null;
        $inner->unionOrders = null;
        $inner->limit = $inner->offset = null;

        $selects = ['count(*) as '.$grammar->wrap('__rows')];
        $totals = [];
        foreach ($compiled->columns as $column) {
            if (($column['total'] ?? null) !== null) {
                $totals[$column['alias']] = $column;
                $selects[] = "{$column['total']}(".$grammar->wrap('rg_s.'.$column['alias']).') as '.$grammar->wrap($column['alias']);
            }
        }
        $query = $compiled->connection->query()
            ->fromSub($inner, 'rg_s')
            ->selectRaw($compiled->dialect->timeoutHint($timeoutMs).implode(', ', $selects));
        // ROLLUP subtotal rows would be counted twice.
        if (collect($compiled->columns)->contains('alias', '__subtotal')) {
            $query->where('rg_s.__subtotal', 0);
        }

        $sql = $query->toSql();
        $bindings = $query->getBindings();
        $version = $this->models->current($user)->version;
        $cacheKey = 'report-generator:summary:'.hash('sha256', implode('|', [$version, $compiled->connection->getName(), $sql, json_encode($bindings), $this->policies->fingerprint()]));

        $started = microtime(true);
        $cached = $this->cache->get($cacheKey);
        if ($cached === null) {
            $rows = (new ConcurrencyLimiter($this->cache))->run(
                $user?->getAuthIdentifier(),
                (int) ceil($timeoutMs / 1000) + 5,
                fn () => $this->execute($compiled, $query, $timeoutMs, $user, null),
            );
            $row = $this->normalizeRows($rows, [['alias' => '__rows', 'type' => 'integer', 'kind' => 'measure'], ...array_values($totals)])[0] ?? [];
            $cached = ['rows' => (int) ($row['__rows'] ?? 0), 'totals' => array_intersect_key($row, $totals)];
            $this->cache->put($cacheKey, $cached, (int) config('report-generator.cache.ttl', 600));
            $hit = false;
        } else {
            $hit = true;
        }

        return $cached + ['duration_ms' => (int) round((microtime(true) - $started) * 1000), 'cached' => $hit];
    }

    /**
     * Choices for a report filter (lines, divisions, employees): id and label
     * from the topic configured in report-generator.context, limited to what
     * the user may see, the chosen lines and the period.
     *
     * @param  list<int|string>  $lines
     * @return list<array{id: int|string, label: string}>
     */
    public function contextOptions(string $kind, ?string $search, array $lines, string|array|null $period, ?Authenticatable $user, int $limit = 200): array
    {
        $config = config("report-generator.context.{$kind}");
        if (! is_array($config) || empty($config['topic'])) {
            return [];
        }

        $model = $this->models->current($user);
        $dataset = $model->dataset($config['topic']);
        $access = new DatasetAccess($this->generator, $this->policies, $user, $model);
        if ($dataset === null || ! $access->allows($dataset->key, true)) {
            return [];
        }

        [$from, $to] = match (true) {
            is_array($period) && count($period) === 2 => [substr((string) $period[0], 0, 10).' 00:00:00', substr((string) $period[1], 0, 10).' 23:59:59'],
            is_string($period) && $period !== '' => DatePresets::resolve($period) ?? [null, null],
            default => [null, null],
        };
        $connection = $this->dataConnection();
        $label = $config['label'] ?? 'name';
        $id = $dataset->primaryKey[0] ?? 'id';

        $query = $connection->query()
            ->from("{$dataset->sourceName} as t0")
            ->select(["t0.{$id} as id", "t0.{$label} as label"])
            ->orderBy("t0.{$label}")
            ->limit(max(1, min($limit, 500)));
        $dataset->constrain($query, 't0');

        if ($lines !== [] && $kind !== 'lines' && $dataset->lineColumn) {
            $query->whereIn("t0.{$dataset->lineColumn}", $lines);
        }
        if ($from !== null && $dataset->validFromColumn) {
            $query->where("t0.{$dataset->validFromColumn}", '<=', $to);
            if ($dataset->validToColumn) {
                $query->where(fn ($q) => $q->whereNull("t0.{$dataset->validToColumn}")->orWhere("t0.{$dataset->validToColumn}", '>=', $from));
            }
        }
        if ($search !== null && $search !== '') {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
            $query->whereRaw($connection->getQueryGrammar()->wrap("t0.{$label}").' '.$this->dialects->for($connection)->likeOperator()." ? ESCAPE '!'", ['%'.$escaped.'%']);
        }

        $this->policies->apply($query, new PolicyContext($dataset->key, 't0', $user));
        if (! $access->viewAll()) {
            $this->policies->applyScope($query, new ScopeContext($dataset, 't0', $user, $from, $to, $lines));
        }

        return array_map(fn ($r) => ['id' => is_numeric($r->id) ? (int) $r->id : $r->id, 'label' => (string) $r->label], $query->get()->all());
    }

    /**
     * Every row of a report, streamed: constant memory whatever the size
     * (unbuffered on MySQL, a server-side cursor on PostgreSQL).
     *
     * @return array{columns: list<array>, rows: \Generator<int, array<string, mixed>>}
     */
    public function stream(array $definition, array $parameters, ?Authenticatable $user): array
    {
        if (\Abdalla\ReportGenerator\Combine\Combiner::isCombined($definition)) {
            return $this->combiner()->stream($definition, $user);
        }

        $timeoutMs = (int) config('report-generator.limits.export_timeout_ms', 900000);
        $compiled = $this->compile($definition, $parameters, $user, null, $timeoutMs);
        $columns = array_values(array_filter($compiled->columns, fn ($c) => $c['kind'] !== 'meta'));
        $chunk = max(100, (int) config('report-generator.exports.chunk', 5000));

        $rows = (function () use ($compiled, $columns, $timeoutMs, $chunk, $user) {
            $started = microtime(true);
            $count = 0;
            foreach ((new \Abdalla\ReportGenerator\Export\ResultStreamer())->stream($compiled, $timeoutMs, $chunk) as $batch) {
                foreach ($this->normalizeRows($batch, $columns) as $row) {
                    $count++;
                    yield $row;
                }
            }
            $this->log($compiled, $user, $compiled->sql(), null, $count, (int) round((microtime(true) - $started) * 1000), false);
        })();

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * Check a definition without running it: errors, output columns, mode,
     * and (when enabled) how many rows the database expects to read.
     */
    public function validate(array $definition, array $parameters, ?Authenticatable $user): array
    {
        if (\Abdalla\ReportGenerator\Combine\Combiner::isCombined($definition)) {
            $this->combiner()->check($definition, $user);

            return ['mode' => 'combined'];
        }

        $compiled = $this->compile($definition, $parameters, $user);

        return array_filter([
            'columns' => $compiled->columns,
            'mode' => $compiled->mode,
            'estimated_rows' => $this->estimate($compiled, $compiled->sql(), $compiled->bindings()),
            'sql' => $this->generator->allows($user, Ability::ManageModel) ? $compiled->sql() : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Distinct values of a field for filter pickers, searched on the server
     * so a filter on a million-row column never loads the whole list.
     *
     * @return list<mixed>
     */
    public function values(string $ref, ?string $search, int $limit, ?Authenticatable $user): array
    {
        if (! preg_match('/^([a-z_][a-z0-9_]*)\.([a-z_][a-z0-9_]*)$/i', $ref, $m)) {
            throw new QueryException('INVALID_REF', 'Refer to fields as "dataset.field".', 'field');
        }

        $model = $this->models->current($user);
        $access = new DatasetAccess($this->generator, $this->policies, $user, $model);
        $access->asserter()($m[1]);
        $dataset = $model->dataset($m[1]) ?? throw new QueryException('UNKNOWN_DATASET', "There is no dataset \"{$m[1]}\".", 'field');
        $field = $dataset->field($m[2]) ?? throw new QueryException('UNKNOWN_FIELD', "{$dataset->label} has no field \"{$m[2]}\".", 'field');

        $connection = $this->dataConnection();
        $dialect = $this->dialects->for($connection);
        $column = $connection->getQueryGrammar()->wrap("t0.{$field->column}");

        $query = $connection->query()
            ->from("{$dataset->sourceName} as t0")
            ->selectRaw($dialect->timeoutHint(5000)."{$column} as value")
            ->distinct()
            ->whereNotNull("t0.{$field->column}")
            ->orderByRaw($connection->getQueryGrammar()->wrap('value').' asc')
            ->limit(max(1, min($limit, 200)));

        $dataset->constrain($query, 't0');
        $this->policies->apply($query, new PolicyContext($dataset->key, 't0', $user));
        if (! $access->viewAll()) {
            // A picker never offers values from rows the user may not see.
            $this->policies->applyScope($query, new ScopeContext($dataset, 't0', $user));
        }

        if ($search !== null && $search !== '' && in_array($field->type, [DataType::String, DataType::Text], true)) {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
            $query->whereRaw("{$column} {$dialect->likeOperator()} ? ESCAPE '!'", ['%'.$escaped.'%']);
        }

        $key = 'report-generator:values:'.hash('sha256', $query->toSql().json_encode($query->getBindings()).$model->version);

        return $this->cache->remember($key, 300, fn () => $dialect->withTimeout($connection, 5000, fn () => array_map(
            fn ($row) => $row->value,
            $query->get()->all(),
        )));
    }

    /**
     * What the builder may offer. Without a root: the topics a report can
     * start from, grouped into tabs. With a root: the topic and every
     * relation it declares, by name, as a tree (each node carries the join
     * path the builder sends back, so a report never has to guess a path).
     * "datasets" repeats the tree as a flat list for older clients.
     */
    public function catalog(?string $root, ?Authenticatable $user): array
    {
        $model = $this->models->current($user);
        $access = new DatasetAccess($this->generator, $this->policies, $user, $model);

        if ($root === null) {
            $allowed = array_values(array_filter($model->datasets, fn (PublishedDataset $d) => $access->allows($d->key, true)));
            $groups = [];
            foreach ($allowed as $dataset) {
                $groups[$dataset->group ?? $dataset->label][] = $this->topicSummary($dataset);
            }

            return [
                'model_version' => $model->version,
                'groups' => array_map(fn ($name, $topics) => ['name' => $name, 'topics' => $topics], array_keys($groups), $groups),
                'datasets' => array_map(fn (PublishedDataset $d) => $this->topicSummary($d), $allowed),
            ];
        }

        $access->asserter()($root, true);
        $model->dataset($root) ?? throw new QueryException('UNKNOWN_DATASET', "There is no topic \"{$root}\".", 'root');

        $tree = $this->topicNode($this->topics->get($root), [], [], 0, false, $access, $model, null);

        $flat = [];
        $walk = function (array $node, array $labels) use (&$walk, &$flat) {
            $labels = [...$labels, $node['label']];
            $entry = &$flat[$node['topic']];
            $entry ??= ['key' => $node['topic'], 'label' => $node['topic_label'], 'row_estimate' => null, 'default_time_field' => $node['default_time_field'], 'paths' => [], 'fields' => $node['fields']];
            if ($node['path'] !== []) {
                $entry['paths'][] = ['path' => $node['path'], 'description' => implode(' → ', $labels), 'multiplies_rows' => $node['multiplies_rows']];
            }
            unset($entry);
            foreach ($node['relations'] as $child) {
                $walk($child, $labels);
            }
        };
        $walk($tree, []);

        return ['model_version' => $model->version, 'root' => $root, 'tree' => $tree, 'datasets' => array_values($flat)];
    }

    /**
     * Every topic with all its fields and relations, for the admin Topics page.
     */
    public function topicsOverview(?Authenticatable $user): array
    {
        $model = $this->models->current($user);
        $groups = [];
        foreach ($this->topics->all() as $key => $topic) {
            $dataset = $model->dataset($key);
            $groups[$topic->group()][] = $this->topicSummary($dataset) + [
                'table' => $topic->table(),
                'class' => $topic::class,
                'permission' => $topic->permission,
                'public' => $topic->public,
                'lookup' => $topic->lookup,
                'scope_columns' => (object) $topic->scopeColumns,
                'conditions' => (object) $topic->conditions(),
                'columns' => [
                    'period' => $topic->validFromColumn ? trim($topic->validFromColumn.' – '.$topic->validToColumn, ' –') : $topic->dateColumn,
                    'line' => $topic->lineColumn,
                    'division' => $topic->divisionColumn,
                    'employee' => $topic->employeeColumn,
                ],
                'fields' => $this->fieldList($dataset),
                'relations' => array_map(fn (Relation $r) => [
                    'name' => $r->name,
                    'label' => $r->label,
                    'type' => $r->type,
                    'topic' => $this->topics->byClass($r->topic)->key(),
                    'topic_label' => $this->topics->byClass($r->topic)->label(),
                    'foreign_key' => $r->foreignKey,
                ], $topic->relations()),
            ];
        }

        return [
            'model_version' => $model->version,
            'groups' => array_map(fn ($name, $topics) => ['name' => $name, 'topics' => $topics], array_keys($groups), $groups),
        ];
    }

    public function cancel(string $queryId, ?Authenticatable $user): bool
    {
        $connection = $this->dataConnection();

        return $this->canceller->cancel($queryId, $user?->getAuthIdentifier(), $this->dialects->for($connection));
    }

    // ----------------------------------------------------------------------

    private function topicSummary(PublishedDataset $dataset): array
    {
        return [
            'key' => $dataset->key,
            'label' => $dataset->label,
            'group' => $dataset->group,
            'description' => $dataset->description,
            'row_estimate' => $dataset->rowEstimate,
            'fields_count' => count(array_filter($dataset->fields, fn ($f) => ! $f->hidden)),
            'default_time_field' => $dataset->defaultTimeField,
            'filters' => [
                'period' => $dataset->dateColumn !== null || $dataset->validFromColumn !== null,
                'line' => $dataset->lineColumn !== null,
                'division' => $dataset->divisionColumn !== null,
                'employee' => $dataset->employeeColumn !== null,
            ],
        ];
    }

    /**
     * @param  list<int>  $path  signed relationship ids from the root
     * @param  list<string>  $ancestors  topic keys above this node (no cycles)
     */
    private function topicNode(Topic $topic, array $path, array $ancestors, int $depth, bool $multiplies, DatasetAccess $access, SemanticModel $model, ?Relation $via): array
    {
        $key = $topic->key();
        $dataset = $model->dataset($key);
        $children = [];

        if ($depth < (int) config('report-generator.topics.max_depth', 3)) {
            foreach ($topic->relations() as $relation) {
                $target = $this->topics->byClass($relation->topic);
                if (in_array($target->key(), [...$ancestors, $key], true) || ! $access->allows($target->key())) {
                    continue;
                }
                [, $signed] = TopicModelBuilder::step($topic, $relation, $target);
                $children[] = $this->topicNode(
                    $target,
                    [...$path, $signed],
                    [...$ancestors, $key],
                    $depth + 1,
                    $multiplies || $relation->type === Relation::HAS_MANY,
                    $access,
                    $model,
                    $relation,
                );
            }
        }

        return [
            'name' => $via?->name ?? $key,
            'label' => $via?->label ?? $topic->label(),
            'topic' => $key,
            'topic_label' => $topic->label(),
            'path' => $path,
            'multiplies_rows' => $multiplies,
            'default_time_field' => $dataset->defaultTimeField,
            'fields' => $this->fieldList($dataset),
            'relations' => $children,
        ];
    }

    private function fieldList(PublishedDataset $dataset): array
    {
        $fields = [[
            'key' => QueryPlanner::ROW_COUNT,
            'label' => $dataset->countLabel ?? "Number of {$dataset->label}",
            'type' => DataType::Integer->value,
            'role' => FieldRole::Measure->value,
            'aggregations' => [Aggregation::Count->value],
            'format' => '#,##0',
            'hidden' => false,
        ]];
        foreach ($dataset->fields as $field) {
            $fields[] = $field->toArray() + ['hidden' => $field->hidden];
        }

        return $fields;
    }

    private function execute(CompiledQuery $compiled, $query, int $timeoutMs, ?Authenticatable $user, ?string $queryId): array
    {
        $connection = $compiled->connection;
        $dialect = $compiled->dialect;

        try {
            return $dialect->withTimeout($connection, $timeoutMs, function () use ($connection, $dialect, $query, $timeoutMs, $user, $queryId) {
                if ($queryId !== null && ($session = $dialect->sessionId($connection)) !== null) {
                    $this->canceller->register($queryId, $user?->getAuthIdentifier(), $connection->getName(), $session, (int) ceil($timeoutMs / 1000) + 30);
                }

                try {
                    return array_map(fn ($row) => (array) $row, $query->get()->all());
                } finally {
                    if ($queryId !== null) {
                        $this->canceller->forget($queryId);
                    }
                }
            });
        } catch (DatabaseQueryException $e) {
            throw match ($dialect->interruption($e)) {
                'timeout' => new QueryException('TIMEOUT', 'The report took longer than '.round($timeoutMs / 1000).' seconds and was stopped. Add a filter (a shorter date range, one line) or export it instead.', status: 422),
                'cancelled' => new QueryException('CANCELLED', 'The report was stopped.', status: 409),
                default => $this->databaseError($e),
            };
        }
    }

    private function databaseError(DatabaseQueryException $e): QueryException
    {
        Log::warning('Report generator query failed', ['error' => $e->getMessage()]);

        return new QueryException('DATABASE_ERROR', 'The database could not run this report: '.($e->getPrevious()?->getMessage() ?? $e->getMessage()), status: 500);
    }

    private function estimate(CompiledQuery $compiled, string $sql, array $bindings): ?int
    {
        if (! config('report-generator.limits.explain', true)) {
            return null;
        }

        try {
            $estimate = $compiled->dialect->estimateRows($compiled->connection, $sql, $bindings);
        } catch (\Throwable) {
            return null; // an estimate is a hint; never fail a report over it
        }

        $limit = config('report-generator.limits.refuse_above_est_rows');
        if ($limit !== null && $estimate !== null && $estimate > (int) $limit) {
            throw new QueryException('TOO_HEAVY', 'This report would read about '.number_format($estimate).' rows. Add a filter (a shorter date range, one line) or export it instead.', details: ['estimated_rows' => $estimate]);
        }

        return $estimate;
    }

    /**
     * Databases return DECIMAL and big SUMs as strings; send numbers as
     * numbers so the grid sorts and formats them correctly.
     */
    private function normalizeRows(array $rows, array $columns): array
    {
        $numeric = [];
        foreach ($columns as $column) {
            if (in_array($column['type'], ['integer', 'decimal'], true) || $column['kind'] === 'measure') {
                $numeric[$column['alias']] = $column['type'];
            }
        }

        foreach ($rows as &$row) {
            foreach ($numeric as $alias => $type) {
                $value = $row[$alias] ?? null;
                if ($value === null || ! is_numeric($value)) {
                    continue;
                }
                // Decimals are always floats and counts always integers, whatever the driver returned.
                $row[$alias] = $type === 'integer' && preg_match('/^-?\d+$/', (string) $value) && strlen(ltrim((string) $value, '-')) < 19
                    ? (int) $value
                    : (float) $value;
            }
        }

        return $rows;
    }

    private function log(CompiledQuery $compiled, ?Authenticatable $user, string $sql, ?int $estimate, int $rows, int $durationMs, bool $cacheHit): void
    {
        if (! config('report-generator.query_log', true)) {
            return;
        }

        try {
            app('db')->connection(Tables::connection())->table(Tables::name(Tables::QUERY_LOG))->insert([
                'user_id' => is_numeric($user?->getAuthIdentifier()) ? $user->getAuthIdentifier() : null,
                'sql_hash' => hash('sha256', $sql),
                'datasets' => json_encode($compiled->datasets),
                'filtered_fields' => json_encode($compiled->filteredFields),
                'estimated_rows' => $estimate,
                'row_count' => $rows,
                'duration_ms' => $durationMs,
                'cache_hit' => $cacheHit,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // The log is diagnostics; a report never fails because of it.
        }
    }

    private function dataConnection(): Connection
    {
        return $this->db->connection(config('report-generator.data_connection'));
    }
}
