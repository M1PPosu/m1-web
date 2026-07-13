<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Libraries\M1pposu\OfficialAccountImportService;
use App\Libraries\M1pposu\OfficialImportDiscordNotifier;
use App\Libraries\M1pposu\OfficialOsuClient;
use App\Models\M1pposuAccountImportRequest;
use App\Models\User;

class OfficialOsuConnectionsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('verify-user');
        $this->middleware('throttle:10,10')->only(['callback', 'create', 'destroy', 'import', 'reimport', 'reset']);

        parent::__construct();
    }

    public function callback(OfficialOsuClient $client, OfficialAccountImportService $importService, OfficialImportDiscordNotifier $discord)
    {
        abort_unless(OfficialOsuClient::canAuthenticate(), 404);

        $params = get_params(request()->all(), null, [
            'code:string',
            'error:string',
            'state:string',
        ], ['null_missing' => true]);

        abort_if($params['state'] === null, 422, 'Missing state parameter.');
        abort_unless(
            hash_equals(session()->pull('official_osu_auth_state', ''), $params['state']),
            403,
            'Invalid state.',
        );

        if ($params['error'] === 'access_denied') {
            return redirect(route('account.edit').'#official-osu');
        }

        abort_if($params['error'] !== null, 500, 'Error obtaining authorization from official osu!.');
        abort_if($params['code'] === null, 422, 'Missing code parameter.');

        $token = $client->tokenFromCode($params['code'], route('account.official-osu.callback'));
        $officialUser = $client->me($token['access_token']);
        $connection = $importService->createOrUpdateConnection(auth()->user(), $token, $officialUser);
        $importService->createSnapshot($connection, $token['access_token'], $officialUser);

        $discord->connectionEvent(
            'Official account connected',
            $connection->fresh('user'),
        );
        \Session::flash('popup', osu_trans('accounts.official_osu.connected'));

        return redirect(route('account.edit').'#official-osu');
    }

    public function create(OfficialOsuClient $client)
    {
        abort_unless(OfficialOsuClient::canAuthenticate(), 404);

        $state = bin2hex(random_bytes(24));
        session()->put('official_osu_auth_state', $state);

        return redirect($client->authorizationUrl($state, route('account.official-osu.callback')));
    }

    public function destroy(OfficialAccountImportService $importService, OfficialImportDiscordNotifier $discord)
    {
        $connection = auth()->user()->m1pposuOfficialConnection()->with('importRequests')->firstOrFail();
        $latestStatus = $connection->importRequests()->latest()->value('status');
        $latestImportedRequest = $connection->importRequests()
            ->whereIn('status', M1pposuAccountImportRequest::IMPORTED_STATUSES)
            ->latest('id')
            ->first();

        if ($latestImportedRequest?->status === M1pposuAccountImportRequest::STATUS_APPLIED) {
            abort_unless(get_bool(request('confirmed')), 422, osu_trans('accounts.official_osu.error.remove_confirm_required'));

            $removedRequest = $importService->selfRemove($latestImportedRequest, auth()->user());
            $discord->connectionEvent('Official import removed by user', $connection->fresh('user'), $removedRequest, auth()->user(), $removedRequest->remove_reason);

            return response([
                'message' => osu_trans('accounts.official_osu.remove_removed'),
                'status' => M1pposuAccountImportRequest::STATUS_SELF_REMOVED,
            ]);
        }

        abort_if(
            $latestStatus === M1pposuAccountImportRequest::STATUS_PENDING || $latestImportedRequest?->isRemoved(),
            422,
            osu_trans('accounts.official_osu.error.unlink_locked'),
        );

        $discordConnection = $connection->fresh('user');
        $connection->delete();
        $discord->connectionEvent('Official account disconnected', $discordConnection);

        return response()->noContent();
    }

    public function reimport(OfficialAccountImportService $importService, OfficialImportDiscordNotifier $discord)
    {
        $this->ensureCanManageImport(auth()->user());

        $connection = auth()->user()->m1pposuOfficialConnection()->firstOrFail();
        $snapshot = $importService->refreshSnapshot($connection);
        $connection->refresh();

        try {
            $result = $importService->apply($snapshot, auth()->user());
            $request = $importService->createAppliedRequest($connection, $snapshot, auth()->user(), $result, true);
        } catch (\Throwable $exception) {
            $discord->connectionEvent('Official import failed', $connection->fresh('user'), null, auth()->user());

            throw $exception;
        }
        $discord->connectionEvent('Official data reimported', $connection->fresh('user'), $request, auth()->user());

        return response([
            'message' => osu_trans('accounts.official_osu.reimport_started'),
            'result' => $result,
            'status' => M1pposuAccountImportRequest::STATUS_APPLIED,
        ]);
    }

    public function reset(OfficialImportDiscordNotifier $discord)
    {
        $user = auth()->user();
        $this->ensureCanManageImport($user);

        $connection = $user->m1pposuOfficialConnection()->with('user')->firstOrFail();
        $discordConnection = $connection->fresh('user');
        $connection->delete();

        $discord->connectionEvent('Official import link reset', $discordConnection, null, $user);

        return response()->noContent();
    }

    public function import(OfficialAccountImportService $importService, OfficialImportDiscordNotifier $discord)
    {
        abort_unless(get_bool(request('confirmed')), 422, osu_trans('accounts.official_osu.error.confirm_required'));

        $connection = auth()->user()->m1pposuOfficialConnection()->firstOrFail();
        $latestStatus = $connection->importRequests()->latest()->value('status');
        $latestImportedStatus = $connection->importRequests()
            ->whereIn('status', M1pposuAccountImportRequest::IMPORTED_STATUSES)
            ->latest('id')
            ->value('status');

        abort_if(
            $latestImportedStatus === M1pposuAccountImportRequest::STATUS_SELF_REMOVED,
            422,
            osu_trans('accounts.official_osu.error.import_self_removed'),
        );

        abort_if(
            $latestImportedStatus === M1pposuAccountImportRequest::STATUS_APPLIED,
            422,
            osu_trans('accounts.official_osu.error.import_already_applied'),
        );

        abort_if(
            $importService->hasSelfRemovedImport($connection),
            422,
            osu_trans('accounts.official_osu.error.import_self_removed'),
        );

        if ($latestStatus === M1pposuAccountImportRequest::STATUS_PENDING) {
            return response([
                'message' => osu_trans('accounts.official_osu.review_requested'),
                'status' => M1pposuAccountImportRequest::STATUS_PENDING,
            ]);
        }

        $snapshot = $connection->refresh_token !== null
            ? $importService->refreshSnapshot($connection)
            : $connection->snapshots()->latest()->firstOrFail();
        $connection->refresh();

        try {
            $result = $importService->apply($snapshot, auth()->user());
            $request = $importService->createAppliedRequest($connection, $snapshot, auth()->user(), $result);
        } catch (\Throwable $exception) {
            $discord->connectionEvent('Official import failed', $connection->fresh('user'), null, auth()->user());

            throw $exception;
        }
        $discord->connectionEvent('Official data imported', $connection->fresh('user'), $request, auth()->user());

        return response([
            'message' => osu_trans('accounts.official_osu.import_started'),
            'result' => $result,
            'status' => M1pposuAccountImportRequest::STATUS_APPLIED,
        ]);
    }

    private function ensureCanManageImport(?User $user): void
    {
        abort_unless(
            $user !== null && app()->environment('local') && ($user->isAdmin() || $user->isDev()),
            403,
        );
    }
}
