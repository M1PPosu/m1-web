Hi {{ $importRequest->user->username }},

Your official osu! account import request has been approved and applied.

Imported official statistics:
{{ implode(', ', $result['imported_statistics'] ?? []) ?: 'none' }}

Imported official score summaries:
{{ $result['imported_score_summaries'] ?? 0 }}

Native M1PPosu pp, ranks, scores, and leaderboards were not changed.

@if (!empty($result['blocked']))
Some fields could not be imported automatically:
@foreach ($result['blocked'] as $field => $reason)
{{ $field }}: {{ $reason }}
@endforeach
@endif

Thanks,
M1PPosu Trust & Safety

@include('emails._signature')
