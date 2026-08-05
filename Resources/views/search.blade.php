@extends('layouts.app')

@section('title', __('adamsmartsearchui::messages.page_title'))

@section('content')
<div class="container adamsmartsearchui-page">
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
                        <input
                            type="search"
                            class="form-control"
                            name="q"
                            value="{{ $q }}"
                            placeholder="{{ __('adamsmartsearchui::messages.query_placeholder') }}"
                            autocomplete="off"
                            autofocus
                        >
                        <p class="help-block">{{ __('adamsmartsearchui::messages.query_tip') }}</p>
                    </div>
                </div>

                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ __('adamsmartsearchui::messages.mailbox') }}</label>
                    <div class="col-sm-4">
                        <select class="form-control" name="mailbox_id">
                            <option value="0">{{ __('adamsmartsearchui::messages.all_accessible_mailboxes') }}</option>
                            @foreach (($mailboxes ?? collect()) as $mb)
                                <option value="{{ $mb->id }}" @if((int)$mailboxId === (int)$mb->id) selected @endif>{{ $mb->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <label class="col-sm-2 control-label">{{ __('adamsmartsearchui::messages.field') }}</label>
                    <div class="col-sm-4">
                        <select class="form-control" name="field_id">
                            <option value="0">{{ __('adamsmartsearchui::messages.any_custom_field') }}</option>
                            @foreach ($fields as $f)
                                <option value="{{ $f['id'] }}" @if((int)$fieldId === (int)$f['id']) selected @endif>{{ $f['name'] }}</option>
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
                            <option value="-1" @if((int)($status ?? -1) === -1) selected @endif>{{ __('adamsmartsearchui::messages.any_status') }}</option>
                            @foreach (($statusOptions ?? []) as $code => $label)
                                <option value="{{ $code }}" @if((int)($status ?? -1) === (int)$code) selected @endif>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ __('adamsmartsearchui::messages.folder') }}</label>
                    <div class="col-sm-4">
                        <select class="form-control" name="folder_id" @if(empty($foldersOk)) disabled @endif>
                            <option value="0" @if((int)($folderId ?? 0) === 0) selected @endif>{{ __('adamsmartsearchui::messages.any_folder') }}</option>
                            @if (!empty($foldersOk) && !empty($folders) && is_array($folders))
                                @foreach ($folders as $f)
                                    <option value="{{ $f['id'] }}" @if((int)($folderId ?? 0) === (int)$f['id']) selected @endif>{{ $f['name'] }}</option>
                                @endforeach
                            @endif
                        </select>
                    </div>

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
                <span class="text-muted">&mdash; {{ __('adamsmartsearchui::messages.total_count', ['count' => $total]) }}</span>
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
                $bulkNoteMaxLength = max(100, min(100000, (int)config('adamsmartsearchui.bulk_note_max_length', 50000)));
            @endphp

            <form
                method="POST"
                action="{{ route('adamsmartsearchui.bulk') }}"
                id="adamsmartsearchui-bulk-form"
                class="adamsmartsearchui-bulk-form"
                data-count-template="{{ __('adamsmartsearchui::messages.bulk_selected_count', ['count' => '__COUNT__']) }}"
                data-select-one-message="{{ __('adamsmartsearchui::messages.bulk_select_required') }}"
                data-action-message="{{ __('adamsmartsearchui::messages.bulk_action_required') }}"
                data-assignee-message="{{ __('adamsmartsearchui::messages.bulk_assignee_required') }}"
                data-status-message="{{ __('adamsmartsearchui::messages.bulk_status_required') }}"
                data-note-message="{{ __('adamsmartsearchui::messages.bulk_note_required') }}"
                data-processing-label="{{ __('adamsmartsearchui::messages.bulk_processing') }}"
            >
                <input type="hidden" name="_token" value="{{ csrf_token() }}">

                <div class="adamsmartsearchui-bulk-toolbar">
                    <label class="checkbox-inline adamsmartsearchui-bulk-select-visible">
                        <input
                            type="checkbox"
                            class="adamsmartsearchui-check-all"
                            aria-label="{{ __('adamsmartsearchui::messages.bulk_select_visible') }}"
                        >
                        {{ __('adamsmartsearchui::messages.bulk_select_visible_short') }}
                    </label>

                    <select
                        class="form-control input-sm adamsmartsearchui-bulk-action"
                        name="bulk_action"
                        aria-label="{{ __('adamsmartsearchui::messages.bulk_action') }}"
                    >
                        <option value="">{{ __('adamsmartsearchui::messages.bulk_choose_action') }}</option>
                        <option value="assign">{{ __('adamsmartsearchui::messages.bulk_assign') }}</option>
                        <option value="status">{{ __('adamsmartsearchui::messages.bulk_update_status') }}</option>
                        <option value="note">{{ __('adamsmartsearchui::messages.bulk_add_note') }}</option>
                    </select>

                    <select
                        class="form-control input-sm adamsmartsearchui-bulk-target adamsmartsearchui-bulk-target-assign"
                        name="bulk_assignee_id"
                        aria-label="{{ __('adamsmartsearchui::messages.assignee') }}"
                        disabled
                    >
                        <option value="0">{{ __('adamsmartsearchui::messages.bulk_choose_assignee') }}</option>
                        <option value="-1">{{ __('adamsmartsearchui::messages.unassigned') }}</option>
                        @foreach (($bulkAssignableUsers ?? []) as $bulkUser)
                            <option value="{{ $bulkUser['id'] }}">{{ $bulkUser['name'] }}</option>
                        @endforeach
                    </select>

                    <select
                        class="form-control input-sm adamsmartsearchui-bulk-target adamsmartsearchui-bulk-target-status"
                        name="bulk_status"
                        aria-label="{{ __('adamsmartsearchui::messages.status') }}"
                        disabled
                    >
                        <option value="0">{{ __('adamsmartsearchui::messages.bulk_choose_status') }}</option>
                        @foreach (($bulkStatusOptions ?? []) as $bulkStatusCode => $bulkStatusLabel)
                            <option value="{{ $bulkStatusCode }}">{{ $bulkStatusLabel }}</option>
                        @endforeach
                    </select>

                    <input
                        type="text"
                        class="form-control input-sm adamsmartsearchui-bulk-target adamsmartsearchui-bulk-target-note"
                        name="bulk_note"
                        value=""
                        maxlength="{{ $bulkNoteMaxLength }}"
                        placeholder="{{ __('adamsmartsearchui::messages.bulk_note_placeholder') }}"
                        aria-label="{{ __('adamsmartsearchui::messages.bulk_note') }}"
                        autocomplete="off"
                        disabled
                    >

                    <button type="submit" class="btn btn-primary btn-sm adamsmartsearchui-bulk-submit" disabled>
                        {{ __('adamsmartsearchui::messages.bulk_apply') }}
                    </button>
                    <span class="text-muted adamsmartsearchui-bulk-count" aria-live="polite">
                        {{ __('adamsmartsearchui::messages.bulk_selected_count', ['count' => 0]) }}
                    </span>
                </div>

                <div class="table-responsive adamsmartsearchui-results-wrap">
                    <table class="table table-striped table-hover adamsmartsearchui-results-table">
                        <thead>
                            <tr>
                                <th class="adamsmartsearchui-col-check" scope="col"></th>
                                <th class="adamsmartsearchui-col-conversation" scope="col">
                                    <a href="{{ route('adamsmartsearchui.search', array_merge($baseSort, ['sort' => $nextSortId])) }}">
                                        {{ __('adamsmartsearchui::messages.conversation') }}{{ $idArrow }}
                                    </a>
                                </th>
                                <th scope="col">{{ __('adamsmartsearchui::messages.subject') }}</th>
                                @if ($fieldId && !empty($selectedField))
                                    <th class="adamsmartsearchui-col-field" scope="col">{{ $selectedField['name'] }}</th>
                                @endif
                                <th class="adamsmartsearchui-col-status" scope="col">{{ __('adamsmartsearchui::messages.status') }}</th>
                                <th scope="col">
                                    <a href="{{ route('adamsmartsearchui.search', array_merge($baseSort, ['sort' => $nextSortUpdated])) }}">
                                        {{ __('adamsmartsearchui::messages.updated') }}{{ $updatedArrow }}
                                    </a>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $qTrim = trim((string)$q);
                                $qRegex = $qTrim ? '/(' . preg_quote($qTrim, '/') . ')/i' : null;
                            @endphp
                            @foreach ($results as $result)
                                @php
                                    $stateId = (int)($result['state'] ?? 0);
                                    $deletedState = defined('App\Conversation::STATE_DELETED')
                                        ? (int)constant('App\Conversation::STATE_DELETED')
                                        : 3;
                                    $isDeleted = ($stateId > 0 && $stateId === $deletedState);

                                    $subjectRaw = trim((string)($result['subject'] ?? ''));
                                    if ($subjectRaw === '') {
                                        $subjectRaw = __('adamsmartsearchui::messages.no_subject');
                                    }
                                    $subjectParts = [$subjectRaw];
                                    if ($qRegex && mb_strlen($qTrim) >= 2) {
                                        $subjectParts = preg_split(
                                            $qRegex,
                                            $subjectRaw,
                                            -1,
                                            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
                                        ) ?: [$subjectRaw];
                                    }

                                    $statusName = (string)($result['status_name'] ?? '');
                                    $statusClass = (string)($result['status_class'] ?? 'default');
                                @endphp
                                <tr>
                                    <td class="adamsmartsearchui-col-check">
                                        <input
                                            type="checkbox"
                                            class="adamsmartsearchui-row-check"
                                            name="conversation_ids[]"
                                            value="{{ $result['id'] }}"
                                            aria-label="{{ __('adamsmartsearchui::messages.bulk_select_conversation', ['id' => $result['id']]) }}"
                                            @if($isDeleted) disabled @endif
                                        >
                                    </td>
                                    <td class="adamsmartsearchui-col-conversation">
                                        <a href="{{ route('conversations.view', ['id' => $result['id']]) }}">#{{ $result['id'] }}</a>
                                    </td>
                                    <td>
                                        <a href="{{ route('conversations.view', ['id' => $result['id']]) }}">
                                            @foreach ($subjectParts as $subjectPart)
                                                @if ($qRegex && preg_match($qRegex, $subjectPart))
                                                    <span class="adamsmartsearchui-hl">{{ $subjectPart }}</span>
                                                @else
                                                    {{ $subjectPart }}
                                                @endif
                                            @endforeach
                                        </a>
                                    </td>
                                    @if ($fieldId && !empty($selectedField))
                                        <td>{{ $result['field_value'] ?? '' }}</td>
                                    @endif
                                    <td>
                                        @if ($statusName)
                                            <span class="label label-{{ $statusClass }}">{{ $statusName }}</span>
                                        @endif
                                    </td>
                                    <td>{{ \App\User::dateFormat($result['updated_at'], 'Y-m-d H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @php
                    $hasPrev = $page > 1;
                    $hasNext = ($page * $perPage) < $total;
                    $base = request()->except('page');
                @endphp
                <nav class="adamsmartsearch-pager" aria-label="{{ __('adamsmartsearchui::messages.pagination') }}">
                    <ul class="pager">
                        <li class="previous @if(!$hasPrev) disabled @endif">
                            @if($hasPrev)
                                <a href="{{ route('adamsmartsearchui.search', array_merge($base, ['page' => $page - 1])) }}">&larr; {{ __('adamsmartsearchui::messages.prev') }}</a>
                            @else
                                <span class="text-muted">&larr; {{ __('adamsmartsearchui::messages.prev') }}</span>
                            @endif
                        </li>
                        <li class="next @if(!$hasNext) disabled @endif">
                            @if($hasNext)
                                <a href="{{ route('adamsmartsearchui.search', array_merge($base, ['page' => $page + 1])) }}">{{ __('adamsmartsearchui::messages.next') }} &rarr;</a>
                            @else
                                <span class="text-muted">{{ __('adamsmartsearchui::messages.next') }} &rarr;</span>
                            @endif
                        </li>
                    </ul>
                </nav>
            </form>
        @endif
    @endif
</div>
@endsection
