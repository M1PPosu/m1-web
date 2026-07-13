{{--
    Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
    See the LICENCE file in the repository root for full licence text.
--}}
@extends('master', ['titlePrepend' => osu_trans('admin.imported_accounts.show.title')])

@php
    $connection = $importedAccount->connection;
    $localUser = $importedAccount->user;
    $snapshot = $importedAccount->snapshot;
    $data = $snapshot?->data ?? [];
    $officialUser = $data['user'] ?? [];
    $statistics = $data['statistics'] ?? [];
    $scores = $data['scores'] ?? [];
    $bestPlaysFetched = 0;
    $fetchFailures = 0;

    foreach ($scores as $groups) {
        $best = $groups['best'] ?? [];
        if (is_array($best) && !isset($best['_error'])) {
            $bestPlaysFetched += count($best);
        } elseif (isset($best['_error'])) {
            $fetchFailures++;
        }

        foreach ($groups as $rows) {
            if (is_array($rows) && isset($rows['_error'])) {
                $fetchFailures++;
            }
        }
    }

    foreach (($data['beatmapsets'] ?? []) as $rows) {
        if (is_array($rows) && isset($rows['_error'])) {
            $fetchFailures++;
        }
    }

    $favouriteBeatmapsets = array_filter($data['beatmapsets']['favourite'] ?? [], fn ($item) => is_array($item) && !isset($item['_error']));
    $mostPlayedBeatmapsets = array_filter($data['beatmapsets']['most_played'] ?? [], fn ($item) => is_array($item) && !isset($item['_error']));
    $scoreSummaryCount = $snapshot?->scoreSummaries?->count() ?? 0;
@endphp

@section('content')
    @include('admin._header')
    <div class="osu-page osu-page--admin">
        <div class="admin-imported-accounts">
            <div class="admin-imported-accounts__toolbar">
                <h2 class="title">{{ osu_trans('admin.imported_accounts.show.title') }} #{{ $importedAccount->getKey() }}</h2>
                <a class="admin-imported-accounts__action" href="{{ route('admin.imported-accounts.index') }}">
                    {{ osu_trans('common.buttons.back') }}
                </a>
            </div>

            <section class="admin-imported-accounts__panel">
                <h3>{{ osu_trans('admin.imported_accounts.show.local_account') }}</h3>
                <div class="admin-imported-accounts__summary">
                    <div class="admin-imported-accounts__field">
                        <div>{{ osu_trans('admin.imported_accounts.show.user') }}</div>
                        <strong>
                            @if ($localUser !== null)
                                <a href="{{ $localUser->url() }}">{{ $localUser->username }}</a>
                            @else
                                #{{ $importedAccount->user_id }}
                            @endif
                        </strong>
                        <span>#{{ $importedAccount->user_id }}</span>
                    </div>
                    <div class="admin-imported-accounts__field">
                        <div>{{ osu_trans('admin.imported_accounts.show.local_status') }}</div>
                        <strong>{{ $localUser?->isRestricted() ? osu_trans('admin.imported_accounts.show.restricted') : osu_trans('admin.imported_accounts.show.unrestricted') }}</strong>
                    </div>
                </div>
            </section>

            <section class="admin-imported-accounts__panel">
                <h3>{{ osu_trans('admin.imported_accounts.show.official_account') }}</h3>
                <div class="admin-imported-accounts__summary">
                    <div class="admin-imported-accounts__field">
                        <div>{{ osu_trans('admin.imported_accounts.show.official_user') }}</div>
                        <strong>
                            @if ($connection !== null)
                                <a href="{{ $connection->officialUrl() }}">{{ $connection->username }}</a>
                            @else
                                #{{ $importedAccount->official_user_id }}
                            @endif
                        </strong>
                        <span>#{{ $importedAccount->official_user_id }}</span>
                    </div>
                    <div class="admin-imported-accounts__field">
                        <div>{{ osu_trans('admin.imported_accounts.show.snapshot_user') }}</div>
                        <strong>{{ $officialUser['username'] ?? '-' }}</strong>
                        <span>#{{ $officialUser['id'] ?? $importedAccount->official_user_id }}</span>
                    </div>
                    <div class="admin-imported-accounts__field">
                        <div>{{ osu_trans('admin.imported_accounts.show.restricted_flag') }}</div>
                        <strong>{{ $importedAccount->restricted_at_request ? osu_trans('admin.imported_accounts.show.flagged') : '-' }}</strong>
                    </div>
                </div>
            </section>

            <section class="admin-imported-accounts__panel">
                <h3>{{ osu_trans('admin.imported_accounts.show.import_status') }}</h3>
                <div class="admin-imported-accounts__summary">
                    <div class="admin-imported-accounts__field">
                        <div>{{ osu_trans('admin.imported_accounts.show.status') }}</div>
                        <strong class="admin-imported-accounts__status admin-imported-accounts__status--{{ $importedAccount->status }}">{{ $importedAccount->status }}</strong>
                    </div>
                    <div class="admin-imported-accounts__field">
                        <div>{{ osu_trans('admin.imported_accounts.show.imported_at') }}</div>
                        <strong>{!! $importedAccount->applied_at === null ? '-' : timeago($importedAccount->applied_at) !!}</strong>
                    </div>
                    <div class="admin-imported-accounts__field">
                        <div>{{ osu_trans('admin.imported_accounts.show.removed_at') }}</div>
                        <strong>{!! $importedAccount->removed_at === null ? '-' : timeago($importedAccount->removed_at) !!}</strong>
                    </div>
                    <div class="admin-imported-accounts__field">
                        <div>{{ osu_trans('admin.imported_accounts.show.restored_at') }}</div>
                        <strong>{!! $importedAccount->restored_at === null ? '-' : timeago($importedAccount->restored_at) !!}</strong>
                    </div>
                </div>
            </section>

            <section class="admin-imported-accounts__panel">
                <h3>{{ osu_trans('admin.imported_accounts.show.imported_data_summary') }}</h3>
                <div class="admin-imported-accounts__summary">
                    <div class="admin-imported-accounts__field">
                        <div>{{ osu_trans('admin.imported_accounts.show.score_summaries') }}</div>
                        <strong>{{ $scoreSummaryCount }}</strong>
                    </div>
                    <div class="admin-imported-accounts__field">
                        <div>{{ osu_trans('admin.imported_accounts.show.best_plays') }}</div>
                        <strong>{{ $bestPlaysFetched }}</strong>
                    </div>
                    <div class="admin-imported-accounts__field">
                        <div>{{ osu_trans('admin.imported_accounts.show.favourites') }}</div>
                        <strong>{{ count($favouriteBeatmapsets) }}</strong>
                    </div>
                    <div class="admin-imported-accounts__field">
                        <div>{{ osu_trans('admin.imported_accounts.show.most_played') }}</div>
                        <strong>{{ count($mostPlayedBeatmapsets) }}</strong>
                    </div>
                    <div class="admin-imported-accounts__field">
                        <div>{{ osu_trans('admin.imported_accounts.show.missing_data') }}</div>
                        <strong>{{ $fetchFailures }}</strong>
                    </div>
                </div>
            </section>

            <section class="admin-imported-accounts__panel">
                <h3>{{ osu_trans('admin.imported_accounts.show.staff_actions') }}</h3>
                @if ($importedAccount->status === \App\Models\M1pposuAccountImportRequest::STATUS_APPLIED)
                    <form class="admin-imported-accounts__form" action="{{ route('admin.imported-accounts.remove', $importedAccount) }}" method="POST">
                        @csrf
                        <textarea name="reason" class="input-text input-text--textarea" required placeholder="{{ osu_trans('admin.imported_accounts.show.remove_reason') }}"></textarea>
                        <label class="admin-imported-accounts__confirm">
                            <input name="confirmed" value="1" required type="checkbox">
                            <span>{{ osu_trans('admin.imported_accounts.show.remove_confirm') }}</span>
                        </label>
                        <button class="btn-osu-big btn-osu-big--danger" type="submit">{{ osu_trans('admin.imported_accounts.show.remove') }}</button>
                    </form>
                @elseif ($importedAccount->isRemoved())
                    <form class="admin-imported-accounts__form" action="{{ route('admin.imported-accounts.restore', $importedAccount) }}" method="POST">
                        @csrf
                        <textarea name="reason" class="input-text input-text--textarea" required placeholder="{{ osu_trans('admin.imported_accounts.show.restore_reason') }}"></textarea>
                        <label class="admin-imported-accounts__confirm">
                            <input name="confirmed" value="1" required type="checkbox">
                            <span>{{ osu_trans('admin.imported_accounts.show.restore_confirm') }}</span>
                        </label>
                        <button class="btn-osu-big admin-imported-accounts__restore-button" type="submit">{{ osu_trans('admin.imported_accounts.show.restore') }}</button>
                    </form>
                @else
                    <div class="admin-imported-accounts__muted">{{ osu_trans('admin.imported_accounts.show.no_actions') }}</div>
                @endif
            </section>

            <section class="admin-imported-accounts__panel">
                <h3>{{ osu_trans('admin.imported_accounts.show.audit_history') }}</h3>
                <div class="admin-imported-accounts__summary">
                    <div class="admin-imported-accounts__field admin-imported-accounts__field--wide">
                        <div>{{ osu_trans('admin.imported_accounts.show.remove_reason_label') }}</div>
                        <strong>{{ $importedAccount->remove_reason ?? '-' }}</strong>
                        <span>{{ $importedAccount->removedBy?->username ?? '-' }}</span>
                    </div>
                    <div class="admin-imported-accounts__field admin-imported-accounts__field--wide">
                        <div>{{ osu_trans('admin.imported_accounts.show.restore_reason_label') }}</div>
                        <strong>{{ $importedAccount->restore_reason ?? '-' }}</strong>
                        <span>{{ $importedAccount->restoredBy?->username ?? '-' }}</span>
                    </div>
                </div>
            </section>

            <details class="admin-imported-accounts__panel admin-imported-accounts__technical">
                <summary>{{ osu_trans('admin.imported_accounts.show.technical_details') }}</summary>
                <div class="admin-imported-accounts__summary">
                    <div class="admin-imported-accounts__field">
                        <div>{{ osu_trans('admin.imported_accounts.show.request_id') }}</div>
                        <strong>#{{ $importedAccount->getKey() }}</strong>
                    </div>
                    <div class="admin-imported-accounts__field">
                        <div>{{ osu_trans('admin.imported_accounts.show.connection_id') }}</div>
                        <strong>#{{ $importedAccount->connection_id }}</strong>
                    </div>
                    <div class="admin-imported-accounts__field">
                        <div>{{ osu_trans('admin.imported_accounts.show.snapshot_id') }}</div>
                        <strong>#{{ $importedAccount->snapshot_id }}</strong>
                    </div>
                    <div class="admin-imported-accounts__field">
                        <div>{{ osu_trans('admin.imported_accounts.show.captured_at') }}</div>
                        <strong>{{ $data['captured_at'] ?? '-' }}</strong>
                    </div>
                </div>
            </details>
        </div>
    </div>
@endsection
