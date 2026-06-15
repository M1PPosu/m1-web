{{--
    Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
    See the LICENCE file in the repository root for full licence text.
--}}
@extends('master', [
    'titlePrepend' => $team->name,
])

@php
    $params = [
        'ruleset' => $ruleset,
        'sort' => $sort,
        'team' => $team->getKey(),
        'variant' => $variant,
    ];
@endphp

@section('content')
    @component('layout._page_header_v4', ['params' => [
        'backgroundImage' => $team->headerUrl(),
        'links' => App\Http\Controllers\TeamsController::pageLinks('leaderboard', $team),
        'theme' => 'team',
    ]])
        @slot('linksAppend')
            @include('objects._ruleset_selector', [
                'currentRuleset' => $ruleset,
                'urlFn' => fn ($r) => route('teams.leaderboard', [
                    ...$params,
                    'ruleset' => $r,
                    'variant' => App\Libraries\M1pposu\SourceMode::sourceMode($r, $variant) === null ? null : $variant,
                ]),
            ])
        @endslot
    @endcomponent

    <div class="osu-page osu-page--generic">
        @php
            $variants = array_values(array_filter(
                App\Models\Beatmap::VARIANTS[$ruleset] ?? [],
                fn ($candidate) => App\Libraries\M1pposu\SourceMode::sourceMode($ruleset, $candidate) !== null,
            ));
            array_unshift($variants, 'all');
        @endphp
        <div class="sort">
            <div class="sort__items">
                <div class="sort__item sort__item--title">
                    {{ osu_trans('rankings.filter.variant.title') }}
                </div>
                @foreach ($variants as $v)
                    @php
                        $selectedVariant = $v === 'all' ? null : $v;
                    @endphp
                    <a
                        class="{{ class_with_modifiers('sort__item', 'button', ['active' => $variant === $selectedVariant]) }}"
                        href="{{ route('teams.leaderboard', [...$params, 'variant' => $selectedVariant]) }}"
                    >
                        {{ osu_trans("beatmaps.variant.{$ruleset}.{$v}") }}
                    </a>
                @endforeach
            </div>
        </div>
        <div class="sort">
            <div class="sort__items">
                <div class="sort__item sort__item--title">
                    {{ osu_trans('sort._') }}
                </div>
                @foreach ([['performance', 'performance'], ['score', 'ranked_score']] as $newSort)
                    <a
                        class="{{ class_with_modifiers('sort__item', 'button', ['active' => $newSort[0] === $sort]) }}"
                        href="{{ route('teams.leaderboard', [...$params, 'sort' => $newSort[0]]) }}"
                    >
                        {{ osu_trans("rankings.stat.{$newSort[1]}") }}
                    </a>
                @endforeach
            </div>
        </div>
        <div class="ranking-page">
            @include('teams._members_leaderboard')
        </div>
    </div>
@endsection
