<?php

namespace Abdalla\ReportGenerator\Console;

use Abdalla\ReportGenerator\Semantic\ModelPublisher;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class PublishCommand extends Command
{
    protected $signature = 'report-generator:publish {--notes= : What changed in this version}';

    protected $description = 'Publish the reviewed draft as a new semantic model version';

    public function handle(ModelPublisher $publisher): int
    {
        try {
            $version = $publisher->publish(notes: $this->option('notes'));
        } catch (ValidationException $e) {
            $this->components->error(collect($e->errors())->flatten()->first());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Published model version %d: %d datasets, %d relationships.',
            $version->version, count($version->payload['datasets']), count($version->payload['relationships']),
        ));

        return self::SUCCESS;
    }
}
