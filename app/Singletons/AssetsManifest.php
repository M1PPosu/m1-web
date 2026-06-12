<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Singletons;

use Exception;
use Illuminate\Support\HtmlString;

class AssetsManifest
{
    private $manifest;
    private string $manifestPath;
    private int $manifestMtime;

    public function __construct()
    {
        $this->manifestPath = public_path('assets/manifest.json');

        $this->loadManifest();
    }

    private function loadManifest(): void
    {
        if (!file_exists($this->manifestPath)) {
            throw new Exception('The manifest does not exist.');
        }

        $mtime = filemtime($this->manifestPath);
        if ($mtime === false) {
            throw new Exception('The manifest timestamp could not be read.');
        }

        $this->manifest = json_decode(file_get_contents($this->manifestPath), true);
        $this->manifestMtime = $mtime;
    }

    public function src(string $resource): HtmlString
    {
        if (filemtime($this->manifestPath) !== $this->manifestMtime) {
            $this->loadManifest();
        }

        if (!isset($this->manifest[$resource])) {
            throw new Exception("resource not defined: {$resource}.");
        }

        return new HtmlString($this->manifest[$resource]);
    }
}
