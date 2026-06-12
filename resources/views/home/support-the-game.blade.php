{{--
    Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
    See the LICENCE file in the repository root for full licence text.
--}}
@php
    use App\Models\Store\Product;
    use App\Models\SupporterTag;

    static $getDurationText = fn (string $duration): string =>
        SupporterTag::getDurationText($duration, null, osu_trans('common.array_and.two_words_connector'));
@endphp

@extends('master')

@section('content')
    @component('layout._page_header_v4', ['params' => [
        'showTitle' => false,
        'theme' => 'supporter',
    ]])
        @slot('contentAppend')
            <div class="supporter-status">
                <div class="supporter-status__brand-art"></div>
                @if (!empty($supporterStatus))
                    <!-- Commented out: supporter status section with heart icon -->
                    {{--
                    <div class="supporter-status__flex-container">
                        <a class="supporter-eyecatch__link" href="{{ route('store.products.show', Product::SUPPORTER_TAG_NAME) }}" title="{{ osu_trans('community.support.convinced.support') }}">
                            <div class="supporter-heart{{ $supporterStatus['current'] ? ' supporter-heart--active' : '' }}"></div>
                        </a>
                        <div class="supporter-status__flex-container-inner">
                    --}}
                    {{-- Commented out supporter status --}}
                    {{-- <div class="supporter-status__flex-container">
                        <div class="supporter-status__flex-container-inner">
                            <div class="supporter-status__progress-bar supporter-status__progress-bar--active">
                                <div class="supporter-status__progress-bar-fill supporter-status__progress-bar-fill--active" style="width: {{ $supporterStatus['remainingPercent'] ?? '0' }}%;"></div>
                            </div>
                            @if ($supporterStatus['expiration'] !== null)
                            <div class="supporter-status__text supporter-status__text--first">
                                {!! osu_trans('community.support.supporter_status.'.($supporterStatus['current'] ? 'valid_until' : 'was_valid_until'), [
                                    'date' => '<strong>'.i18n_date($supporterStatus['expiration']).'</strong>'
                                ]) !!}
                            </div>
                            @else
                            <div class="supporter-status__text">
                                {!! osu_trans('community.support.supporter_status.not_yet') !!}
                            </div>
                            @endif
                            @if ($supporterStatus['duration'] > 0)
                            <div class="supporter-status__text">
                                {!! osu_trans('community.support.supporter_status.contribution_with_duration', [
                                    'dollars' => "<strong>{$supporterStatus['dollars']}</strong>",
                                    'duration' => tag(
                                        'strong',
                                        content: e($getDurationText($supporterStatus['duration'])),
                                    ),
                                ]) !!}
                            </div>
                            @endif
                            @if ($supporterStatus['giftedDuration'] > 0)
                            <div class="supporter-status__text">
                                {!! osu_trans('community.support.supporter_status.gifted._', [
                                    'dollars' => "<strong>{$supporterStatus['giftedDollars']}</strong>",
                                    'duration' => tag(
                                        'strong',
                                        content: e($getDurationText($supporterStatus['giftedDuration'])),
                                    ),
                                    'users' => tag(
                                        'strong',
                                        content: e(osu_trans_choice('community.support.supporter_status.gifted.users', $supporterStatus['giftedUsers'])),
                                    ),
                                ]) !!}
                            </div>
                            @endif
                        </div>
                    {{-- </div> --}}
                    {{-- End of commented out supporter status --}}
                    <!-- end: supporter status -->
                @endif
            </div>
        @endslot
    @endcomponent

    <div class="osu-page osu-page--supporter">
        <div class="supporter">
            <div class="supporter-quote">
                <div class="supporter-quote__body">
                    <div class="supporter-quote__quote-mark supporter-quote__quote-mark--left"><i class="fas fa-quote-left"></i></div>
                    <blockquote class="supporter-quote__content">
                        Support helps cover hosting, maintenance, development, and future M1PPosu features.
                        <br/><br/>
                        M1PPosu supporter status belongs to M1PPosu only and is not an official supporter tag from osu.ppy.sh.
                        <br/><br/>
                        {{ config('m1pposu.legal.non_affiliation') }}
                    </blockquote>
                    <div class="supporter-quote__quote-mark supporter-quote__quote-mark--right"><i class="fas fa-quote-right"></i></div>
                </div>
                <div class="supporter-quote__signature">M1PPosu</div>
            </div>
            <h3 class="supporter__title">
                {{ osu_trans('community.support.why-support.title') }}
            </h3>
            @include('home._supporter_perk_group', ['group' => $data['support-reasons']])
            <div class="supporter__block supporter__block--bg-0">
                <h3 class="supporter__title">
                    {{ osu_trans('community.support.perks.title') }}
                </h3>
            </div>
            @foreach($data['perks'] as $index => $group)
                <div class="supporter__block supporter__block--{{'bg-'.$index % 3}}">
                    @include("home._supporter_perk_{$group['type']}", ['group' => $group])
                </div>
            @endforeach
            <h3 class="supporter__title supporter__title--convinced">
                {{ osu_trans('community.support.convinced.title') }}
            </h3>
            {{-- Commented out: Supporter heart call-to-action section --}}
            {{--
            <div class="supporter-eyecatch">
                <div class="supporter-eyecatch__box">
                    <a class="supporter-eyecatch__link" href="{{ route('store.products.show', Product::SUPPORTER_TAG_NAME) }}">
                        <div class="supporter-heart supporter-heart--larger supporter-heart--active"></div>
                    </a>
                    <div class="supporter-eyecatch__text supporter-eyecatch__text--main">
                        {{ osu_trans('community.support.convinced.support') }}
                    </div>
                    <div class="supporter-eyecatch__text supporter-eyecatch__text--sub-1">
                        {{ osu_trans('community.support.convinced.gift') }}
                    </div>
                    <div class="supporter-eyecatch__text supporter-eyecatch__text--sub-2">
                        {{ osu_trans('community.support.convinced.instructions') }}
                    </div>
                </div>
            </div>
            --}}
        </div>
    </div>
@endsection
