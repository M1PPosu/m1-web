{{--
    Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
    See the LICENCE file in the repository root for full licence text.
--}}
@extends('master', ['titlePrepend' => osu_trans('admin.official_import_requests.show.title')])

@php
    $connection = $officialImportRequest->connection;
    $localUser = $officialImportRequest->user;
    $snapshot = $officialImportRequest->snapshot;
    $data = $snapshot?->data ?? [];
    $officialUser = $data['user'] ?? [];
    $fetchMetadata = $data['fetch_metadata'] ?? [];
    $favouriteBeatmapsets = array_filter($data['beatmapsets']['favourite'] ?? [], fn ($item) => is_array($item) && !isset($item['_error']));
    $mostPlayedBeatmapsets = array_filter($data['beatmapsets']['most_played'] ?? [], fn ($item) => is_array($item) && !isset($item['_error']));
    $scoreSummaryCount = $snapshot?->scoreSummaries?->count() ?? 0;
    $statistics = $data['statistics'] ?? [];
    $scoreRows = [];

    foreach (($data['scores'] ?? []) as $mode => $groups) {
        foreach ($groups as $kind => $scores) {
            $scoreRows[] = [
                'count' => is_array($scores) && !isset($scores['_error'])
                    ? count($scores)
                    : osu_trans('admin.official_import_requests.show.fetch_failed'),
                'kind' => $kind,
                'mode' => $mode,
            ];
        }
    }

    $unavailableLabels = [
        'official API does not expose complete historical ranked scores through this importer' => osu_trans('admin.official_import_requests.show.unavailable_scores'),
        'official favorites/history are limited to the available paginated endpoint responses fetched here' => osu_trans('admin.official_import_requests.show.unavailable_history'),
        'imported scores are archived as official summaries and never inserted into native score tables' => osu_trans('admin.official_import_requests.show.native_scores_safe'),
    ];
    $unavailableData = array_values(array_unique(array_map(
        fn ($limitation) => $unavailableLabels[$limitation] ?? get_string($limitation),
        $fetchMetadata['limitations'] ?? [],
    )));
    $usernameConflict = present($officialUser['username'] ?? null)
        && $localUser !== null
        && \App\Models\User::cleanUsername($officialUser['username']) !== $localUser->username_clean;
@endphp

@section('content')
    @include('admin._header')
    <div class="osu-page osu-page--admin">
        <div class="admin-official-import-requests">
            <div class="admin-official-import-requests__toolbar">
                <h2 class="title">{{ osu_trans('admin.official_import_requests.show.title') }} #{{ $officialImportRequest->getKey() }}</h2>
                <a class="admin-official-import-requests__action" href="{{ route('admin.official-import-requests.index') }}">
                    {{ osu_trans('common.buttons.back') }}
                </a>
            </div>

            <section class="admin-official-import-requests__panel">
                <h3>{{ osu_trans('admin.official_import_requests.show.request_summary') }}</h3>
                <div class="admin-official-import-requests__summary">
                    <div class="admin-official-import-requests__field">
                        <div>{{ osu_trans('admin.official_import_requests.show.status') }}</div>
                        <strong class="admin-official-import-requests__status admin-official-import-requests__status--{{ $officialImportRequest->status }}">
                            {{ $officialImportRequest->status }}
                        </strong>
                        <span>{{ $officialImportRequest->restricted_at_request ? osu_trans('admin.official_import_requests.show.restricted') : '-' }}</span>
                    </div>
                    <div class="admin-official-import-requests__field">
                        <div>{{ osu_trans('admin.official_import_requests.show.requested') }}</div>
                        <strong>{!! timeago($officialImportRequest->created_at) !!}</strong>
                    </div>
                    <div class="admin-official-import-requests__field">
                        <div>{{ osu_trans('admin.official_import_requests.show.reviewed') }}</div>
                        <strong>{!! $officialImportRequest->reviewed_at === null ? '-' : timeago($officialImportRequest->reviewed_at) !!}</strong>
                    </div>
                    <div class="admin-official-import-requests__field">
                        <div>{{ osu_trans('admin.official_import_requests.show.reviewer') }}</div>
                        <strong>{{ $officialImportRequest->reviewer?->username ?? '-' }}</strong>
                    </div>
                </div>
            </section>

            <section class="admin-official-import-requests__panel">
                <h3>{{ osu_trans('admin.official_import_requests.show.local_account') }}</h3>
                <div class="admin-official-import-requests__summary">
                    <div class="admin-official-import-requests__field">
                        <div>{{ osu_trans('admin.official_import_requests.show.local_user') }}</div>
                        <strong>
                            @if ($localUser !== null)
                                <a href="{{ $localUser->url() }}">{{ $localUser->username }}</a>
                            @else
                                #{{ $officialImportRequest->user_id }}
                            @endif
                        </strong>
                        <span>#{{ $officialImportRequest->user_id }}</span>
                    </div>
                    <div class="admin-official-import-requests__field">
                        <div>{{ osu_trans('admin.official_import_requests.show.native_changes') }}</div>
                        <strong>{{ osu_trans('admin.official_import_requests.show.native_changes_none') }}</strong>
                    </div>
                </div>
            </section>

            <section class="admin-official-import-requests__panel">
                <h3>{{ osu_trans('admin.official_import_requests.show.official_account') }}</h3>
                <div class="admin-official-import-requests__summary">
                    <div class="admin-official-import-requests__field">
                        <div>{{ osu_trans('admin.official_import_requests.show.official_user') }}</div>
                        <strong>
                            @if ($connection !== null)
                                <a href="{{ $connection->officialUrl() }}">{{ $connection->username }}</a>
                            @else
                                #{{ $officialImportRequest->official_user_id }}
                            @endif
                        </strong>
                        <span>#{{ $officialImportRequest->official_user_id }}</span>
                    </div>
                    <div class="admin-official-import-requests__field">
                        <div>{{ osu_trans('admin.official_import_requests.show.snapshot_user') }}</div>
                        <strong>{{ $officialUser['username'] ?? '-' }}</strong>
                        <span>#{{ $officialUser['id'] ?? $officialImportRequest->official_user_id }}</span>
                    </div>
                    @if ($usernameConflict)
                        <div class="admin-official-import-requests__field admin-official-import-requests__field--wide">
                            <div>{{ osu_trans('admin.official_import_requests.show.username_conflict_label') }}</div>
                            <strong>{{ osu_trans('admin.official_import_requests.show.username_conflict') }}</strong>
                        </div>
                    @endif
                </div>
            </section>

            <section class="admin-official-import-requests__panel">
                <h3>{{ osu_trans('admin.official_import_requests.show.import_scope') }}</h3>
                <div class="admin-official-import-requests__summary">
                    <div class="admin-official-import-requests__field">
                        <div>{{ osu_trans('admin.official_import_requests.show.coverage') }}</div>
                        <strong>{{ implode(', ', $data['import_scope'] ?? []) ?: '-' }}</strong>
                    </div>
                    <div class="admin-official-import-requests__field">
                        <div>{{ osu_trans('admin.official_import_requests.show.scores_fetched') }}</div>
                        <strong>{{ osu_trans('admin.official_import_requests.show.score_summaries_count', ['count' => $scoreSummaryCount]) }}</strong>
                    </div>
                    <div class="admin-official-import-requests__field">
                        <div>{{ osu_trans('admin.official_import_requests.show.favorites_fetched') }}</div>
                        <strong>{{ count($favouriteBeatmapsets) }}</strong>
                    </div>
                    <div class="admin-official-import-requests__field">
                        <div>{{ osu_trans('admin.official_import_requests.show.most_played_fetched') }}</div>
                        <strong>{{ count($mostPlayedBeatmapsets) }}</strong>
                    </div>
                </div>
            </section>

            @if (!empty($unavailableData))
                <section class="admin-official-import-requests__panel">
                    <h3>{{ osu_trans('admin.official_import_requests.show.unavailable_data') }}</h3>
                    <ul class="admin-official-import-requests__notes">
                        @foreach ($unavailableData as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section class="admin-official-import-requests__panel">
                <h3>{{ osu_trans('admin.official_import_requests.show.snapshot_summary') }}</h3>
                <div class="admin-official-import-requests__table">
                    <div class="admin-official-import-requests__table-header">
                        <span>{{ osu_trans('admin.official_import_requests.show.mode') }}</span>
                        <span>pp</span>
                        <span>{{ osu_trans('admin.official_import_requests.show.play_count') }}</span>
                        <span>{{ osu_trans('admin.official_import_requests.show.accuracy') }}</span>
                        <span>{{ osu_trans('admin.official_import_requests.show.total_score') }}</span>
                    </div>
                    @forelse ($statistics as $mode => $stats)
                        <div class="admin-official-import-requests__table-row">
                            <span>{{ $mode }}</span>
                            <span>{{ $stats['pp'] ?? 0 }}</span>
                            <span>{{ $stats['play_count'] ?? 0 }}</span>
                            <span>{{ $stats['hit_accuracy'] ?? 0 }}</span>
                            <span>{{ $stats['total_score'] ?? 0 }}</span>
                        </div>
                    @empty
                        <div class="admin-official-import-requests__table-row">
                            <span>-</span><span>-</span><span>-</span><span>-</span><span>-</span>
                        </div>
                    @endforelse
                </div>
            </section>

            <section class="admin-official-import-requests__panel">
                <h3>{{ osu_trans('admin.official_import_requests.show.score_samples') }}</h3>
                <div class="admin-official-import-requests__table admin-official-import-requests__table--three">
                    <div class="admin-official-import-requests__table-header">
                        <span>{{ osu_trans('admin.official_import_requests.show.mode') }}</span>
                        <span>{{ osu_trans('admin.official_import_requests.show.kind') }}</span>
                        <span>{{ osu_trans('admin.official_import_requests.show.score_count') }}</span>
                    </div>
                    @forelse ($scoreRows as $scoreRow)
                        <div class="admin-official-import-requests__table-row">
                            <span>{{ $scoreRow['mode'] }}</span>
                            <span>{{ $scoreRow['kind'] }}</span>
                            <span>{{ $scoreRow['count'] }}</span>
                        </div>
                    @empty
                        <div class="admin-official-import-requests__table-row">
                            <span>-</span><span>-</span><span>-</span>
                        </div>
                    @endforelse
                </div>
            </section>

            <section class="admin-official-import-requests__panel">
                <h3>{{ osu_trans('admin.official_import_requests.show.review_decision') }}</h3>
                @if ($officialImportRequest->status === \App\Models\M1pposuAccountImportRequest::STATUS_PENDING)
                    <div class="admin-official-import-requests__review-actions">
                        <form id="approve" class="admin-official-import-requests__review-form" action="{{ route('admin.official-import-requests.approve', $officialImportRequest) }}" method="POST">
                            @csrf
                            <h4>{{ osu_trans('admin.official_import_requests.show.approve') }}</h4>
                            <textarea name="decision_note" class="input-text input-text--textarea" placeholder="{{ osu_trans('admin.official_import_requests.show.note_placeholder') }}"></textarea>
                            <button class="btn-osu-big" type="submit">{{ osu_trans('admin.official_import_requests.show.approve') }}</button>
                        </form>

                        <form id="deny" class="admin-official-import-requests__review-form" action="{{ route('admin.official-import-requests.deny', $officialImportRequest) }}" method="POST">
                            @csrf
                            <h4>{{ osu_trans('admin.official_import_requests.show.deny') }}</h4>
                            <textarea name="decision_note" class="input-text input-text--textarea" placeholder="{{ osu_trans('admin.official_import_requests.show.note_placeholder') }}"></textarea>
                            <button class="btn-osu-big btn-osu-big--danger" type="submit">{{ osu_trans('admin.official_import_requests.show.deny') }}</button>
                        </form>
                    </div>
                @else
                    <div class="admin-official-import-requests__summary">
                        <div class="admin-official-import-requests__field">
                            <div>{{ osu_trans('admin.official_import_requests.show.status') }}</div>
                            <strong>{{ $officialImportRequest->status }}</strong>
                        </div>
                        <div class="admin-official-import-requests__field">
                            <div>{{ osu_trans('admin.official_import_requests.show.reviewed_by') }}</div>
                            <strong>{{ $officialImportRequest->reviewer?->username ?? '-' }}</strong>
                        </div>
                    </div>
                @endif
            </section>

            <section class="admin-official-import-requests__panel">
                <h3>{{ osu_trans('admin.official_import_requests.show.staff_notes') }}</h3>
                <div class="admin-official-import-requests__summary">
                    <div class="admin-official-import-requests__field admin-official-import-requests__field--wide">
                        <div>{{ osu_trans('admin.official_import_requests.show.decision_note') }}</div>
                        <strong>{{ $officialImportRequest->decision_note ?? '-' }}</strong>
                    </div>
                    @if (present($officialImportRequest->failure_reason))
                        <div class="admin-official-import-requests__field admin-official-import-requests__field--wide">
                            <div>{{ osu_trans('admin.official_import_requests.show.failure_reason') }}</div>
                            <strong>{{ $officialImportRequest->failure_reason }}</strong>
                        </div>
                    @endif
                </div>
            </section>

            <details class="admin-official-import-requests__panel admin-official-import-requests__technical">
                <summary>{{ osu_trans('admin.official_import_requests.show.technical_details') }}</summary>
                <div class="admin-official-import-requests__summary">
                    <div class="admin-official-import-requests__field">
                        <div>{{ osu_trans('admin.official_import_requests.show.request_id') }}</div>
                        <strong>#{{ $officialImportRequest->getKey() }}</strong>
                    </div>
                    <div class="admin-official-import-requests__field">
                        <div>{{ osu_trans('admin.official_import_requests.show.connection_id') }}</div>
                        <strong>#{{ $officialImportRequest->connection_id }}</strong>
                    </div>
                    <div class="admin-official-import-requests__field">
                        <div>{{ osu_trans('admin.official_import_requests.show.snapshot_id') }}</div>
                        <strong>#{{ $officialImportRequest->snapshot_id }}</strong>
                    </div>
                    <div class="admin-official-import-requests__field">
                        <div>{{ osu_trans('admin.official_import_requests.show.captured_at') }}</div>
                        <strong>{{ $data['captured_at'] ?? '-' }}</strong>
                    </div>
                </div>
            </details>
        </div>
    </div>
@endsection
