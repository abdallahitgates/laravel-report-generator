<?php

namespace Abdalla\ReportGenerator\Console;

use Abdalla\ReportGenerator\Enums\DataType;
use Abdalla\ReportGenerator\Enums\FieldRole;
use Abdalla\ReportGenerator\Schema\FieldClassifier;
use Abdalla\ReportGenerator\Schema\FieldDraft;
use Abdalla\ReportGenerator\Schema\Inspectors\InspectorFactory;
use Abdalla\ReportGenerator\Schema\Relationships\EloquentRelationshipScanner;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Drafts a Topic class from an Eloquent model: its columns become fields
 * with labels and sensible types, its belongsTo / hasOne / hasMany
 * relations become topic relations. The database is read once, here; the
 * generated class is the source of truth from then on.
 */
class MakeTopicCommand extends Command
{
    protected $signature = 'report-generator:make-topic
        {name : Topic name, e.g. ActualVisits}
        {--model= : Eloquent model class, e.g. "App\ActualVisit"}
        {--group= : Tab the topic is listed under, e.g. Structure}
        {--force : Overwrite an existing file}';

    protected $description = 'Draft a report topic class from an Eloquent model';

    private const DIVISION_COLUMNS = ['div_id', 'line_division_id', 'division_id'];

    public function handle(InspectorFactory $inspectors, DatabaseManager $db, EloquentRelationshipScanner $scanner): int
    {
        $modelClass = ltrim((string) $this->option('model'), '\\');
        if ($modelClass === '' || ! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            $this->components->error('Pass the Eloquent model with --model, e.g. --model="App\ActualVisit".');

            return self::FAILURE;
        }

        [$directory, $namespace] = $this->destination();
        $class = Str::studly($this->argument('name'));
        $class = str_ends_with($class, 'Topic') ? $class : $class.'Topic';
        $file = rtrim($directory, '/')."/{$class}.php";

        if (file_exists($file) && ! $this->option('force')) {
            $this->components->error("{$file} already exists. Use --force to overwrite it.");

            return self::FAILURE;
        }

        /** @var Model $model */
        $model = new $modelClass();
        $connection = $db->connection($model->getConnectionName());
        $table = $inspectors->for($connection)->inspect($connection)->table($model->getTable());
        if ($table === null) {
            $this->components->error("Table [{$model->getTable()}] was not found on the [{$connection->getName()}] connection.");

            return self::FAILURE;
        }

        $classifier = new FieldClassifier((array) config('report-generator.schema.sensitive_columns', []));
        $draft = $classifier->dataset($table, Str::snake(Str::beforeLast($class, 'Topic')));
        $label = Str::plural(Str::headline(Str::beforeLast($class, 'Topic')));

        $columns = array_keys($table->columns);
        $properties = array_filter([
            'employeeColumn' => in_array('user_id', $columns, true) ? 'user_id' : null,
            'divisionColumn' => collect(self::DIVISION_COLUMNS)->first(fn ($c) => in_array($c, $columns, true)),
            'lineColumn' => in_array('line_id', $columns, true) ? 'line_id' : null,
            'dateColumn' => $draft->defaultTimeField,
        ]);
        // Record timestamps say when a row was typed in, not what it is about.
        if (in_array($properties['dateColumn'] ?? null, ['created_at', 'updated_at'], true)) {
            unset($properties['dateColumn']);
        }
        // Assignments valid over a span: the period keeps rows valid at any time in it.
        if (in_array('from_date', $columns, true) && in_array('to_date', $columns, true)) {
            unset($properties['dateColumn']);
            $properties += ['validFromColumn' => 'from_date', 'validToColumn' => 'to_date'];
        }

        $relations = [];
        foreach ($scanner->relationsOf($modelClass) as $relation) {
            $target = class_basename($relation['related']).'Topic';
            $exists = class_exists("{$namespace}\\{$target}");
            $line = sprintf(
                "Relation::%s('%s', '%s', %s::class, '%s'%s),",
                $relation['type'],
                $relation['method'],
                Str::headline($relation['method']),
                $target,
                $relation['foreign_key'],
                $relation['other_key'] !== 'id' ? ", '{$relation['other_key']}'" : '',
            );
            $relations[] = $exists ? $line : "// {$line} // create {$target} first";
        }

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        file_put_contents($file, $this->render($namespace, $class, $modelClass, $label, (string) ($this->option('group') ?: $label), $properties, $draft->fields, $relations));

        $this->components->info("Created {$file}");
        $this->components->bulletList([
            'Rename labels, remove fields reports should not offer, and check the employee / division / line / date columns.',
            'Relations to topics that do not exist yet are commented out: create those topics, then uncomment.',
        ]);

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: string} directory, namespace */
    private function destination(): array
    {
        $paths = (array) config('report-generator.topics.paths', []);
        $path = array_key_first($paths) ?? 'app/Reporting/Topics';
        $namespace = $paths[$path] ?? 'App\\Reporting\\Topics';

        return [str_starts_with($path, '/') ? $path : base_path($path), rtrim($namespace, '\\')];
    }

    /**
     * @param  array<string, string>  $properties
     * @param  list<FieldDraft>  $fields
     * @param  list<string>  $relations
     */
    private function render(string $namespace, string $class, string $modelClass, string $label, string $group, array $properties, array $fields, array $relations): string
    {
        $lines = ["            Field::count('Number of ".Str::lower($label)."'),"];
        foreach ($fields as $field) {
            $lines[] = '            '.$this->fieldLine($field);
        }

        $props = '';
        foreach ($properties as $name => $value) {
            $props .= "    public ?string \${$name} = '{$value}';\n\n";
        }

        $relationLines = $relations ? implode("\n", array_map(fn ($l) => '            '.$l, $relations)) : '            // Relation::belongsTo(\'name\', \'Label\', OtherTopic::class, \'other_id\'),';
        $model = class_basename($modelClass);

        return <<<PHP
<?php

namespace {$namespace};

use {$modelClass};
use Abdalla\\ReportGenerator\\Topics\\Field;
use Abdalla\\ReportGenerator\\Topics\\Relation;
use Abdalla\\ReportGenerator\\Topics\\Topic;

class {$class} extends Topic
{
    public string \$model = {$model}::class;

    public ?string \$label = '{$label}';

    public ?string \$group = '{$group}';

{$props}    public function fields(): array
    {
        return [
{$this->joinLines($lines)}
        ];
    }

    public function relations(): array
    {
        return [
{$relationLines}
        ];
    }
}

PHP;
    }

    /** @param  list<string>  $lines */
    private function joinLines(array $lines): string
    {
        return implode("\n", $lines);
    }

    private function fieldLine(FieldDraft $field): string
    {
        $column = $field->column;
        $label = str_replace("'", "\\'", $field->label);

        return match (true) {
            $field->hiddenReason === 'sensitive' => "// left out: {$column} looks sensitive",
            $field->hiddenReason === 'soft_delete' => "// {$column}: soft deletes are handled by the model",
            $field->hiddenReason === 'unsupported' => "// left out: {$column} has a type reports cannot use",
            $field->type === DataType::Json => "// left out: {$column} is JSON",
            $field->role === FieldRole::Key => "Field::id('{$column}', '{$label}'),",
            $field->type === DataType::Date => "Field::date('{$column}', '{$label}'),",
            $field->type === DataType::DateTime => "Field::datetime('{$column}', '{$label}'),",
            $field->type === DataType::Boolean => "Field::boolean('{$column}', '{$label}'),",
            $field->type === DataType::Text => "Field::longText('{$column}', '{$label}'),",
            $field->role === FieldRole::Measure && $field->type === DataType::Integer => "Field::integer('{$column}', '{$label}')->totals(['sum', 'avg', 'min', 'max']),",
            $field->role === FieldRole::Measure => "Field::number('{$column}', '{$label}'),",
            $field->type === DataType::Integer => "Field::integer('{$column}', '{$label}'),",
            $field->type === DataType::Decimal => "Field::number('{$column}', '{$label}')->totals([]), // describes rather than measures",
            default => "Field::text('{$column}', '{$label}'),",
        };
    }
}
