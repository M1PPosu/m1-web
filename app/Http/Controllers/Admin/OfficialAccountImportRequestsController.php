<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Libraries\M1pposu\OfficialAccountImportService;
use App\Libraries\M1pposu\OfficialImportDiscordNotifier;
use App\Mail\M1pposuOfficialImportApproved;
use App\Mail\M1pposuOfficialImportDenied;
use App\Models\M1pposuAccountImportRequest;
use Carbon\Carbon;
use DB;
use Log;
use Mail;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class OfficialAccountImportRequestsController extends Controller
{
    public function approve(
        M1pposuAccountImportRequest $officialImportRequest,
        OfficialAccountImportService $importService,
        OfficialImportDiscordNotifier $discord,
    )
    {
        $officialImportRequest = $this->findOfficialImportRequest($officialImportRequest);

        abort_unless($officialImportRequest->status === M1pposuAccountImportRequest::STATUS_PENDING, 422);

        $note = presence(get_string(request('decision_note')));

        try {
            $result = DB::transaction(function () use ($officialImportRequest, $importService, $note) {
                $request = $officialImportRequest->lockSelf();
                abort_unless($request->status === M1pposuAccountImportRequest::STATUS_PENDING, 422);

                $result = $importService->apply($request->snapshot, auth()->user());

                $request->update([
                    'applied_at' => Carbon::now(),
                    'decision_note' => $note,
                    'reviewed_at' => Carbon::now(),
                    'reviewed_by' => auth()->id(),
                    'status' => M1pposuAccountImportRequest::STATUS_APPLIED,
                ]);

                return $result;
            });

            Mail::to($officialImportRequest->user->user_email)
                ->locale($officialImportRequest->user->preferredLocale())
                ->send(new M1pposuOfficialImportApproved($officialImportRequest, $result));
            $discord->connectionEvent('admin approved request', $officialImportRequest->connection, $officialImportRequest->fresh(), auth()->user());
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::warning('Official osu! import approval failed.', [
                'class' => $exception::class,
                'official_user_id' => $officialImportRequest->official_user_id,
                'request_id' => $officialImportRequest->getKey(),
                'user_id' => $officialImportRequest->user_id,
            ]);

            $officialImportRequest->update([
                'decision_note' => $note,
                'failure_reason' => "Import failed during approval. Check application logs for request #{$officialImportRequest->getKey()}.",
                'reviewed_at' => Carbon::now(),
                'reviewed_by' => auth()->id(),
                'status' => M1pposuAccountImportRequest::STATUS_FAILED,
            ]);
            $discord->connectionEvent('import failed', $officialImportRequest->connection, $officialImportRequest->fresh(), auth()->user());

            throw $exception;
        }

        return redirect(route('admin.official-import-requests.show', $officialImportRequest));
    }

    public function deny(M1pposuAccountImportRequest $officialImportRequest, OfficialImportDiscordNotifier $discord)
    {
        $officialImportRequest = $this->findOfficialImportRequest($officialImportRequest);

        abort_unless($officialImportRequest->status === M1pposuAccountImportRequest::STATUS_PENDING, 422);

        DB::transaction(function () use ($officialImportRequest) {
            $request = $officialImportRequest->lockSelf();
            abort_unless($request->status === M1pposuAccountImportRequest::STATUS_PENDING, 422);

            $request->update([
                'decision_note' => presence(get_string(request('decision_note'))),
                'reviewed_at' => Carbon::now(),
                'reviewed_by' => auth()->id(),
                'status' => M1pposuAccountImportRequest::STATUS_DENIED,
            ]);
        });

        Mail::to($officialImportRequest->user->user_email)
            ->locale($officialImportRequest->user->preferredLocale())
            ->send(new M1pposuOfficialImportDenied($officialImportRequest));
        $discord->connectionEvent('admin denied request', $officialImportRequest->connection, $officialImportRequest->fresh(), auth()->user());

        return redirect(route('admin.official-import-requests.show', $officialImportRequest));
    }

    public function index()
    {
        return redirect(route('admin.imported-accounts.index'));
    }

    public function show(M1pposuAccountImportRequest $officialImportRequest)
    {
        $officialImportRequest = $this->findOfficialImportRequest($officialImportRequest, [
            'connection',
            'reviewer',
            'snapshot.scoreSummaries',
            'user',
        ]);

        return ext_view('admin.official_import_requests.show', compact('officialImportRequest'));
    }

    private function findOfficialImportRequest(
        M1pposuAccountImportRequest $officialImportRequest,
        array $with = [],
    ): M1pposuAccountImportRequest {
        $routeParam = request()->route('officialImportRequest');
        $routeParamId = $routeParam instanceof M1pposuAccountImportRequest
            ? $routeParam->getKey()
            : $routeParam;
        $requestId = get_int($officialImportRequest->getKey())
            ?? get_int($routeParamId)
            ?? get_int(request()->segment(3));

        return M1pposuAccountImportRequest::with($with)->findOrFail($requestId);
    }
}
