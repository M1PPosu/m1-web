<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Singletons;

use App\Enums\Ip;
use App\Libraries\Ip2AsnUpdater;
use Exception;
use WeakMap;

class Ip2Asn
{
    private bool $available = true;
    private WeakMap $count;
    private WeakMap $dbFh;
    private WeakMap $index;

    public function __construct()
    {
        $this->count = new WeakMap();
        $this->dbFh = new WeakMap();
        $this->index = new WeakMap();

        foreach (Ip::cases() as $version) {
            $dbPath = Ip2AsnUpdater::getDbPath($version);
            $indexPath = Ip2AsnUpdater::getIndexPath($version);

            if (!is_file($dbPath) || !is_file($indexPath)) {
                $this->available = false;

                return;
            }

            $this->dbFh[$version] = fopen($dbPath, 'r');
            $index = file_get_contents($indexPath);
            if ($this->dbFh[$version] === false || $index === false) {
                throw new Exception("failed opening ip2asn {$version} database or index");
            }
            $this->index[$version] = $index;

            // 4 bytes per entry (int32)
            $this->count[$version] = strlen($this->index[$version]) / 4;
        }
    }

    public function lookup(string $ip): string
    {
        if (!$this->available) {
            return '0';
        }

        switch (true) {
            case filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false:
                return $this->lookupByVersion(Ip::V4, $ip);
            case filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false:
                return $this->lookupByVersion(Ip::V6, $ip);
        }

        return '0';
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    private function lookupByVersion(Ip $version, string $ip): string
    {
        $dbFh = $this->dbFh[$version];
        $index = $this->index[$version];
        $start = 0;
        $end = $this->count[$version] - 1;
        $search = inet_pton($ip);

        while ($start <= $end) {
            $current = (int) (($start + $end) / 2);
            $loc = unpack('l', substr($index, $current * 4, 4))[1];
            fseek($dbFh, $loc);
            $row = fgets($dbFh);
            $data = explode("\t", $row, 4);
            $compare = inet_pton($data[1]);
            $asn = $data[2];
            if ($compare === $search) {
                return $asn;
            } elseif ($compare < $search) {
                $start = $current + 1;
            } elseif ($compare > $search) {
                $lastInnerSearchAsn = $asn;
                $end = $current - 1;
            }
        }

        return $lastInnerSearchAsn ?? $asn;
    }
}
