<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use RuntimeException;

trait CreatesApplication
{
    public static function createApp()
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        foreach ($app->make('config')->get('database.connections', []) as $name => $connection) {
            if (($connection['driver'] ?? null) !== 'mysql') {
                continue;
            }

            $database = $connection['database'] ?? null;
            if (!is_string($database) || !str_ends_with($database, '_test')) {
                throw new RuntimeException("Refusing to run tests against non-test database connection '{$name}'.");
            }
        }

        return $app;
    }

    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        return static::createApp();
    }
}
