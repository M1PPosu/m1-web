{{--
    Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
    See the LICENCE file in the repository root for full licence text.
--}}
@php
    use App\Libraries\M1pposu\SourceMode;
    use App\Models\Beatmap;

    $topPlayVariant = $variant ?? null;
    $hasTopPlayScores = $scores !== null && $scores->total() > 0;
@endphp
@extends('rankings.index', [
    'hasPager' => $hasTopPlayScores,
    'params' => ['mode' => $rulesetName, 'type' => 'top_plays', 'variant' => $topPlayVariant],
    'rulesetSelectorUrlFn' => fn (string $r): string => route('rankings.top-plays', [
        'mode' => $r,
        'variant' => SourceMode::sourceMode($r, $topPlayVariant) === null ? null : $topPlayVariant,
    ]),
    'titlePrepend' => osu_trans('rankings.type.top_plays'),
])

@section('ranking-header')
    @php
        $variants = array_values(array_filter(
            Beatmap::VARIANTS[$rulesetName] ?? [],
            fn ($v) => SourceMode::sourceMode($rulesetName, $v) !== null,
        ));
        if ($variants !== []) {
            array_unshift($variants, 'all');
        }
    @endphp
    @if ($variants !== [])
        <div class="osu-page osu-page--ranking-info">
            <div class="grid-items grid-items--ranking-filter">
                <div class="ranking-filter">
                    <div class="ranking-filter__title">
                        {{ osu_trans('rankings.filter.variant.title') }}
                    </div>
                    <div class="sort sort--ranking-header">
                        <div class="sort__items">
                            @foreach ($variants as $v)
                                @php
                                    $selectedVariant = $v === 'all' ? null : $v;
                                @endphp
                                <a
                                    class="{{ class_with_modifiers('sort__item', 'button', ['active' => $topPlayVariant === $selectedVariant]) }}"
                                    href="{{ route('rankings.top-plays', [
                                        'mode' => $rulesetName,
                                        'variant' => $selectedVariant,
                                        'page' => null,
                                    ]) }}"
                                >
                                    {{ osu_trans("beatmaps.variant.{$rulesetName}.{$v}") }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection

@if (!$hasTopPlayScores)
    @section('scores')
        {{ osu_trans('rankings.top_plays.empty') }}
    @endsection
@else
    @section('scores-header')
        <p>
            <small>{{ osu_trans('rankings.top_plays.last_updated') }}: {!! timeago($lastUpdate) !!}</small>
        </p>
    @endsection
    @section('scores')
        <div
            class="u-contents js-react"
            data-props="{{ json_encode([
                'first_score_rank' => $scores->firstItem(),
                'scores' => $scoresJson,
            ]) }}"
            data-react="ranking-top-plays"
        >
            <div class="ranking-page-grid">
                <div class="ranking-page-grid-item ranking-page-grid-item--header">
                    <div class="ranking-page-grid-item__content">
                        <div class="ranking-page-grid-item__col">
                            &nbsp;
                        </div>
                    </div>
                </div>
                @foreach ($scores as $score)
                    <div class="ranking-page-grid-item">
                        <div class="ranking-page-grid-item__content">
                            <div class="ranking-page-grid-item__col">
                                <div class="ranking-page-grid-item__icons"></div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        @include('layout._react_js', ['src' => 'js/ranking-top-plays.js'])
    @endsection
@endif
