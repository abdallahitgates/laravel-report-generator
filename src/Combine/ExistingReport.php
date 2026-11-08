<?php

namespace Abdalla\ReportGenerator\Combine;

use Abdalla\ReportGenerator\Contracts\CombinableReport;
use Abdalla\ReportGenerator\Query\QueryException;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;

/**
 * An existing report served by a host controller, combinable in a few lines:
 *
 *   ExistingReport::make('visit_statistics', 'Visit statistics')
 *       ->permission('show_reports_visits_statistics')
 *       ->action([VisitStatisticsController::class, 'filter'])
 *       ->payload(fn (CombineRun $run) => ['visitFilter' => [
 *           'fromDate' => $run->fromDate(), 'toDate' => $run->toDate(),
 *           'lines' => $run->lines, 'filter' => $run->byEmployee() ? 2 : 1, ...]])
 *       ->rowsAt('data');
 *
 * The controller method is called directly, as the current user, with a
 * request built from the payload (its own row rules apply); the route's
 * middleware is not, so its permission is checked here instead.
 */
final class ExistingReport implements CombinableReport
{
    private ?string $description = null;
    private ?string $group = null;
    private array $levels = ['employee', 'division'];
    private ?string $permission = null;
    private array|Closure|null $action = null;
    private ?Closure $payload = null;
    private string $rowsPath = 'data';
    private string $idKey = 'id';
    /** @var array<string, string> base column => key in the report's rows */
    private array $names = ['line' => 'line', 'division' => 'division', 'employee' => 'employee'];
    private ?array $fields = null;
    /** @var list<string> */
    private array $exclude = [];
    private bool $perLine = false;
    private ?Closure $lineParameter = null;

    private function __construct(private readonly string $key, private readonly string $label)
    {
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw new \InvalidArgumentException("Combinable report key [{$key}] must be lowercase letters, digits and underscores.");
        }
    }

    public static function make(string $key, string $label): self
    {
        return new self($key, $label);
    }

    public function describe(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function inGroup(string $group): self
    {
        $this->group = $group;

        return $this;
    }

    /** @param  'employee'|'division'  ...$levels */
    public function at(string ...$levels): self
    {
        $this->levels = array_values(array_intersect(['employee', 'division'], $levels));

        return $this;
    }

    /** Gate ability the report's route requires. */
    public function requires(string $ability): self
    {
        $this->permission = $ability;

        return $this;
    }

    /** @param  array{0: class-string, 1: string}|Closure(Request, CombineRun, array): mixed  $action */
    public function action(array|Closure $action): self
    {
        $this->action = $action;

        return $this;
    }

    /** @param  Closure(CombineRun): array  $payload  request body for a run (for one line when perLine) */
    public function payload(Closure $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    /** Where the rows are in the response (dot path). */
    public function rowsAt(string $path): self
    {
        $this->rowsPath = $path;

        return $this;
    }

    public function idKey(string $key): self
    {
        $this->idKey = $key;

        return $this;
    }

    /** @param  array<string, string>  $names  base column (line, division, employee) => key in the rows */
    public function names(array $names): self
    {
        $this->names = $names;

        return $this;
    }

    /**
     * The columns it offers, when known in advance (otherwise discovered).
     *
     * @param  array<string, array<string, mixed>|string>  $fields  key => label, or key => ['label', 'type', 'total']
     */
    public function offering(array $fields): self
    {
        $this->fields = array_map(fn ($f) => is_string($f) ? ['label' => $f] : $f, $fields);

        return $this;
    }

    /** @param  list<string>  $keys  row keys never offered as columns */
    public function hiding(array $keys): self
    {
        $this->exclude = $keys;

        return $this;
    }

    /**
     * The route takes one line ({line}): run once per line and merge. The
     * closure turns a line id into the route parameter (e.g. Line::find).
     */
    public function perLine(Closure $parameter): self
    {
        $this->perLine = true;
        $this->lineParameter = $parameter;

        return $this;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function group(): ?string
    {
        return $this->group;
    }

    public function levels(): array
    {
        return $this->levels;
    }

    public function permission(): ?string
    {
        return $this->permission;
    }

    public function fields(): ?array
    {
        return $this->fields;
    }

    /** @return list<string> */
    public function excluded(): array
    {
        return [...$this->exclude, $this->idKey, ...array_values($this->names)];
    }

    public function rows(CombineRun $run): iterable
    {
        if ($this->action === null || $this->payload === null) {
            throw new \LogicException("Combinable report [{$this->key}] needs action() and payload().");
        }

        $runs = $this->perLine
            ? array_map(fn ($line) => [new CombineRun($run->level, $run->from, $run->to, [$line], $run->divisions, $run->employees, $run->user), ['line' => ($this->lineParameter)($line)]], $run->lines)
            : [[$run, []]];

        foreach ($runs as [$lineRun, $parameters]) {
            foreach ($this->invoke($lineRun, $parameters) as $row) {
                if (! is_array($row) || ! array_key_exists($this->idKey, $row)) {
                    continue;
                }
                $out = ['id' => $row[$this->idKey]];
                foreach ($this->names as $base => $source) {
                    if (array_key_exists($source, $row)) {
                        $out[$base] = $row[$source];
                    }
                }
                foreach ($row as $key => $value) {
                    if (! in_array($key, $this->excluded(), true) && ! array_key_exists($key, $out)) {
                        $out[$key] = $value;
                    }
                }
                yield $out;
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function invoke(CombineRun $run, array $parameters): array
    {
        $container = Container::getInstance();
        $outer = $container->bound('request') ? $container->make('request') : null;
        $request = Request::create('/report-generator/combine/'.$this->key, 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], json_encode(($this->payload)($run)));
        $request->setUserResolver(fn () => $run->user);
        if ($outer !== null && $outer->hasSession()) {
            $request->setLaravelSession($outer->session());
        }

        // Code inside the report reads request() too: point it at our request while it runs.
        $container->instance('request', $request);
        Facade::clearResolvedInstance('request');

        try {
            $response = $this->action instanceof Closure
                ? ($this->action)($request, $run, $parameters)
                : $container->call([$container->make($this->action[0]), $this->action[1]], $parameters + ['request' => $request]);
        } catch (QueryException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            throw new QueryException('REPORT_FAILED', "{$this->label} could not run: ".$e->getMessage(), status: 422, details: ['report' => $this->key]);
        } finally {
            if ($outer !== null) {
                $container->instance('request', $outer);
            }
            Facade::clearResolvedInstance('request');
        }

        $data = match (true) {
            $response instanceof JsonResponse => $response->getData(true),
            $response instanceof Response => json_decode((string) $response->getContent(), true),
            $response instanceof \Illuminate\Contracts\Support\Arrayable => $response->toArray(),
            default => $response,
        };
        if ($response instanceof Response && $response->getStatusCode() >= 400) {
            throw new QueryException('REPORT_FAILED', "{$this->label} could not run: ".($data['message'] ?? 'error '.$response->getStatusCode()), status: 422, details: ['report' => $this->key]);
        }

        $rows = Arr::get(is_array($data) ? $data : [], $this->rowsPath, []);

        return array_values(is_array($rows) ? $rows : (array) $rows);
    }
}
