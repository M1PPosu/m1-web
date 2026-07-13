Hi {{ $importRequest->user->username }},

Your official osu! account import request has been reviewed and denied.

@if (present($importRequest->decision_note))
Reason:
{{ $importRequest->decision_note }}
@endif

Your M1PPosu account connection remains in place, but no official osu! account data has been imported.

Thanks,
M1PPosu Trust & Safety

@include('emails._signature')
