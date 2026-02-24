@extends('layouts.app')

@section('title', __('Search'))

@section('content')
<div class="container">
	<h2 class="page-heading">{{ __('Smart Search') }}</h2>

    @if ($error)
        <div class="alert alert-danger">{{ $error }}</div>
    @endif

	<div class="panel panel-default">
		<div class="panel-body">
			<form method="GET" action="{{ route('adamsmartsearchui.search') }}" class="form-horizontal adamsmartsearch-form">
        <div class="form-group">
            <label class="col-sm-2 control-label">{{ __('Query') }}</label>
            <div class="col-sm-10">
				<input type="text" class="form-control" name="q" value="{{ $q }}" placeholder="{{ __('Search conversations, customers, custom fields…') }}" autofocus>
				<p class="help-block">{{ __('Tip: type #1234 to jump to a conversation (conversation/thread ID).') }}</p>
            </div>
        </div>

        <div class="form-group">
            <label class="col-sm-2 control-label">{{ __('Mailbox') }}</label>
            <div class="col-sm-4">
                <select class="form-control" name="mailbox_id">
                    <option value="0">{{ __('All accessible mailboxes') }}</option>
                    @foreach (Auth::user()->mailboxesCanView(true) as $mb)
                        <option value="{{ $mb->id }}" @if($mailboxId == $mb->id) selected @endif>{{ $mb->name }}</option>
                    @endforeach
                </select>
            </div>

            <label class="col-sm-2 control-label">{{ __('Field') }}</label>
            <div class="col-sm-4">
                <select class="form-control" name="field_id">
                    <option value="0">{{ __('Any custom field') }}</option>
                    @foreach ($fields as $f)
                        <option value="{{ $f['id'] }}" @if($fieldId == $f['id']) selected @endif>{{ $f['name'] }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="col-sm-2 control-label">{{ __('Sort') }}</label>
            <div class="col-sm-4">
                <select class="form-control" name="sort">
                    <option value="updated_desc" @if(($sort ?? 'updated_desc') === 'updated_desc') selected @endif>{{ __('Updated: newest') }}</option>
                    <option value="updated_asc" @if(($sort ?? 'updated_desc') === 'updated_asc') selected @endif>{{ __('Updated: oldest') }}</option>
                    <option value="id_desc" @if(($sort ?? 'updated_desc') === 'id_desc') selected @endif>{{ __('Ticket #: highest') }}</option>
                    <option value="id_asc" @if(($sort ?? 'updated_desc') === 'id_asc') selected @endif>{{ __('Ticket #: lowest') }}</option>
                </select>
            </div>
        </div>

        <div class="form-group">
            <div class="col-sm-offset-2 col-sm-10">
                <button type="submit" class="btn btn-primary">{{ __('Search') }}</button>
            </div>
        </div>
			</form>
		</div>
	</div>

    @if ($q && mb_strlen($q) < (int)config('adamsmartsearchui.min_query_len', 2))
        <div class="alert alert-warning">{{ __('Query is too short.') }}</div>
    @endif

    @if (!$error && ($q || ($mode ?? '') === 'recent'))
        <div class="adamsmartsearch-results-meta">
            @if (($mode ?? '') === 'recent')
                <strong>{{ __('Recent conversations') }}</strong>
                <span class="text-muted">&mdash; {{ $total }} total</span>
            @else
                <strong>{{ $total }}</strong> result(s)
                @if ($total > 0)
                    <span class="text-muted">(showing {{ count($results) }} on this page)</span>
                @endif
            @endif
        </div>

		@if (!count($results) && ($mode ?? '') !== 'recent' && mb_strlen($q) >= (int)config('adamsmartsearchui.min_query_len', 2))
			<div class="alert alert-info">{{ __('No matching conversations found.') }}</div>
		@endif

        @if (count($results))
			@php
                $baseSort = request()->except('page');
                $sortVal = $sort ?? 'updated_desc';
                $nextSortId = ($sortVal === 'id_desc') ? 'id_asc' : 'id_desc';
                $nextSortUpdated = ($sortVal === 'updated_desc') ? 'updated_asc' : 'updated_desc';
                $idArrow = ($sortVal === 'id_desc') ? ' ↓' : (($sortVal === 'id_asc') ? ' ↑' : '');
                $updatedArrow = ($sortVal === 'updated_desc') ? ' ↓' : (($sortVal === 'updated_asc') ? ' ↑' : '');
            @endphp
			<table class="table table-striped table-hover">
                <thead>
                    <tr>
					<th style="width: 180px">
                            <a href="{{ route('adamsmartsearchui.search', array_merge($baseSort, ['sort' => $nextSortId])) }}">{{ __('Conversation') }}{!! e($idArrow) !!}</a>
                        </th>
                        <th>{{ __('Subject') }}</th>
                        <th style="width: 120px">{{ __('Status') }}</th>
					<th>
                            <a href="{{ route('adamsmartsearchui.search', array_merge($baseSort, ['sort' => $nextSortUpdated])) }}">{{ __('Updated') }}{!! e($updatedArrow) !!}</a>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $q_trim = trim((string)$q);
                        $q_re = $q_trim ? '/(' . preg_quote($q_trim, '/') . ')/i' : null;
                    @endphp
                    @foreach ($results as $r)
                        <tr>
						<td style="width: 180px">
							<a href="{{ route('conversations.view', ['id' => $r['id']]) }}">#{{ $r['id'] }}</a>
						</td>
						@php
                                $subj_escaped = e((string)($r['subject'] ?? ''));
                                if ($q_re && mb_strlen($q_trim) >= 2) {
                                    // Highlight inside escaped HTML.
                                    $subj_escaped = preg_replace($q_re, '<span class="adamsmartsearchui-hl">$1</span>', $subj_escaped);
                                }
                            @endphp
						<td><a href="{{ route('conversations.view', ['id' => $r['id']]) }}">{!! $subj_escaped !!}</a></td>
                            <td>
                                @php
                                    $status_id = (int)($r['status'] ?? 0);
                                    $status_name = method_exists('App\\Conversation', 'statusCodeToName')
                                        ? App\Conversation::statusCodeToName($status_id)
                                        : ($status_id ? ucfirst(App\Conversation::$statuses[$status_id] ?? '') : '');
                                    $status_class = App\Conversation::$status_classes[$status_id] ?? 'default';
                                @endphp
                                @if ($status_name)
                                    <span class="label label-{{ $status_class }}">{{ $status_name }}</span>
                                @endif
                            </td>
                            <td>{{ $r['updated_at'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @php
                $hasPrev = $page > 1;
                $hasNext = ($page * $perPage) < $total;
                $base = request()->except('page');
            @endphp
            <nav class="adamsmartsearch-pager">
                <ul class="pager">
                    <li class="previous @if(!$hasPrev) disabled @endif">
                        @if($hasPrev)
                            <a href="{{ route('adamsmartsearchui.search', array_merge($base, ['page' => $page-1])) }}">&larr; Prev</a>
                        @else
                            <a href="#" onclick="return false;">&larr; Prev</a>
                        @endif
                    </li>
                    <li class="next @if(!$hasNext) disabled @endif">
                        @if($hasNext)
                            <a href="{{ route('adamsmartsearchui.search', array_merge($base, ['page' => $page+1])) }}">Next &rarr;</a>
                        @else
                            <a href="#" onclick="return false;">Next &rarr;</a>
                        @endif
                    </li>
                </ul>
            </nav>
        @endif
    @endif
</div>
@endsection
