<?php

namespace Abdalla\ReportGenerator\Export;

use Abdalla\ReportGenerator\Query\QueryException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Writes streamed report rows as CSV or XLSX straight to the response,
 * never holding the whole file in memory.
 */
final class ReportWriter
{
    public const FORMATS = ['csv', 'xlsx'];

    /**
     * @param  list<array>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     */
    public function response(string $format, string $name, array $columns, iterable $rows): StreamedResponse
    {
        if ($format === 'xlsx' && (! class_exists(XlsxWriter::class) || ! class_exists(\ZipArchive::class))) {
            throw new QueryException('EXPORT_UNAVAILABLE', 'Excel export needs openspout/openspout and the PHP zip extension. Export as CSV instead.', status: 422);
        }

        $filename = $this->filename($name, $format);
        $type = $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8';

        return new StreamedResponse(function () use ($format, $columns, $rows) {
            $format === 'xlsx' ? $this->xlsx($columns, $rows) : $this->csv($columns, $rows);
        }, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"; filename*=UTF-8\'\''.rawurlencode($filename),
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /** @param iterable<array<string, mixed>> $rows */
    public function csv(array $columns, iterable $rows, $out = null): void
    {
        $out ??= fopen('php://output', 'wb');
        // Excel opens UTF-8 (Arabic names) correctly only with the byte order mark.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_column($columns, 'label'));
        $aliases = array_column($columns, 'alias');
        $i = 0;
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($a) => $this->csvSafe($this->scalar($row[$a] ?? null)), $aliases));
            if (++$i % 1000 === 0) {
                fflush($out);
            }
        }
        fflush($out);
    }

    /** @param iterable<array<string, mixed>> $rows */
    public function xlsx(array $columns, iterable $rows, string $path = 'php://output'): void
    {
        $writer = new XlsxWriter();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(array_column($columns, 'label')));
        $aliases = array_column($columns, 'alias');
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_map(fn ($a) => $this->scalar($row[$a] ?? null), $aliases)));
        }
        $writer->close();
    }

    /**
     * Text that Excel would run as a formula (=, +, -, @, tab, return at the
     * start: "CSV injection") is prefixed with an apostrophe. Numbers stay.
     */
    private function csvSafe(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && ! is_numeric($value) && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)
            ? "'".$value
            : $value;
    }

    private function scalar(mixed $value): mixed
    {
        return match (true) {
            is_bool($value) => $value ? 1 : 0,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_array($value) || is_object($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => $value,
        };
    }

    private function filename(string $name, string $format): string
    {
        $base = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $name))) ?: 'report';

        return mb_substr($base, 0, 120).' '.date('Y-m-d').'.'.$format;
    }
}
