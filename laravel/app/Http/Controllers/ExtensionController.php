<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ExtensionController extends Controller
{
    /**
     * Serve the prebuilt browser extension zip. Built by the project's
     * extension/ build script and copied to storage/app/public on deploy.
     */
    public function download(): BinaryFileResponse|Response
    {
        $path = config('bex.extension_zip_path');

        if (! is_string($path) || ! is_file($path)) {
            return response(
                'Extension zip not built yet. Run `cd extension && pnpm run build:zip` from the project root.',
                503
            );
        }

        $version = config('bex.extension_version', '0.0.0');

        return response()->download(
            $path,
            "bexlogs-extension-{$version}.zip",
            ['Content-Type' => 'application/zip']
        );
    }
}
