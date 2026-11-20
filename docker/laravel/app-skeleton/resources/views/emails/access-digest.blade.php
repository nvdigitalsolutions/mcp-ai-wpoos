{{-- Text, not HTML: every interpolation below is unescaped because this message
     is `text/plain`. Blade's `{{ }}` HTML-escapes, which in a plain-text body is
     a corruption rather than a protection — an estate named "Smith & Sons" would
     arrive as "Smith &amp; Sons", and a reason containing an apostrophe as
     "&#039;". There is no HTML context here to inject into. --}}
Hi {!! $name !!},

{!! $summary !!} Nothing has been granted — each one is waiting for a decision.

@foreach ($rows as $row)
  {!! $row['email'] !!} — {!! $row['reason'] !!} ({!! $row['attempted_at'] !!})
@endforeach
@if ($remaining > 0)

…and {!! $remaining !!} more. The page below shows the full list.
@endif

Review and decide:

{!! $url !!}

Approving creates an account with viewer access. Dismissing grants nothing.

You are receiving this because you administer {!! $estate !!}. Turn the reminder
off in the estate's settings if you would rather check the page yourself.
