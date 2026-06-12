<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Libraries\M1pposu;

use App\Models\Beatmap;
use App\Models\Beatmapset;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class PrivateBeatmapsetSearch
{
    private const DEFAULT_STATUS = 'default';
    private const STATUS_MAP = [
        'any' => null,
        'leaderboard' => [Beatmapset::STATES['ranked'], Beatmapset::STATES['approved'], Beatmapset::STATES['qualified'], Beatmapset::STATES['loved']],
        'ranked' => [Beatmapset::STATES['ranked'], Beatmapset::STATES['approved']],
        'qualified' => [Beatmapset::STATES['qualified']],
        'loved' => [Beatmapset::STATES['loved']],
        'pending' => [Beatmapset::STATES['pending']],
        'wip' => [Beatmapset::STATES['wip']],
        'graveyard' => [Beatmapset::STATES['graveyard']],
        self::DEFAULT_STATUS => [Beatmapset::STATES['ranked'], Beatmapset::STATES['approved'], Beatmapset::STATES['loved']],
    ];
    private const SORT_FIELDS = ['artist', 'creator', 'difficulty', 'favourites', 'nominations', 'plays', 'ranked', 'rating', 'relevance', 'title', 'updated'];

    private ?int $count = null;
    private ?Collection $records = null;
    private readonly ?int $mode;
    private readonly int $offset;
    private readonly int $page;
    private readonly string $queryString;
    private readonly int $size;
    private readonly string $sortField;
    private readonly string $sortOrder;
    private readonly string $status;

    public function __construct(private readonly array $rawParams, private readonly ?User $user)
    {
        $this->queryString = trim((string) ($rawParams['q'] ?? $rawParams['query'] ?? ''));
        $mode = get_int($rawParams['m'] ?? null);
        $this->mode = in_array($mode, array_values(Beatmap::MODES), true) ? $mode : null;
        $this->status = $this->normalStatus($rawParams['s'] ?? $rawParams['status'] ?? null);
        [$this->sortField, $this->sortOrder] = $this->normalSort($rawParams['sort'] ?? null);
        $this->page = max(1, get_int($rawParams['page'] ?? null) ?? 1);
        $this->size = $GLOBALS['cfg']['osu']['beatmaps']['max'];
        $this->offset = ($this->page - 1) * $this->size;
    }

    public function count(): int
    {
        return $this->count ??= (clone $this->baseQuery())->count();
    }

    public function getError(): null
    {
        return null;
    }

    public function getRecommendedDifficulty(): null
    {
        return null;
    }

    public function getSort(): string
    {
        return "{$this->sortField}_{$this->sortOrder}";
    }

    public function records(): Collection
    {
        return $this->records ??= $this->orderedQuery($this->baseQuery())
            ->withPackTags()
            ->with(['beatmaps' => fn ($query) => $query->withMaxCombo()])
            ->limit($this->size)
            ->offset($this->offset)
            ->get();
    }

    private function applyStatusFilter(Builder $query): void
    {
        if ($this->status === 'favourites') {
            $this->user === null
                ? $query->whereRaw('1 = 0')
                : $query->whereHas('favourites', fn ($favouritesQuery) => $favouritesQuery->where('user_id', $this->user->getKey()));

            return;
        }

        if ($this->status === 'mine') {
            $this->user === null
                ? $query->whereRaw('1 = 0')
                : $query->where(function ($mineQuery) {
                    $mineQuery
                        ->where('user_id', $this->user->getKey())
                        ->orWhereHas('beatmaps', fn ($beatmapQuery) => $beatmapQuery->where('user_id', $this->user->getKey()));
                });

            return;
        }

        $statuses = self::STATUS_MAP[$this->status] ?? self::STATUS_MAP[self::DEFAULT_STATUS];
        if ($statuses === null) {
            return;
        }

        $query->whereHas('beatmaps', fn ($beatmapQuery) => $beatmapQuery->whereIn('approved', $statuses));
    }

    private function baseQuery(): Builder
    {
        $query = Beatmapset::whereHas('beatmaps')
            ->where('active', true)
            ->whereNull('deleted_at');

        if ($this->mode !== null) {
            $query->whereHas('beatmaps', fn ($beatmapQuery) => $beatmapQuery->where('playmode', $this->mode));
        }

        if ($this->queryString !== '') {
            $this->applyTextFilter($query);
        }

        $this->applyStatusFilter($query);

        return $query;
    }

    private function applyTextFilter(Builder $query): void
    {
        $search = mb_strtolower($this->queryString);
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search);

        $query->where(function ($textQuery) use ($like, $search) {
            if (ctype_digit($search)) {
                $id = (int) $search;
                $textQuery
                    ->orWhere('beatmapset_id', $id)
                    ->orWhereHas('beatmaps', fn ($beatmapQuery) => $beatmapQuery->where('beatmap_id', $id));
            }

            $textQuery
                ->orWhereRaw('LOWER(artist) LIKE ?', ["%{$like}%"])
                ->orWhereRaw('LOWER(artist_unicode) LIKE ?', ["%{$like}%"])
                ->orWhereRaw('LOWER(title) LIKE ?', ["%{$like}%"])
                ->orWhereRaw('LOWER(title_unicode) LIKE ?', ["%{$like}%"])
                ->orWhereRaw('LOWER(creator) LIKE ?', ["%{$like}%"])
                ->orWhereRaw('LOWER(tags) LIKE ?', ["%{$like}%"])
                ->orWhereHas('beatmaps', function ($beatmapQuery) use ($like) {
                    $beatmapQuery
                        ->whereRaw('LOWER(version) LIKE ?', ["%{$like}%"])
                        ->orWhereRaw('LOWER(checksum) LIKE ?', ["%{$like}%"]);
                });
        });
    }

    private function normalSort($value): array
    {
        $parts = explode('_', (string) $value);
        $field = $parts[0] ?? '';
        $order = $parts[1] ?? 'desc';

        if (!in_array($field, self::SORT_FIELDS, true)) {
            $field = $this->queryString === '' ? 'ranked' : 'relevance';
        }

        if (!in_array($order, ['asc', 'desc'], true)) {
            $order = 'desc';
        }

        return [$field, $order];
    }

    private function normalStatus($value): string
    {
        static $legacyStatusMap = [
            '0' => 'ranked',
            '2' => 'favourites',
            '3' => 'qualified',
            '4' => 'pending',
            '5' => 'graveyard',
            '6' => 'mine',
            '7' => 'any',
            '8' => 'loved',
        ];

        $status = $legacyStatusMap[(string) $value] ?? (string) $value;

        return array_key_exists($status, self::STATUS_MAP) || in_array($status, ['favourites', 'mine'], true)
            ? $status
            : self::DEFAULT_STATUS;
    }

    private function orderedQuery(Builder $query): Builder
    {
        $direction = $this->sortOrder;

        match ($this->sortField) {
            'artist' => $query->orderBy('artist', $direction),
            'creator' => $query->orderBy('creator', $direction),
            'difficulty' => $query->orderBy($this->beatmapAggregate('difficultyrating', $direction === 'asc' ? 'min' : 'max'), $direction),
            'favourites' => $query->orderBy('favourite_count', $direction),
            'nominations' => $query->orderBy('nominations', $direction)->orderBy('hype', $direction),
            'plays' => $query->orderBy('play_count', $direction),
            'rating' => $query->orderBy('rating', $direction),
            'title' => $query->orderBy('title', $direction),
            'updated' => $query->orderBy('last_update', $direction),
            default => $query->orderByRaw("COALESCE(approved_date, last_update) {$direction}"),
        };

        return $query->orderBy('beatmapset_id', $direction);
    }

    private function beatmapAggregate(string $column, string $function)
    {
        return Beatmap::selectRaw("{$function}({$column})")
            ->whereColumn('osu_beatmaps.beatmapset_id', 'osu_beatmapsets.beatmapset_id')
            ->when($this->mode !== null, fn ($query) => $query->where('playmode', $this->mode));
    }
}
