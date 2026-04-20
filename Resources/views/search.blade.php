@extends('layouts.app')

@section('title', __('adamsmartsearchui::messages.page_title'))

@section('content')
<div class="container">
	<h2 class="page-heading">{{ __('adamsmartsearchui::messages.heading') }}</h2>

    @if ($error)
        <div class="alert alert-danger">{{ $error }}</div>
    @endif

	<div class="panel panel-default">
		<div class="panel-body">
			<form method="GET" action="{{ route('adamsmartsearchui.search') }}" class="form-horizontal adamsmartsearch-form">
        <div class="form-group">
            <label class="col-sm-2 control-label">{{ __('adamsmartsearchui::messages.query') }}</label>
            <div class="col-sm-10">
				<input type="text" class="form-control" name="q" value="{{ $q }}" placeholder="{{ __('adamsmartsearchui::messages.query_placeholder') }}" autofocus>
				<p class="help-block">{{ __('adamsmartsearchui::messages.query_tip') }}</p>
            </div>
        </div>

        <div class="form-group">
            <label class="col-sm-2 control-label">{{ __('adamsmartsearchui::messages.mailbox') }}</label>
            <div class="col-sm-4">
                <select class="form-control" name="mailbox_id">
                    <option value="0">{{ __('adamsmartsearchui::messages.all_accessible_mailboxes') }}</option>
                    @foreach (Auth::user()->mailboxesCanView(true) as $mb)
                        <option value="{{ $mb->id }}" @if($mailboxId == $mb->id) selected @endif>{{ $mb->name }}</option>
                    @endforeach
                </select>
            </div>

            <label class="col-sm-2 control-label">{{ __('adamsmartsearchui::messages.field') }}</label>
            <div class="col-sm-4">
                <select class="form-control" name="field_id">
                    <option value="0">{{ __('adamsmartsearchui::messages.any_custom_field') }}</option>
                    @foreach ($fields as $f)
                        <option value="{{ $f['id'] }}" @if($fieldId == $f['id']) selected @endif>{{ $f['name'] }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="col-sm-2 control-label">{{ __('adamsmartsearchui::messages.sort') }}</label>
            <div class="col-sm-4">
                <select class="form-control" name="sort">
                    <option value="updated_desc" @if(($sort ?? 'updated_desc') === 'updated_desc') selected @endif>{{ __('adamsmartsearchui::messages.updated_newest') }}</option>
                    <option value="updated_asc" @if(($sort ?? 'updated_desc') === 'updated_asc') selected @endif>{{ __('adamsmartsearchui::messages.updated_oldest') }}</option>
                    <option value="id_desc" @if(($sort ?? 'updated_desc') === 'id_desc') selected @endif>{{ __('adamsmartsearchui::messages.ticket_highest') }}</option>
                    <option value="id_asc" @if(($sort ?? 'updated_desc') === 'id_asc') selected @endif>{{ __('adamsmartsearchui::messages.ticket_lowest') }}</option>
                </select>
            </div>

            <label class="col-sm-2 control-label">{{ __('adamsmartsearchui::messages.status') }}</label>
            <div class="col-sm-4">
                <select class="form-control" name="status">
                    <option value="-1" @if(($status ?? -1) === -1) selected @endif>{{ __('adamsmartsearchui::messages.any_status') }}</option>
                    @foreach (($statusOptions ?? []) as $code => $label)
                        <option value="{{ $code }}" @if((int)($status ?? -1) === (int)$code) selected @endif>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="form-group">
            @if (!empty($foldersOk) && !empty($folders) && is_array($folders))
                <label class="col-sm-2 control-label">{{ __('adamsmartsearchui::messages.folder') }}</label>
                <div class="col-sm-4">
                    <select class="form-control" name="folder_id">
                        <option value="0" @if((int)($folderId ?? 0) === 0) selected @endif>{{ __('adamsmartsearchui::messages.any_folder') }}</option>
                        @foreach ($folders as $f)
                            <option value="{{ $f['id'] }}" @if((int)($folderId ?? 0) === (int)$f['id']) selected @endif>{{ $f['name'] }}</option>
                        @endforeach
                    </select>
                </div>
            @else
                <label class="col-sm-2 control-label">{{ __('adamsmartsearchui::messages.folder') }}</label>
                <div class="col-sm-4">
                    <select class="form-control" name="folder_id" disabled>
                        <option value="0">{{ __('adamsmartsearchui::messages.any_folder') }}</option>
                    </select>
                </div>
            @endif

            <label class="col-sm-2 control-label">{{ __('adamsmartsearchui::messages.assignee') }}</label>
            <div class="col-sm-4">
                <select class="form-control" name="assignee_id">
                    <option value="0" @if((int)($assigneeId ?? 0) === 0) selected @endif>{{ __('adamsmartsearchui::messages.any_assignee') }}</option>
                    <option value="-1" @if((int)($assigneeId ?? 0) === -1) selected @endif>{{ __('adamsmartsearchui::messages.unassigned') }}</option>
                    @foreach (($assignees ?? []) as $assignee)
                        <option value="{{ $assignee['id'] }}" @if((int)($assigneeId ?? 0) === (int)$assignee['id']) selected @endif>{{ $assignee['name'] }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="form-group">
            <div class="col-sm-offset-2 col-sm-10">
                <button type="submit" class="btn btn-primary">{{ __('adamsmartsearchui::messages.search') }}</button>
            </div>
        </div>
			</form>
		</div>
	</div>

    @if ($q && mb_strlen($q) < (int)config('adamsmartsearchui.min_query_len', 2))
        <div class="alert alert-warning">{{ __('adamsmartsearchui::messages.query_too_short') }}</div>
    @endif

    @if (!$error && ($q || ($mode ?? '') === 'recent'))
        <div class="adamsmartsearch-results-meta">
            @if (($mode ?? '') === 'recent')
                <strong>{{ __('adamsmartsearchui::messages.recent_conversations') }}</strong>
                <span class="text-muted">&mdash; {{ $total }} total</span>
            @else
                <strong>{{ $total }}</strong> {{ trans_choice('adamsmartsearchui::messages.results_count_text', (int)$total) }}
                @if ($total > 0)
                    <span class="text-muted">{{ __('adamsmartsearchui::messages.showing_on_page', ['count' => count($results)]) }}</span>
                @endif
            @endif
        </div>

		@if (!count($results) && ($mode ?? '') !== 'recent' && mb_strlen($q) >= (int)config('adamsmartsearchui.min_query_len', 2))
			<div class="alert alert-info">{{ __('adamsmartsearchui::messages.no_matches') }}</div>
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
					<th class="adamsmartsearchui-col-conversation">
                            <a href="{{ route('adamsmartsearchui.search', array_merge($baseSort, ['sort' => $nextSortId])) }}">{{ __('adamsmartsearchui::messages.conversation') }}{{ $idArrow }}</a>
                        </th>
                        <th>{{ __('adamsmartsearchui::messages.subject') }}</th>
                        @if ($fieldId && !empty($selectedField))
                            <th class="adamsmartsearchui-col-field">{{ $selectedField['name'] }}</th>
                        @endif
					<th class="adamsmartsearchui-col-status">{{ __('adamsmartsearchui::messages.status') }}</th>
					<th>
                            <a href="{{ route('adamsmartsearchui.search', array_merge($baseSort, ['sort' => $nextSortUpdated])) }}">{{ __('adamsmartsearchui::messages.updated') }}{{ $updatedArrow }}</a>
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
						<td class="adamsmartsearchui-col-conversation">
							<a href="{{ route('conversations.view', ['id' => $r['id']]) }}">#{{ $r['id'] }}</a>
						</td>
						@php
                                $subject_raw = (string)($r['subject'] ?? '');
                                $subject_parts = [$subject_raw];
                                if ($q_re && mb_strlen($q_trim) >= 2) {
                                    $subject_parts = preg_split($q_re, $subject_raw, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$subject_raw];
                                }
                            @endphp
						<td>
                                <a href="{{ route('conversations.view', ['id' => $r['id']]) }}">
                                    @foreach ($subject_parts as $subject_part)
                                        @if ($q_re && preg_match($q_re, $subject_part))
                                            <span class="adamsmartsearchui-hl">{{ $subject_part }}</span>
                                        @else
                                            {{ $subject_part }}
                                        @endif
                                    @endforeach
                                </a>
                            </td>
                            @if ($fieldId && !empty($selectedField))
                                <td>{{ $r['field_value'] ?? '' }}</td>
                            @endif
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
                            <td>{{ \App\User::dateFormat($r['updated_at'], 'Y-m-d H:i') }}</td>
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
							<a href="{{ route('adamsmartsearchui.search', array_merge($base, ['page' => $page-1])) }}">&larr; {{ __('adamsmartsearchui::messages.prev') }}</a>
                        @else
							<span class="text-muted">&larr; {{ __('adamsmartsearchui::messages.prev') }}</span>
                        @endif
                    </li>
                    <li class="next @if(!$hasNext) disabled @endif">
                        @if($hasNext)
							<a href="{{ route('adamsmartsearchui.search', array_merge($base, ['page' => $page+1])) }}">{{ __('adamsmartsearchui::messages.next') }} &rarr;</a>
                        @else
							<span class="text-muted">{{ __('adamsmartsearchui::messages.next') }} &rarr;</span>
                        @endif
                    </li>
                </ul>
            </nav>
        @endif
    @endif
</div>
@endsection
