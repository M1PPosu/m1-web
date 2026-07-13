<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Libraries\M1pposu\OfficialAccountImportService;
use App\Libraries\M1pposu\OfficialImportDiscordNotifier;
use App\Models\M1pposuAccountImportRequest;
use DB;

class ImportedAccountsController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware('throttle:30,10')->only(['remove', 'restore']);
    }

    public function index()
    {
        $latestImportedIds = DB::table('m1pposu_account_import_requests')
            ->selectRaw('MAX(id)')
            ->whereIn('status', M1pposuAccountImportRequest::IMPORTED_STATUSES)
            ->groupBy('user_id');

        $imports = M1pposuAccountImportRequest::with(['connection', 'removedBy', 'restoredBy', 'reviewer', 'user'])
            ->whereIn('id', $latestImportedIds)
            ->orderByDesc('applied_at')
            ->orderByDesc('id')
            ->paginate();

        return ext_view('admin.imported_accounts.index', compact('imports'));
    }

    public function remove(
        M1pposuAccountImportRequest $importedAccount,
        OfficialAccountImportService $importService,
        OfficialImportDiscordNotifier $discord,
    ) {
        abort_unless(get_bool(request('confirmed')), 422);

        $reason = presence(get_string(request('reason')));
        abort_if($reason === null, 422);

        $importedAccount = $this->findImportedAccount($importedAccount);
        $removed = $importService->removeByStaff($importedAccount, auth()->user(), $reason);
        $discord->connectionEvent('admin removed import', $removed->connection, $removed, auth()->user(), $reason);

        return redirect(route('admin.imported-accounts.show', $removed));
    }

    public function restore(
        M1pposuAccountImportRequest $importedAccount,
        OfficialAccountImportService $importService,
        OfficialImportDiscordNotifier $discord,
    ) {
        abort_unless(get_bool(request('confirmed')), 422);

        $reason = presence(get_string(request('reason')));
        abort_if($reason === null, 422);

        $importedAccount = $this->findImportedAccount($importedAccount);
        $restored = $importService->restore($importedAccount, auth()->user(), $reason);
        $discord->connectionEvent('admin restored import', $restored->connection, $restored, auth()->user(), $reason);

        return redirect(route('admin.imported-accounts.show', $restored));
    }

    public function show(M1pposuAccountImportRequest $importedAccount)
    {
        $importedAccount = $this->findImportedAccount($importedAccount, [
            'connection',
            'removedBy',
            'removalAccountHistory',
            'restoredBy',
            'restoreAccountHistory',
            'reviewer',
            'snapshot.scoreSummaries',
            'user',
        ]);

        return ext_view('admin.imported_accounts.show', compact('importedAccount'));
    }

    private function findImportedAccount(
        M1pposuAccountImportRequest $importedAccount,
        array $with = [],
    ): M1pposuAccountImportRequest {
        $routeParam = request()->route('importedAccount');
        $routeParamId = $routeParam instanceof M1pposuAccountImportRequest
            ? $routeParam->getKey()
            : $routeParam;
        $requestId = get_int($importedAccount->getKey()) ?? get_int($routeParamId);

        return M1pposuAccountImportRequest::with($with)
            ->whereIn('status', M1pposuAccountImportRequest::IMPORTED_STATUSES)
            ->findOrFail($requestId);
    }
}
