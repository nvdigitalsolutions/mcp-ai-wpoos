{{ count($rows) }} machine credential(s) expire within {{ $days }} day(s).

Rotate each before it lapses: nvoos:machine-token:rotate {id}

@foreach ($rows as $row)
#{{ $row['id'] }}  {{ $row['name'] }}
    owner:   {{ $row['owner'] }}
    scopes:  {{ $row['scopes'] }}
    expires: {{ $row['expires'] }} ({{ $row['days_left'] }} day(s) left)
@endforeach

This alert repeats only when the set of affected credentials changes, or after
24 hours. The same finding is recorded in the activity feed.
