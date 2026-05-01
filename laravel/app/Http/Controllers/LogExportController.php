<?php

namespace App\Http\Controllers;

use App\Models\LogMessage;
use App\Models\Page;
use App\Models\Subscription;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class LogExportController extends Controller
{
    /**
     * Stream all rows for a page as an .xlsx download.
     * Mirrors the Electron app's export columns:
     *   Timestamp | Type | Action | Method | Status | Parameters | Request | Response
     */
    public function export(Request $request, Page $page): StreamedResponse
    {
        $this->authorizePageAccess($request, $page);

        $filename = sprintf(
            '%s_%s_%s.xlsx',
            $page->organization?->id ?? 'org',
            $page->application?->id ?? 'app',
            $page->subscription?->id ?? 'sub',
        );

        return new StreamedResponse(function () use ($page) {
            $writer = new Writer;
            $writer->openToFile('php://output');

            $headerStyle = new Style;
            $headerStyle->setFontBold();
            $writer->addRow(Row::fromValues([
                'Timestamp', 'Type', 'Action', 'Method', 'Status',
                'Parameters', 'Request', 'Response',
            ], $headerStyle));

            LogMessage::query()
                ->where('page_id', $page->id)
                ->orderBy('timestamp')
                ->orderBy('id')
                ->chunkById(500, function ($chunk) use ($writer) {
                    foreach ($chunk as $msg) {
                        $writer->addRow(Row::fromValues([
                            $msg->timestamp,
                            $msg->type,
                            $msg->action,
                            $msg->method,
                            $msg->status,
                            $this->encodeJson($msg->parameters),
                            $this->encodeJson($msg->request),
                            $this->encodeJson($msg->response),
                        ]));
                    }
                });

            $writer->close();
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    /**
     * Import rows from an uploaded .xlsx into the page. Same column order as
     * the export. Existing rows are deduplicated by the
     * (page_id, timestamp, type, action, method, status) unique key.
     */
    public function import(Request $request, Page $page)
    {
        $this->authorizePageAccess($request, $page);
        $request->validate(['file' => 'required|file|mimes:xlsx,xls']);

        $reader = new Reader;
        $reader->open($request->file('file')->getRealPath());

        $imported = 0;
        $skippedHeader = false;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $batch = [];
                foreach ($sheet->getRowIterator() as $row) {
                    if (! $skippedHeader) {
                        $skippedHeader = true;

                        continue;
                    }
                    $cells = array_map(fn (Cell $c) => $c->getValue(), $row->getCells());

                    [$ts, $type, $action, $method, $status, $params, $req, $resp] = array_pad($cells, 8, null);
                    if (! $ts || ! $type) {
                        continue;
                    }

                    $batch[] = [
                        'page_id' => $page->id,
                        'timestamp' => $this->normalizeTimestamp($ts),
                        'type' => (string) $type,
                        'action' => (string) ($action ?? ''),
                        'method' => (string) ($method ?? ''),
                        'status' => $status !== null ? (string) $status : null,
                        'parameters' => $this->decodeJson($params),
                        'request' => $this->decodeJson($req),
                        'response' => $this->decodeJson($resp),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    if (count($batch) >= 500) {
                        $imported += LogMessage::query()->upsert(
                            $batch,
                            ['page_id', 'timestamp', 'type', 'action', 'method', 'status'],
                            [],
                        );
                        $batch = [];
                    }
                }
                if (! empty($batch)) {
                    $imported += LogMessage::query()->upsert(
                        $batch,
                        ['page_id', 'timestamp', 'type', 'action', 'method', 'status'],
                        [],
                    );
                }
            }
        } finally {
            $reader->close();
        }

        return back()->with('status', "imported-{$imported}");
    }

    private function encodeJson(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function decodeJson(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        try {
            return json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return (string) $value;
        }
    }

    private function normalizeTimestamp(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s\Z');
        }

        return (string) $value;
    }

    private function authorizePageAccess(Request $request, Page $page): void
    {
        $owns = Subscription::query()
            ->join('applications', 'applications.id', '=', 'subscriptions.application_id')
            ->join('organizations', 'organizations.id', '=', 'applications.organization_id')
            ->where('subscriptions.id', $page->subscription_id)
            ->where('organizations.user_id', $request->user()->id)
            ->exists();
        abort_unless($owns, 403);
    }
}
