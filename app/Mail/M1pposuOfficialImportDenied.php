<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

declare(strict_types=1);

namespace App\Mail;

use App\Models\M1pposuAccountImportRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class M1pposuOfficialImportDenied extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public M1pposuAccountImportRequest $importRequest)
    {
    }

    public function build()
    {
        return $this
            ->text('emails.m1pposu_official_import_denied')
            ->subject(osu_trans('mail.m1pposu_official_import_denied.subject'));
    }
}
