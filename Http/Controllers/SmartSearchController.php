<?php

namespace Modules\AdamSmartSearchUI\Http\Controllers;

use App\Conversation;
use App\Folder;
use App\Http\Controllers\Controller;
use App\Mailbox;
use App\Thread;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class SmartSearchController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string)$request->get('q', ''));
        $q = $this->sanitizeSearchString($q);
        $page = max(1, (int)$request->get('page', 1));
        $perPage = (int)config('adamsmartsearchui.per_page', 50);
        if ($perPage < 10) {
            $perPage = 10;
        }
        if ($perPage > 200) {
            $perPage = 200;
        }

        $mailboxId = (int)$request->get('mailbox_id', 0);
        $fieldId = (int)$request->get('field_id', 0);
        // -1 means "any"
        $status = (int)$request->get('status', -1);
        $folderId = (int)$request->get('folder_id', 0);
        $assigneeId = (int)$request->get('assignee_id', 0);
        $sort = $this->normalizeSort((string)$request->get('sort', 'updated_desc'));

        $user = Auth::user();
        $mailboxes = $user ? $user->mailboxesCanView(true) : collect();
        $allowedMailboxIds = [];
        foreach ($mailboxes as $mailbox) {
            $allowedMailboxIds[] = (int)$mailbox->id;
        }
        $allowedMailboxIds = array_values(array_unique($allowedMailboxIds));
        $assignedOnlyUserId = $this->getAssignedOnlyUserId($user);

        // If user selected a mailbox they cannot access, ignore it.
        if ($mailboxId && !in_array($mailboxId, $allowedMailboxIds, true)) {
            $mailboxId = 0;
        }

        // Validate status filter against known statuses.
        $statusOptions = $this->statusOptions();
        if ($status !== -1 && !array_key_exists($status, $statusOptions)) {
            $status = -1;
        }

        // Folder list is optional (depends on schema).
        $folders = [];
        $foldersOk = $this->foldersOk();
        if ($foldersOk) {
            $folders = $this->loadFolders($allowedMailboxIds, $mailboxId);
            if ($folderId) {
                $isValidFolder = false;
                foreach ($folders as $f) {
                    if ((int)($f['id'] ?? 0) === $folderId) {
                        $isValidFolder = true;
                        break;
                    }
                }
                if (!$isValidFolder) {
                    $folderId = 0;
                }
            }
        } else {
            $folderId = 0;
        }

        $assignees = $this->loadAssignees($allowedMailboxIds, $mailboxId, $assignedOnlyUserId);
        if (!$this->isValidAssigneeFilter($assigneeId, $assignees)) {
            $assigneeId = 0;
        }

        $schemaOk = $this->schemaOk();
        $cfOk = $this->customFieldsOk();
        $cfDefsOk = $this->customFieldDefinitionsOk();
        $error = $schemaOk ? null : __('adamsmartsearchui::messages.schema_missing');

        if ($fieldId && !$cfOk) {
            $fieldId = 0;
        }

        $fields = [];
        $selectedField = null;
        if ($cfDefsOk) {
            $fields = $this->loadFields($allowedMailboxIds, $mailboxId);

            if ($fieldId) {
                foreach ($fields as $f) {
                    if ((int)($f['id'] ?? 0) === $fieldId) {
                        $selectedField = $f;
                        break;
                    }
                }

                // If the field is not in the dropdown (edge case: mailbox filter/permissions changed), try resolving by ID.
                if (!$selectedField) {
                    $selectedField = $this->loadFieldById($allowedMailboxIds, $fieldId);
                }
            }
        }

        $results = [];
        $total = 0;
        $mode = 'search';

        // Native behavior: if user entered a conversation ID (or thread ID), jump directly to the conversation.
        // FreeScout core routes by conversations.id.
        if ($schemaOk && !$fieldId && $q !== '' && mb_strlen($q) >= (int)config('adamsmartsearchui.min_query_len', 2)) {
            $numericTicket = $this->normalizeTicketNumberExact($q);
            if ($numericTicket !== null && $this->isStrictNumericQuery($q)) {
                $ids = $allowedMailboxIds;
                if ($mailboxId) {
                    $ids = [$mailboxId];
                }
                if (count($ids)) {
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    $resolved = $this->resolveNumericConversation(
                        $numericTicket,
                        $ids,
                        $in,
                        $status,
                        $folderId,
                        $assigneeId,
                        $assignedOnlyUserId,
                        $this->isDisplayNumberQuery($q)
                    );
                    if ($resolved !== null) {
                        return redirect()->route('conversations.view', ['id' => (int)$resolved['id']]);
                    }
                }
            }
        }

        if ($schemaOk && $q !== '' && mb_strlen($q) >= (int)config('adamsmartsearchui.min_query_len', 2)) {
            [$results, $total] = $this->search(
                $q,
                $allowedMailboxIds,
                $mailboxId,
                $fieldId,
                $status,
                $folderId,
                $assigneeId,
                $assignedOnlyUserId,
                $page,
                $perPage,
                $sort
            );
            $mode = 'search';
        } elseif ($schemaOk && $q === '') {
            // No query yet: show newest conversations as a useful default.
            [$results, $total] = $this->recent(
                $allowedMailboxIds,
                $mailboxId,
                $status,
                $folderId,
                $assigneeId,
                $assignedOnlyUserId,
                $page,
                $perPage,
                $sort
            );
            $mode = 'recent';
        }

        // If a specific custom field is selected, always hydrate its value column
        // for the current results page (including "recent" mode).
        if ($fieldId && $cfOk && count($results)) {
            $results = $this->appendCustomFieldValues($results, $fieldId, $selectedField);
        }

        if (count($results)) {
            $results = $this->appendStatusMeta($results);
        }

        return view('adamsmartsearchui::search', [
            'q' => $q,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'results' => $results,
            'mode' => $mode,
            'mailboxId' => $mailboxId,
            'fieldId' => $fieldId,
            'status' => $status,
            'statusOptions' => $statusOptions,
            'folderId' => $folderId,
            'folders' => $folders,
            'foldersOk' => $foldersOk,
            'assigneeId' => $assigneeId,
            'assignees' => $assignees,
            'bulkAssignableUsers' => $this->loadAssignableUsers($allowedMailboxIds, $mailboxId),
            'bulkStatusOptions' => $this->writableStatusOptions(),
            'sort' => $sort,
            'fields' => $fields,
            'selectedField' => $selectedField,
            'allowedMailboxIds' => $allowedMailboxIds,
            'mailboxes' => $mailboxes,
            'error' => $error,
        ]);
    }

    /**
     * Default listing when query is empty: newest conversations the user can access.
     */
    protected function recent(array $allowedMailboxIds, int $mailboxId, int $status, int $folderId, int $assigneeId, int $assignedOnlyUserId, int $page, int $perPage, string $sort = 'updated_desc')
    {
        if (!count($allowedMailboxIds)) {
            return [[], 0];
        }

        $ids = $allowedMailboxIds;
        if ($mailboxId) {
            $ids = [$mailboxId];
        }
        if (!count($ids)) {
            return [[], 0];
        }

        $offset = ($page - 1) * $perPage;
        $in = implode(',', array_fill(0, count($ids), '?'));

        $where = "c.mailbox_id IN ($in)";
        $bWhere = $ids;
        $statusFilter = $this->buildStatusFilterSql($status);
        if ($statusFilter['sql'] !== '') {
            $where .= $statusFilter['sql'];
            $bWhere = array_merge($bWhere, $statusFilter['bindings']);
        }
        if ($folderId) {
            $where .= " AND c.folder_id = ?";
            $bWhere[] = $folderId;
        }
        if ($assigneeId === -1) {
            $where .= " AND (c.user_id IS NULL OR c.user_id <= 0)";
        } elseif ($assigneeId > 0) {
            $where .= " AND c.user_id = ?";
            $bWhere[] = $assigneeId;
        }

        $visibility = $this->buildAssignedVisibilitySql($assignedOnlyUserId, 'c');
        $where .= (string)$visibility['sql'];
        $bWhere = array_merge($bWhere, (array)$visibility['bindings']);

        $total = 0;
        try {
            $row = DB::selectOne("SELECT COUNT(*) as cnt FROM conversations c WHERE $where", $bWhere);
            $total = $row ? (int)$row->cnt : 0;
        } catch (\Throwable $e) {
            $total = 0;
        }

        $rows = [];
        $orderBy = $this->sortToOrderBy($this->normalizeSort($sort));
        try {
            $rows = DB::select(
                "SELECT c.id, c.subject, c.status, c.state, c.updated_at, c.mailbox_id
                 FROM conversations c
                 WHERE $where
                 ORDER BY $orderBy
                 LIMIT ? OFFSET ?",
                array_merge($bWhere, [$perPage, $offset])
            );
        } catch (\Throwable $e) {
            $rows = [];
        }

        $results = [];
        foreach ($rows as $r) {
            $results[] = [
                'id' => (int)$r->id,
                'subject' => (string)$r->subject,
                'status' => (int)$r->status,
                'state' => isset($r->state) ? (int)$r->state : 0,
                'updated_at' => (string)$r->updated_at,
                'mailbox_id' => (int)$r->mailbox_id,
            ];
        }

        return [$results, $total];
    }

    /**
     * Lightweight autosuggest endpoint for the topbar input.
     * Returns a small list of matches the user is allowed to view.
     */
    public function suggest(Request $request)
    {
        try {
            $q = trim((string)$request->get('q', ''));
            $q = $this->sanitizeSearchString($q);
            $minLen = (int)config('adamsmartsearchui.min_query_len', 2);
            if ($q === '' || mb_strlen($q) < $minLen) {
                return response()->json(['items' => []]);
            }

            $user = Auth::user();
            $allowedMailboxIds = $user ? $user->mailboxesIdsCanView() : [];
            $assignedOnlyUserId = $this->getAssignedOnlyUserId($user);
            if (!count($allowedMailboxIds)) {
                return response()->json(['items' => []]);
            }

            $mailboxId = (int)$request->get('mailbox_id', 0);
            if ($mailboxId && !in_array($mailboxId, array_map('intval', $allowedMailboxIds), true)) {
                $mailboxId = 0;
            }

            $status = (int)$request->get('status', -1);
            $statusOptions = $this->statusOptions();
            if ($status !== -1 && !array_key_exists($status, $statusOptions)) {
                $status = -1;
            }

            $folderId = (int)$request->get('folder_id', 0);
            if (!$this->foldersOk()) {
                $folderId = 0;
            }

            $fieldId = (int)$request->get('field_id', 0);
            if ($fieldId && !$this->customFieldsOk()) {
                $fieldId = 0;
            }

            $selectedField = null;
            if ($fieldId) {
                $selectedField = $this->loadFieldById($allowedMailboxIds, $fieldId);
            }

            if (!$this->schemaOk()) {
                return response()->json(['items' => []]);
            }

            // Keep it intentionally small and fast.
            $perPage = 8;
            // Always suggest newest first (deterministic UX).
            [$results, $total] = $this->search(
                $q,
                $allowedMailboxIds,
                $mailboxId,
                $fieldId,
                $status,
                $folderId,
                0,
                $assignedOnlyUserId,
                1,
                $perPage,
                'updated_desc'
            );

            // Optional: hydrate selected custom field value for suggestions.
            if ($fieldId && $this->customFieldsOk() && count($results)) {
                $results = $this->appendCustomFieldValues($results, $fieldId, $selectedField);
            }

            $items = $this->buildConversationMetaItems($results);

            return response()->json([
                'items' => $items,
                'total' => (int)$total,
            ]);
        } catch (\Throwable $e) {
            // Never break the navbar.
            return response()->json(['items' => []]);
        }
    }


    /**
     * Refresh conversation metadata used by recent-search shortcuts in the navbar.
     * This keeps status badges and timestamps from drifting when a conversation
     * changes after it was first stored in localStorage.
     */
    public function recentMeta(Request $request)
    {
        try {
            $rawIds = (string)$request->get('ids', '');
            if ($rawIds === '') {
                return response()->json(['items' => []]);
            }

            $parts = preg_split('/[^0-9]+/', $rawIds);
            $ids = [];
            foreach ((array)$parts as $part) {
                $id = (int)$part;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
            $ids = array_values($ids);
            if (!count($ids)) {
                return response()->json(['items' => []]);
            }
            if (count($ids) > 10) {
                $ids = array_slice($ids, 0, 10);
            }

            $user = Auth::user();
            $allowedMailboxIds = $user ? $user->mailboxesIdsCanView() : [];
            if (!count($allowedMailboxIds) || !$this->schemaOk()) {
                return response()->json(['items' => []]);
            }

            $rows = Conversation::whereIn('id', $ids)
                ->whereIn('mailbox_id', $allowedMailboxIds)
                ->get(['id', 'subject', 'mailbox_id', 'status', 'state', 'updated_at']);

            if (!count($rows)) {
                return response()->json(['items' => []]);
            }

            $results = [];
            foreach ($rows as $conversation) {
                if (!$this->canViewConversation($conversation, $user)) {
                    continue;
                }

                $results[] = [
                    'id' => (int)$conversation->id,
                    'subject' => (string)$conversation->subject,
                    'mailbox_id' => (int)$conversation->mailbox_id,
                    'status' => (int)$conversation->status,
                    'state' => isset($conversation->state) ? (int)$conversation->state : 0,
                    'updated_at' => (string)$conversation->updated_at,
                ];
            }

            return response()->json([
                'items' => $this->buildConversationMetaItems($results),
            ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
              ->header('Pragma', 'no-cache')
              ->header('Expires', '0');
        } catch (\Throwable $e) {
            return response()->json(['items' => []]);
        }
    }

    protected function buildConversationMetaItems(array $results)
    {
        if (!count($results)) {
            return [];
        }

        $mailboxNames = [];
        try {
            $mbIds = [];
            foreach ($results as $r) {
                $mid = (int)($r['mailbox_id'] ?? 0);
                if ($mid) {
                    $mbIds[$mid] = true;
                }
            }
            $mbIds = array_keys($mbIds);
            if (count($mbIds)) {
                $in = implode(',', array_fill(0, count($mbIds), '?'));
                $rows = DB::select("SELECT id, name FROM mailboxes WHERE id IN ($in)", $mbIds);
                foreach ($rows as $mb) {
                    $mailboxNames[(int)$mb->id] = (string)$mb->name;
                }
            }
        } catch (\Throwable $e) {
            $mailboxNames = [];
        }

        $items = [];
        foreach ($results as $r) {
            $id = (int)($r['id'] ?? 0);
            if (!$id) {
                continue;
            }

            $status = (int)($r['status'] ?? 0);
            $state = (int)($r['state'] ?? 0);
            $mailboxId = (int)($r['mailbox_id'] ?? 0);
            $updatedAt = (string)($r['updated_at'] ?? '');

            $updatedHuman = '';
            try {
                if ($updatedAt) {
                    $updatedHuman = Carbon::parse($updatedAt)->diffForHumans();
                }
            } catch (\Throwable $e) {
                $updatedHuman = '';
            }

            $badge = $this->getConversationBadgeMeta($status, $state);
            $statusName = (string)($badge['name'] ?? '');
            $statusClass = (string)($badge['class'] ?? 'default');

            $items[] = [
                'id' => $id,
                'subject' => (string)($r['subject'] ?? ''),
                'url' => route('conversations.view', ['id' => $id]),
                'mailbox_id' => $mailboxId,
                'mailbox_name' => (string)($mailboxNames[$mailboxId] ?? ''),
                'status' => $status,
                'state' => $state,
                'status_name' => $statusName,
                'status_class' => $statusClass,
                'updated_at' => $updatedAt,
                'updated_human' => $updatedHuman,
                'field_value' => (string)($r['field_value'] ?? ''),
            ];
        }

        return $items;
    }



    /**
     * Return available custom fields for a selected mailbox.
     * Used by the Smart Search form before submitting the search.
     */
    public function fields(Request $request)
    {
        try {
            $user = Auth::user();
            $allowedMailboxIds = $user ? $user->mailboxesIdsCanView() : [];
            if (!count($allowedMailboxIds)) {
                return response()->json(['status' => 'success', 'fields' => []]);
            }

            $mailboxId = (int)$request->get('mailbox_id', 0);
            if ($mailboxId && !in_array($mailboxId, array_map('intval', $allowedMailboxIds), true)) {
                $mailboxId = 0;
            }

            if (!$this->customFieldDefinitionsOk()) {
                return response()->json(['status' => 'success', 'fields' => []]);
            }

            $fields = $this->loadFields($allowedMailboxIds, $mailboxId);

            return response()->json([
                'status' => 'success',
                'fields' => $fields,
            ]);
        } catch (\Throwable $e) {
            try {
                \Helper::logException($e);
            } catch (\Throwable $ignored) {}

            return response()->json([
                'status' => 'error',
                'fields' => [],
            ]);
        }
    }


    public function bulk(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return redirect()->route('adamsmartsearchui.search');
            }

            $data = $request->only([
                'bulk_action',
                'bulk_assignee_id',
                'bulk_status',
                'bulk_note',
                'conversation_ids',
            ]);

            $ids = $this->normalizeBulkConversationIds($data['conversation_ids'] ?? []);
            if (!count($ids)) {
                \Session::flash('flash_error_floating', __('adamsmartsearchui::messages.bulk_select_required'));
                return redirect()->back();
            }

            $action = trim((string)($data['bulk_action'] ?? ''));
            if (!in_array($action, ['assign', 'status', 'note'], true)) {
                \Session::flash('flash_error_floating', __('adamsmartsearchui::messages.bulk_action_required'));
                return redirect()->back();
            }

            $allowedMailboxIds = $user->mailboxesIdsCanView();
            if (!count($allowedMailboxIds)) {
                \Session::flash('flash_error_floating', __('Not enough permissions'));
                return redirect()->back();
            }

            if (!$this->validateBulkActionPayload($action, $data, $allowedMailboxIds)) {
                return redirect()->back();
            }

            $conversations = Conversation::with('mailbox')
                ->whereIn('id', $ids)
                ->whereIn('mailbox_id', $allowedMailboxIds)
                ->get();

            $updated = 0;
            $skipped = max(0, count($ids) - count($conversations));
            $mailboxIdsToRefresh = [];

            foreach ($conversations as $conversation) {
                try {
                    if (!$this->canBulkTouchConversation($conversation, $user)) {
                        ++$skipped;
                        continue;
                    }

                    if ($this->isDeletedConversationState((int)($conversation->state ?? 0))) {
                        ++$skipped;
                        continue;
                    }

                    $changed = false;
                    if ($action === 'assign') {
                        $changed = $this->applyBulkAssignment($conversation, $user, (int)($data['bulk_assignee_id'] ?? 0));
                    } elseif ($action === 'status') {
                        $changed = $this->applyBulkStatus($conversation, $user, (int)($data['bulk_status'] ?? 0));
                    } elseif ($action === 'note') {
                        $changed = $this->applyBulkNote($conversation, $user, (string)($data['bulk_note'] ?? ''), $request);
                    }

                    if ($changed) {
                        ++$updated;
                        $mailboxIdsToRefresh[(int)$conversation->mailbox_id] = (int)$conversation->mailbox_id;
                    } else {
                        ++$skipped;
                    }
                } catch (\Throwable $e) {
                    ++$skipped;
                    try {
                        \Helper::logException($e);
                    } catch (\Throwable $ignored) {}
                }
            }

            $this->refreshMailboxCounters(array_values($mailboxIdsToRefresh));

            if ($updated > 0) {
                \Session::flash('flash_success_floating', __('adamsmartsearchui::messages.bulk_success', [
                    'updated' => $updated,
                    'skipped' => $skipped,
                ]));
            } else {
                \Session::flash('flash_error_floating', __('adamsmartsearchui::messages.bulk_no_updates'));
            }
        } catch (\Throwable $e) {
            try {
                \Helper::logException($e);
            } catch (\Throwable $ignored) {}
            \Session::flash('flash_error_floating', __('adamsmartsearchui::messages.bulk_failed'));
        }

        return redirect()->back();
    }

    protected function normalizeBulkConversationIds($raw)
    {
        $ids = [];
        if (!is_array($raw)) {
            $raw = preg_split('/[^0-9]+/', (string)$raw);
        }

        foreach ((array)$raw as $value) {
            $id = (int)$value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        $ids = array_values($ids);
        $limit = (int)config('adamsmartsearchui.bulk_max_selected', 200);
        $limit = max(1, min(500, $limit));
        if (count($ids) > $limit) {
            $ids = array_slice($ids, 0, $limit);
        }

        return $ids;
    }

    protected function validateBulkActionPayload(string $action, array $data, array $allowedMailboxIds)
    {
        if ($action === 'assign') {
            $assigneeId = (int)($data['bulk_assignee_id'] ?? 0);
            if ($assigneeId === 0) {
                \Session::flash('flash_error_floating', __('adamsmartsearchui::messages.bulk_assignee_required'));
                return false;
            }

            $assignableIds = [-1 => -1];
            foreach ($this->loadAssignableUsers($allowedMailboxIds, 0) as $row) {
                $assignableIds[(int)$row['id']] = (int)$row['id'];
            }

            if (!isset($assignableIds[$assigneeId])) {
                \Session::flash('flash_error_floating', __('adamsmartsearchui::messages.bulk_assignee_required'));
                return false;
            }
        } elseif ($action === 'status') {
            $status = (int)($data['bulk_status'] ?? 0);
            if (!array_key_exists($status, $this->writableStatusOptions())) {
                \Session::flash('flash_error_floating', __('adamsmartsearchui::messages.bulk_status_required'));
                return false;
            }
        } elseif ($action === 'note') {
            $noteText = trim((string)($data['bulk_note'] ?? ''));
            if ($noteText === '' || trim(strip_tags($noteText)) === '') {
                \Session::flash('flash_error_floating', __('adamsmartsearchui::messages.bulk_note_required'));
                return false;
            }

            $maxLength = (int)config('adamsmartsearchui.bulk_note_max_length', 50000);
            $maxLength = max(100, min(100000, $maxLength));
            if (mb_strlen($noteText) > $maxLength) {
                \Session::flash('flash_error_floating', __('adamsmartsearchui::messages.bulk_note_too_long', [
                    'max' => $maxLength,
                ]));
                return false;
            }
        }

        return true;
    }

    protected function canBulkTouchConversation($conversation, $user)
    {
        if (!$conversation || !$user) {
            return false;
        }

        try {
            if (!$user->can('update', $conversation)) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    protected function applyBulkAssignment($conversation, $user, int $newUserId)
    {
        if ($newUserId === 0) {
            return false;
        }

        if ($newUserId !== -1 && !$this->isUserAssignableToMailbox($conversation->mailbox, $newUserId)) {
            return false;
        }

        $currentUserId = (int)($conversation->user_id ?? 0);
        if ($newUserId === -1) {
            if ($currentUserId <= 0) {
                return false;
            }
        } elseif ($currentUserId === $newUserId) {
            return false;
        }

        $conversation->changeUser($newUserId, $user);
        return true;
    }

    protected function isUserAssignableToMailbox($mailbox, int $userId)
    {
        if (!$mailbox || $userId <= 0) {
            return false;
        }

        try {
            // Prefer the same extension surface used by FreeScout's assignee UI.
            // Teams and other assignment modules can extend this collection.
            if (method_exists($mailbox, 'usersAssignable')) {
                foreach ($mailbox->usersAssignable(true) as $assignableUser) {
                    if ((int)($assignableUser->id ?? 0) === $userId) {
                        return true;
                    }
                }
                return false;
            }

            if (method_exists($mailbox, 'userHasAccess')) {
                return (bool)$mailbox->userHasAccess($userId);
            }

            if (method_exists($mailbox, 'userIdsHavingAccess')) {
                $ids = array_map('intval', (array)$mailbox->userIdsHavingAccess());
                return in_array($userId, $ids, true);
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    protected function applyBulkStatus($conversation, $user, int $newStatus)
    {
        if (!array_key_exists($newStatus, $this->writableStatusOptions())) {
            return false;
        }

        if ((int)($conversation->status ?? 0) === $newStatus) {
            return false;
        }

        $conversation->changeStatus($newStatus, $user);
        return true;
    }

    protected function applyBulkNote($conversation, $user, string $noteText, Request $request)
    {
        $noteText = trim($noteText);
        if ($noteText === '' || trim(strip_tags($noteText)) === '') {
            return false;
        }

        $body = e(str_replace(["\r\n", "\r"], "\n", $noteText));
        $body = nl2br($body);

        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->user_id = $user->id;
        $thread->type = Thread::TYPE_NOTE;
        $thread->status = $conversation->status;
        $thread->state = Thread::STATE_PUBLISHED;
        $thread->source_via = Thread::PERSON_USER;
        $thread->source_type = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id = $conversation->customer_id;
        $thread->created_by_user_id = $user->id;
        $thread->body = $body;

        $hookRequest = clone $request;
        $hookRequest->merge([
            'body' => $body,
            'is_note' => 1,
            'conversation_id' => (int)$conversation->id,
        ]);

        \Eventy::action('thread.before_save_from_request', $thread, $hookRequest);
        $thread->save();

        if (class_exists('App\\Events\\UserAddedNote')) {
            event(new \App\Events\UserAddedNote($conversation, $thread));
        }
        \Eventy::action('conversation.note_added', $conversation, $thread);

        return true;
    }

    protected function refreshMailboxCounters(array $mailboxIds)
    {
        $mailboxIds = array_values(array_unique(array_filter(array_map('intval', $mailboxIds))));
        if (!count($mailboxIds)) {
            return;
        }

        try {
            $mailboxes = Mailbox::whereIn('id', $mailboxIds)->get();
            foreach ($mailboxes as $mailbox) {
                try {
                    if (method_exists($mailbox, 'updateFoldersCounters')) {
                        $mailbox->updateFoldersCounters();
                    }
                } catch (\Throwable $e) {
                    try {
                        \Helper::logException($e);
                    } catch (\Throwable $ignored) {}
                }
            }
        } catch (\Throwable $e) {
            try {
                \Helper::logException($e);
            } catch (\Throwable $ignored) {}
        }
    }

    protected function sanitizeSearchString($value)
    {
        $value = (string)$value;

        try {
            if (class_exists('Helper') && method_exists('Helper', 'sqlSanitizeString')) {
                $value = \Helper::sqlSanitizeString($value);
            } else {
                $value = str_replace("\0", '', $value);
            }
        } catch (\Throwable $e) {
            $value = str_replace("\0", '', $value);
        }

        return trim($value);
    }

    protected function rememberSchemaCheck($key, $callback)
    {
        $cacheMinutes = (int)config('adamsmartsearchui.schema_cache_minutes', 10);
        $cacheMinutes = max(1, min(1440, $cacheMinutes));

        return (bool)Cache::remember(
            'adam_smart_search_ui.'.$key,
            Carbon::now()->addMinutes($cacheMinutes),
            $callback
        );
    }

    protected function threadsTableOk()
    {
        return $this->rememberSchemaCheck('threads_table_ok', function () {
            try {
                return Schema::hasTable('threads')
                    && Schema::hasColumn('threads', 'id')
                    && Schema::hasColumn('threads', 'conversation_id');
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    protected function threadsSearchOk()
    {
        return $this->rememberSchemaCheck('threads_search_ok', function () {
            try {
                return $this->threadsTableOk()
                    && Schema::hasColumn('threads', 'type')
                    && Schema::hasColumn('threads', 'state')
                    && Schema::hasColumn('threads', 'body');
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    protected function conversationCreatorColumnOk()
    {
        return $this->rememberSchemaCheck('conversation_creator_column_ok', function () {
            try {
                return Schema::hasTable('conversations')
                    && Schema::hasColumn('conversations', 'created_by_user_id');
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    protected function customFieldDefinitionsOk()
    {
        return $this->rememberSchemaCheck('custom_field_defs_ok', function () {
            try {
                return Schema::hasTable('custom_fields')
                    && Schema::hasColumn('custom_fields', 'id')
                    && Schema::hasColumn('custom_fields', 'name')
                    && Schema::hasColumn('custom_fields', 'mailbox_id')
                    && Schema::hasColumn('custom_fields', 'type')
                    && Schema::hasColumn('custom_fields', 'options');
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    protected function customFieldsOk()
    {
        return $this->rememberSchemaCheck('custom_fields_ok', function () {
            try {
                return $this->customFieldDefinitionsOk()
                    && Schema::hasTable('conversation_custom_field')
                    && Schema::hasColumn('conversation_custom_field', 'conversation_id')
                    && Schema::hasColumn('conversation_custom_field', 'custom_field_id')
                    && Schema::hasColumn('conversation_custom_field', 'value');
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    protected function schemaOk()
    {
        return $this->rememberSchemaCheck('schema_ok', function () {
            try {
                $requiredColumns = [
                    'id',
                    'subject',
                    'status',
                    'state',
                    'updated_at',
                    'mailbox_id',
                    'user_id',
                    'customer_id',
                    'preview',
                    'customer_email',
                ];

                if (!Schema::hasTable('conversations')) {
                    return false;
                }

                foreach ($requiredColumns as $column) {
                    if (!Schema::hasColumn('conversations', $column)) {
                        return false;
                    }
                }

                return Schema::hasTable('customers')
                    && Schema::hasColumn('customers', 'id')
                    && Schema::hasColumn('customers', 'first_name')
                    && Schema::hasColumn('customers', 'last_name')
                    && Schema::hasColumn('customers', 'phones');
            } catch (\Throwable $e) {
                return false;
            }
        });
    }
    protected function foldersOk()
    {
        return $this->rememberSchemaCheck('folders_ok', function () {
            try {
                return Schema::hasTable('folders')
                    && Schema::hasColumn('folders', 'id')
                    && Schema::hasColumn('folders', 'mailbox_id')
                    && Schema::hasColumn('folders', 'type')
                    && Schema::hasColumn('folders', 'user_id')
                    && Schema::hasColumn('conversations', 'folder_id');
            } catch (\Throwable $e) {
                return false;
            }
        });
    }
    protected function loadFolders(array $allowedMailboxIds, int $mailboxId = 0)
    {
        try {
            if (!count($allowedMailboxIds)) {
                return [];
            }

            $ids = $mailboxId ? [$mailboxId] : $allowedMailboxIds;
            $ids = array_values(array_unique(array_map('intval', $ids)));
            if (!count($ids)) {
                return [];
            }

            $query = Folder::whereIn('mailbox_id', $ids)
                ->select(['id', 'mailbox_id', 'type', 'user_id']);

            // Personal folders are not represented by conversations.folder_id consistently.
            if (property_exists(Folder::class, 'personal_types') && is_array(Folder::$personal_types)) {
                $query->whereNotIn('type', Folder::$personal_types);
            }

            $folders = $query->get();
            $mailboxNames = [];
            if (!$mailboxId && count($ids) > 1) {
                foreach (Mailbox::whereIn('id', $ids)->get(['id', 'name']) as $mailbox) {
                    $mailboxNames[(int)$mailbox->id] = (string)$mailbox->name;
                }
            }

            $out = [];
            foreach ($folders as $folder) {
                try {
                    $name = method_exists($folder, 'getTypeName')
                        ? (string)$folder->getTypeName()
                        : (string)(Folder::$types[(int)$folder->type] ?? '');
                } catch (\Throwable $e) {
                    $name = (string)(Folder::$types[(int)$folder->type] ?? '');
                }
                if ($name === '') {
                    continue;
                }

                $folderMailboxId = (int)$folder->mailbox_id;
                $label = isset($mailboxNames[$folderMailboxId])
                    ? $mailboxNames[$folderMailboxId].' — '.$name
                    : $name;

                $out[] = [
                    'id' => (int)$folder->id,
                    'name' => $label,
                    'mailbox_id' => $folderMailboxId,
                ];
            }

            usort($out, function ($a, $b) {
                return strcasecmp((string)$a['name'], (string)$b['name']);
            });

            return $out;
        } catch (\Throwable $e) {
            try {
                \Helper::logException($e);
            } catch (\Throwable $ignored) {
                // no-op
            }
            return [];
        }
    }
    protected function statusOptions()
    {
        $out = [];
        try {
            if (property_exists(Conversation::class, 'statuses') && is_array(Conversation::$statuses)) {
                foreach (Conversation::$statuses as $code => $name) {
                    $code = (int)$code;
                    if ($code <= 0) {
                        continue;
                    }
                    $label = '';
                    try {
                        $label = (string)Conversation::statusCodeToName($code);
                    } catch (\Throwable $e) {
                        $label = (string)$name;
                    }
                    if ($label === '') {
                        $label = (string)$name;
                    }
                    $out[$code] = $label;
                }
            }
        } catch (\Throwable $e) {
            $out = [];
        }

        // Keep stable order in UI, then append Deleted because it is a conversation state, not a status.
        ksort($out);
        $out[$this->deletedStatusFilterValue()] = (string)__('adamsmartsearchui::messages.deleted');

        return $out;
    }


    protected function writableStatusOptions()
    {
        $out = [];
        try {
            if (property_exists(Conversation::class, 'statuses') && is_array(Conversation::$statuses)) {
                foreach (Conversation::$statuses as $code => $name) {
                    $code = (int)$code;
                    if ($code <= 0) {
                        continue;
                    }
                    try {
                        $label = (string)Conversation::statusCodeToName($code);
                    } catch (\Throwable $e) {
                        $label = (string)$name;
                    }
                    $out[$code] = $label !== '' ? $label : (string)$name;
                }
            }
        } catch (\Throwable $e) {
            $out = [];
        }

        ksort($out);
        return $out;
    }

    protected function loadAssignableUsers(array $allowedMailboxIds, int $mailboxId = 0)
    {
        try {
            $ids = $allowedMailboxIds;
            if ($mailboxId) {
                $ids = [$mailboxId];
            }
            $ids = array_values(array_unique(array_map('intval', $ids)));
            if (!count($ids)) {
                return [];
            }

            $users = [];
            $mailboxes = Mailbox::whereIn('id', $ids)->get();
            foreach ($mailboxes as $mailbox) {
                try {
                    if (method_exists($mailbox, 'usersAssignable')) {
                        foreach ($mailbox->usersAssignable(true) as $mailboxUser) {
                            $uid = (int)($mailboxUser->id ?? 0);
                            if ($uid > 0) {
                                $users[$uid] = $mailboxUser;
                            }
                        }
                        continue;
                    }

                    if (method_exists($mailbox, 'usersHavingAccess')) {
                        foreach ($mailbox->usersHavingAccess() as $mailboxUser) {
                            $uid = (int)($mailboxUser->id ?? 0);
                            if ($uid > 0) {
                                $users[$uid] = $mailboxUser;
                            }
                        }
                        continue;
                    }

                    if (method_exists($mailbox, 'userIdsHavingAccess')) {
                        $userIds = $mailbox->userIdsHavingAccess();
                        if (is_array($userIds) && count($userIds)) {
                            $rows = DB::table('users')
                                ->select('id', 'first_name', 'last_name', 'email')
                                ->whereIn('id', $userIds)
                                ->get();
                            foreach ($rows as $row) {
                                $uid = (int)($row->id ?? 0);
                                if ($uid > 0) {
                                    $users[$uid] = $row;
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // Fall back to already assigned users below if needed.
                }
            }

            if (!count($users)) {
                foreach ($this->loadAssignees($allowedMailboxIds, $mailboxId, 0) as $assignee) {
                    $row = (object)[
                        'id' => (int)($assignee['id'] ?? 0),
                        'first_name' => (string)($assignee['name'] ?? ''),
                        'last_name' => '',
                        'email' => '',
                    ];
                    if ((int)$row->id > 0) {
                        $users[(int)$row->id] = $row;
                    }
                }
            }

            $out = [];
            foreach ($users as $row) {
                $out[] = [
                    'id' => (int)$row->id,
                    'name' => $this->formatAssigneeLabel($row),
                ];
            }

            usort($out, function ($a, $b) {
                return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
            });

            return $out;
        } catch (\Throwable $e) {
            return $this->loadAssignees($allowedMailboxIds, $mailboxId, 0);
        }
    }

    protected function loadFields(array $allowedMailboxIds, int $mailboxId = 0)
    {
        try {
            $ids = $allowedMailboxIds;
            if ($mailboxId) {
                $ids = [$mailboxId];
            }
            $ids = array_values(array_unique(array_map('intval', $ids)));
            if (!count($ids)) {
                return [];
            }

            // Prefer the official CustomFields module entity when available so we stay aligned
            // with the module's own mailbox-level field semantics.
            if (class_exists('Modules\CustomFields\Entities\CustomField')) {
                $out = [];
                foreach ($ids as $id) {
                    try {
                        $fields = \Modules\CustomFields\Entities\CustomField::getMailboxCustomFields($id);
                        foreach ($fields as $field) {
                            $options = [];
                            if (isset($field->options) && is_array($field->options)) {
                                $options = $field->options;
                            } elseif (isset($field->options) && $field->options !== null && $field->options !== '') {
                                $decoded = \Helper::jsonDecode((string)$field->options, true);
                                if (is_array($decoded)) {
                                    $options = $decoded;
                                }
                            }
                            $out[] = [
                                'id' => (int)$field->id,
                                'name' => (string)$field->name,
                                'mailbox_id' => (int)$field->mailbox_id,
                                'type' => (int)($field->type ?? 0),
                                'options' => $options,
                            ];
                        }
                    } catch (\Throwable $e) {
                        // ignore and fall back below if needed
                    }
                }
                if (count($out)) {
                    usort($out, function ($a, $b) {
                        return strcasecmp((string)$a['name'], (string)$b['name']);
                    });
                    return $out;
                }
            }

            $in = implode(',', array_fill(0, count($ids), '?'));
            $sql = "SELECT id, name, mailbox_id, type, options FROM custom_fields WHERE mailbox_id IN ($in) ORDER BY name";
            $rows = DB::select($sql, $ids);

            $out = [];
            foreach ($rows as $r) {
                $options = [];
                if (isset($r->options) && $r->options !== null && $r->options !== '') {
                    $decoded = \Helper::jsonDecode((string)$r->options, true);
                    if (is_array($decoded)) {
                        $options = $decoded;
                    }
                }
                $out[] = [
                    'id' => (int)$r->id,
                    'name' => (string)$r->name,
                    'mailbox_id' => (int)$r->mailbox_id,
                    'type' => (int)($r->type ?? 0),
                    'options' => $options,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Best-effort single-field resolver for cases where the dropdown list does not include
     * the selected field (e.g. mailbox filter/permissions changed).
     */
    protected function loadFieldById(array $allowedMailboxIds, int $fieldId)
    {
        try {
            if (!$fieldId || !count($allowedMailboxIds)) {
                return null;
            }

            if (class_exists('Modules\CustomFields\Entities\CustomField')) {
                try {
                    $field = \Modules\CustomFields\Entities\CustomField::where('id', $fieldId)
                        ->whereIn('mailbox_id', $allowedMailboxIds)
                        ->first();
                    if ($field) {
                        $options = [];
                        if (isset($field->options) && is_array($field->options)) {
                            $options = $field->options;
                        } elseif (isset($field->options) && $field->options !== null && $field->options !== '') {
                            $decoded = \Helper::jsonDecode((string)$field->options, true);
                            if (is_array($decoded)) {
                                $options = $decoded;
                            }
                        }
                        return [
                            'id' => (int)$field->id,
                            'name' => (string)$field->name,
                            'mailbox_id' => (int)$field->mailbox_id,
                            'type' => (int)($field->type ?? 0),
                            'options' => $options,
                        ];
                    }
                } catch (\Throwable $e) {
                    // ignore and fall back
                }
            }

            $in = implode(',', array_fill(0, count($allowedMailboxIds), '?'));
            $sql = "SELECT id, name, mailbox_id, type, options FROM custom_fields WHERE id = ? AND mailbox_id IN ($in) LIMIT 1";
            $row = DB::selectOne($sql, array_merge([$fieldId], $allowedMailboxIds));
            if (!$row) {
                return null;
            }

            $options = [];
            if (isset($row->options) && $row->options !== null && $row->options !== '') {
                // Stored as JSON in the CustomFields module.
                $decoded = \Helper::jsonDecode((string)$row->options, true);
                if (is_array($decoded)) {
                    $options = $decoded;
                }
            }

            return [
                'id' => (int)$row->id,
                'name' => (string)$row->name,
                'mailbox_id' => (int)$row->mailbox_id,
                'type' => (int)($row->type ?? 0),
                'options' => $options,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function getAssignedOnlyUserId($user = null)
    {
        try {
            if (!$user) {
                $user = Auth::user();
            }
            if (!$user) {
                return 0;
            }

            if (method_exists($user, 'isAdmin')) {
                if ($user->isAdmin()) {
                    return 0;
                }
            } else {
                $adminRole = defined(User::class.'::ROLE_ADMIN')
                    ? (int)constant(User::class.'::ROLE_ADMIN')
                    : 2;
                if ((int)($user->role ?? 0) === $adminRole) {
                    return 0;
                }
            }

            // FreeScout 1.8.225+ exposes this as a user permission.
            if (method_exists($user, 'canSeeOnlyAssignedConversations')) {
                return $user->canSeeOnlyAssignedConversations() ? (int)$user->id : 0;
            }

            if (defined(User::class.'::PERM_ONLY_ASSIGNED_TICKETS') && method_exists($user, 'hasPermission')) {
                $permission = (int)constant(User::class.'::PERM_ONLY_ASSIGNED_TICKETS');
                if ($user->hasPermission($permission)) {
                    return (int)$user->id;
                }
            }

            // Backward compatibility for installations using the legacy config list.
            $raw = config('app.show_only_assigned_conversations');
            $raw = is_string($raw) ? trim($raw) : '';
            if ($raw === '') {
                return 0;
            }

            $ids = preg_split('/[^0-9]+/', $raw);
            foreach ((array)$ids as $id) {
                if ((int)$id === (int)($user->id ?? 0) && (int)$id > 0) {
                    return (int)$id;
                }
            }
        } catch (\Throwable $e) {
            return 0;
        }

        return 0;
    }

    protected function buildAssignedVisibilitySql(int $assignedOnlyUserId, string $alias = 'c')
    {
        if ($assignedOnlyUserId <= 0) {
            return ['sql' => '', 'bindings' => []];
        }

        if (!preg_match('/^[a-z][a-z0-9_]*$/i', $alias)) {
            $alias = 'c';
        }

        $bindings = [$assignedOnlyUserId];
        $conditions = [$alias.'.user_id = ?'];
        if ($this->conversationCreatorColumnOk()) {
            $conditions[] = $alias.'.created_by_user_id = ?';
            $bindings[] = $assignedOnlyUserId;
        }

        $result = [
            'sql' => ' AND ('.implode(' OR ', $conditions).')',
            'bindings' => $bindings,
        ];

        // Assignment modules may extend this condition without a core edit.
        try {
            $filtered = \Eventy::filter(
                'adamsmartsearchui.assigned_visibility_sql',
                $result,
                Auth::user(),
                $alias
            );
            if (is_array($filtered)
                && isset($filtered['sql'], $filtered['bindings'])
                && is_string($filtered['sql'])
                && is_array($filtered['bindings'])
            ) {
                $result = $filtered;
            }
        } catch (\Throwable $e) {
            // Keep the safe core condition.
        }

        return $result;
    }

    protected function canViewConversation($conversation, $user = null)
    {
        if (!$conversation) {
            return false;
        }
        if (!$user) {
            $user = Auth::user();
        }
        if (!$user) {
            return false;
        }

        try {
            return (bool)$user->can('view', $conversation);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function canViewConversationId(int $conversationId, $user = null)
    {
        if ($conversationId <= 0) {
            return false;
        }

        try {
            return $this->canViewConversation(Conversation::find($conversationId), $user);
        } catch (\Throwable $e) {
            return false;
        }
    }
    protected function formatAssigneeLabel($row)
    {
        try {
            if (is_object($row) && method_exists($row, 'getFullName')) {
                $modelName = trim((string)$row->getFullName());
                if ($modelName !== '') {
                    return $modelName;
                }
            }
        } catch (\Throwable $e) {
            // Fall back to the selected attributes below.
        }

        $firstName = trim((string)($row->first_name ?? ''));
        $lastName = trim((string)($row->last_name ?? ''));
        $email = trim((string)($row->email ?? ''));

        $fullName = trim($firstName.' '.$lastName);
        if ($fullName !== '') {
            return $fullName;
        }

        if ($email !== '') {
            return $email;
        }

        return '#'.((int)($row->id ?? 0));
    }

    protected function isValidAssigneeFilter(int $assigneeId, array $assignees)
    {
        if ($assigneeId === 0 || $assigneeId === -1) {
            return true;
        }

        foreach ($assignees as $assignee) {
            if ((int)($assignee['id'] ?? 0) === $assigneeId) {
                return true;
            }
        }

        return false;
    }

    protected function loadAssignees(array $allowedMailboxIds, int $mailboxId = 0, int $assignedOnlyUserId = 0)
    {
        try {
            $ids = $allowedMailboxIds;
            if ($mailboxId) {
                $ids = [$mailboxId];
            }
            $ids = array_values(array_unique(array_map('intval', $ids)));
            if (!count($ids)) {
                return [];
            }

            $in = implode(',', array_fill(0, count($ids), '?'));
            $visibility = $this->buildAssignedVisibilitySql($assignedOnlyUserId, 'c');
            $bindings = array_merge($ids, (array)$visibility['bindings']);

            $rows = DB::select(
                "SELECT DISTINCT u.id, u.first_name, u.last_name, u.email\n".
                "FROM conversations c\n".
                "JOIN users u ON u.id = c.user_id\n".
                "WHERE c.mailbox_id IN ($in)\n".
                "  AND c.user_id IS NOT NULL\n".
                "  AND c.user_id > 0\n".
                (string)$visibility['sql']."\n".
                "ORDER BY u.first_name ASC, u.last_name ASC, u.email ASC",
                $bindings
            );

            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'id' => (int)$row->id,
                    'name' => $this->formatAssigneeLabel($row),
                ];
            }

            if ($assignedOnlyUserId > 0 && !count($out)) {
                $userRow = DB::selectOne(
                    "SELECT id, first_name, last_name, email FROM users WHERE id = ? LIMIT 1",
                    [$assignedOnlyUserId]
                );
                if ($userRow) {
                    $out[] = [
                        'id' => (int)$userRow->id,
                        'name' => $this->formatAssigneeLabel($userRow),
                    ];
                }
            }

            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Append selected custom field values to results (single query, no N+1).
     */
    protected function appendCustomFieldValues(array $results, int $fieldId, $fieldMeta = null)
    {
        try {
            if (!$fieldId || !count($results)) {
                return $results;
            }

            $convIds = [];
            foreach ($results as $r) {
                $id = (int)($r['id'] ?? 0);
                if ($id) {
                    $convIds[] = $id;
                }
            }
            $convIds = array_values(array_unique($convIds));
            if (!count($convIds)) {
                return $results;
            }

            $in = implode(',', array_fill(0, count($convIds), '?'));
            $sql = "SELECT conversation_id, value FROM conversation_custom_field WHERE custom_field_id = ? AND conversation_id IN ($in)";
            $rows = DB::select($sql, array_merge([$fieldId], $convIds));

            $map = [];
            foreach ($rows as $row) {
                $map[(int)$row->conversation_id] = (string)($row->value ?? '');
            }

            foreach ($results as &$r) {
                $id = (int)($r['id'] ?? 0);
                $raw = $id && array_key_exists($id, $map) ? $map[$id] : '';

                // Convert dropdown stored values (keys) into labels.
                // CustomFields module types:
                //  1 = Dropdown
                //  8 = Multiselect Dropdown
                if ($raw !== '' && $fieldMeta && !empty($fieldMeta['type']) && !empty($fieldMeta['options']) && is_array($fieldMeta['options'])) {
                    $type = (int)$fieldMeta['type'];
                    $options = $fieldMeta['options'];

                    if ($type === 1) {
                        $r['field_value'] = array_key_exists($raw, $options) ? (string)$options[$raw] : $raw;
                        continue;
                    }

                    if ($type === 8) {
                        $parts = array_filter(array_map('trim', explode(',', (string)$raw)), function ($v) {
                            return $v !== '';
                        });
                        $labels = [];
                        foreach ($parts as $p) {
                            $labels[] = array_key_exists($p, $options) ? (string)$options[$p] : $p;
                        }
                        $r['field_value'] = implode(', ', $labels);
                        continue;
                    }
                }

                $r['field_value'] = $raw;
            }
            unset($r);

            return $results;
        } catch (\Throwable $e) {
            // Never break the listing.
            return $results;
        }
    }

    /**
     * Whitelist + normalize sorting.
     *
     * Supported values:
     *  - updated_desc (default)
     *  - updated_asc
     *  - id_desc
     *  - id_asc
     */
    protected function normalizeSort(string $sort)
    {
        $sort = trim(strtolower($sort));
        $allowed = ['updated_desc', 'updated_asc', 'id_desc', 'id_asc'];
        return in_array($sort, $allowed, true) ? $sort : 'updated_desc';
    }

    /**
     * Convert sort key into a safe ORDER BY clause.
     */
    protected function sortToOrderBy(string $sort)
    {
        switch ($sort) {
            case 'updated_asc':
                return 'c.updated_at ASC, c.id ASC';
            case 'id_desc':
                return 'c.id DESC';
            case 'id_asc':
                return 'c.id ASC';
            case 'updated_desc':
            default:
                return 'c.updated_at DESC, c.id DESC';
        }
    }

    protected function search(string $q, array $allowedMailboxIds, int $mailboxId, int $fieldId, int $status, int $folderId, int $assigneeId, int $assignedOnlyUserId, int $page, int $perPage, string $sort = 'updated_desc')
    {
        $offset = ($page - 1) * $perPage;
        $like = '%'.$q.'%';

        // Determine LIKE operator by driver.
        $driver = null;
        try {
            $driver = DB::connection()->getDriverName();
        } catch (\Throwable $e) {
            $driver = null;
        }
        $op = ($driver === 'pgsql') ? 'ILIKE' : 'LIKE';
        $cfOk = $this->customFieldsOk();
        $cfDefsOk = $this->customFieldDefinitionsOk();
        $orderBy = $this->sortToOrderBy($this->normalizeSort($sort));

        $ids = $allowedMailboxIds;
        if ($mailboxId) {
            $ids = [$mailboxId];
        }
        if (!count($ids)) {
            return [[], 0];
        }

        $in = implode(',', array_fill(0, count($ids), '?'));

        // Strict numeric resolution.
        // If user typed a numeric query (#1234 or 1234), treat it as a conversation/thread ID and jump.
        $numericTicket = $this->normalizeTicketNumberExact($q);
        $isStrictNumeric = (!$fieldId && $numericTicket !== null && $this->isStrictNumericQuery($q));
        if ($isStrictNumeric) {
            $resolved = $this->resolveNumericConversation(
                $numericTicket,
                $ids,
                $in,
                $status,
                $folderId,
                $assigneeId,
                $assignedOnlyUserId,
                $this->isDisplayNumberQuery($q)
            );
            if ($resolved !== null) {
                // Deterministic hit: return a single conversation.
                return [[[
                    'id' => (int)$resolved['id'],
                    'subject' => (string)$resolved['subject'],
                    'mailbox_id' => (int)$resolved['mailbox_id'],
                    'status' => (int)$resolved['status'],
                    'state' => (int)($resolved['state'] ?? 0),
                    'updated_at' => (string)$resolved['updated_at'],
                ]], 1];
            }

            // Compatibility fallback:
            // if the numeric input is not an actual conversation/thread ID,
            // continue into the normal LIKE-based search so order numbers or
            // other numeric values present in the conversation subject/title,
            // preview, email, customer data, or custom fields still match.
        }

        $cfExistsSql = $cfOk ? "EXISTS (\n                    SELECT 1 FROM conversation_custom_field ccf\n                    WHERE ccf.conversation_id = c.id\n                      AND ccf.value $op ?\n                )" : "0=1";

        // Driver-specific concats.
        $fullNameExpr = in_array($driver, ['pgsql', 'sqlite'], true)
            ? "COALESCE(cu.first_name,'') || ' ' || COALESCE(cu.last_name,'')"
            : "CONCAT(COALESCE(cu.first_name,''), ' ', COALESCE(cu.last_name,''))";

        $bindings = [];
        $bindings = array_merge($bindings, $ids);

        // Optional filters (ANDed).
        $filterSql = '';
        $filterBindings = [];
        $statusFilter = $this->buildStatusFilterSql($status);
        if ($statusFilter['sql'] !== '') {
            $filterSql .= $statusFilter['sql'];
            $filterBindings = array_merge($filterBindings, $statusFilter['bindings']);
        }
        if ($folderId) {
            $filterSql .= " AND c.folder_id = ?";
            $filterBindings[] = $folderId;
        }
        if ($assigneeId === -1) {
            $filterSql .= " AND (c.user_id IS NULL OR c.user_id <= 0)";
        } elseif ($assigneeId > 0) {
            $filterSql .= " AND c.user_id = ?";
            $filterBindings[] = $assigneeId;
        }

        $visibility = $this->buildAssignedVisibilitySql($assignedOnlyUserId, 'c');
        $filterSql .= (string)$visibility['sql'];
        $filterBindings = array_merge($filterBindings, (array)$visibility['bindings']);

        // If a specific custom field is selected, restrict search to that field only.
        if ($fieldId) {
            $countSql = "SELECT COUNT(DISTINCT c.id) AS cnt
                FROM conversations c
                JOIN conversation_custom_field ccf ON ccf.conversation_id = c.id
                WHERE c.mailbox_id IN ($in)
                  $filterSql
                  AND ccf.custom_field_id = ?
                  AND ccf.value $op ?";

            $sql = "SELECT DISTINCT c.id, c.subject, c.mailbox_id, c.status, c.state, c.updated_at
                FROM conversations c
                JOIN conversation_custom_field ccf ON ccf.conversation_id = c.id
                WHERE c.mailbox_id IN ($in)
                  $filterSql
                  AND ccf.custom_field_id = ?
                  AND ccf.value $op ?
                ORDER BY $orderBy
                LIMIT $perPage OFFSET $offset";

            $b = array_merge($bindings, $filterBindings, [$fieldId, $like]);
            return $this->runSearch($countSql, $sql, $b);
        }

        // Unified search across: custom field values, subject, preview, customer email, customer name, and customer phones JSON.

        $whereParts = [];
        $searchBindings = [];

        if ($cfOk) {
            $whereParts[] = $cfExistsSql;
            $searchBindings[] = $like;
        }

        $whereParts[] = "COALESCE(c.subject,'') $op ?";
        $searchBindings[] = $like;
        $whereParts[] = "COALESCE(c.preview,'') $op ?";
        $searchBindings[] = $like;
        $whereParts[] = "COALESCE(c.customer_email,'') $op ?";
        $searchBindings[] = $like;
        $whereParts[] = "COALESCE(cu.first_name,'') $op ?";
        $searchBindings[] = $like;
        $whereParts[] = "COALESCE(cu.last_name,'') $op ?";
        $searchBindings[] = $like;
        $whereParts[] = "$fullNameExpr $op ?";
        $searchBindings[] = $like;
        $whereParts[] = "COALESCE(cu.phones,'') $op ?";
        $searchBindings[] = $like;

        // Search published content without joining threads into the result set.
        if ((bool)config('adamsmartsearchui.search_thread_body', true) && $this->threadsSearchOk()) {
            $publishedState = defined(Thread::class.'::STATE_PUBLISHED')
                ? (int)constant(Thread::class.'::STATE_PUBLISHED')
                : 2;
            $threadTypes = [];
            foreach (['TYPE_CUSTOMER', 'TYPE_MESSAGE', 'TYPE_NOTE'] as $constantName) {
                $constant = Thread::class.'::'.$constantName;
                if (defined($constant)) {
                    $threadTypes[] = (int)constant($constant);
                }
            }
            $threadTypes = array_values(array_unique(array_filter($threadTypes)));
            if (count($threadTypes)) {
                $threadTypePlaceholders = implode(',', array_fill(0, count($threadTypes), '?'));
                $whereParts[] = "EXISTS (\n                    SELECT 1 FROM threads st\n                    WHERE st.conversation_id = c.id\n                      AND st.state = ?\n                      AND st.type IN ($threadTypePlaceholders)\n                      AND COALESCE(st.body,'') $op ?\n                )";
                $searchBindings[] = $publishedState;
                $searchBindings = array_merge($searchBindings, $threadTypes);
                $searchBindings[] = $like;
            }
        }

        $whereSql = implode("\n                OR ", $whereParts);

        $countSql = "SELECT COUNT(DISTINCT c.id) AS cnt
            FROM conversations c
            LEFT JOIN customers cu ON cu.id = c.customer_id
            WHERE c.mailbox_id IN ($in)
              $filterSql
              AND (
                $whereSql
              )";

        $sql = "SELECT DISTINCT c.id, c.subject, c.mailbox_id, c.status, c.state, c.updated_at
            FROM conversations c
            LEFT JOIN customers cu ON cu.id = c.customer_id
            WHERE c.mailbox_id IN ($in)
              $filterSql
              AND (
                $whereSql
              )
            ORDER BY $orderBy
            LIMIT $perPage OFFSET $offset";

        $b = array_merge($bindings, $filterBindings, $searchBindings);
        return $this->runSearch($countSql, $sql, $b);
    }

    /**
     * Normalize an exact numeric query into an integer.
     *
     * Plain digits prefer the internal conversation ID. A # prefix prefers
     * FreeScout's display conversation number. Thread IDs remain a fallback.
     */
    protected function normalizeTicketNumberExact(string $q)
    {
        $q = trim($q);
        if ($q === '') {
            return null;
        }

        // Remove common wrappers/prefixes.
        $q2 = preg_replace('/^[\s\[#\(]+/', '', $q) ?? '';
        $q2 = preg_replace('/[\]\)\s]+$/', '', $q2) ?? '';

        if (!preg_match('/^\d{1,12}$/', $q2)) {
            return null;
        }
        $n = (int)$q2;
        return $n > 0 ? $n : null;
    }

    protected function isDisplayNumberQuery(string $q)
    {
        return (bool)preg_match('/^\s*[\[\(]?\s*#\s*\d{1,12}\s*[\]\)]?\s*$/', trim($q));
    }

    protected function conversationNumberFieldName()
    {
        $field = 'number';
        try {
            if (method_exists(Conversation::class, 'numberFieldName')) {
                $candidate = (string)Conversation::numberFieldName();
                if (preg_match('/^[a-z][a-z0-9_]*$/i', $candidate)
                    && Schema::hasColumn('conversations', $candidate)
                ) {
                    $field = $candidate;
                }
            }
        } catch (\Throwable $e) {
            $field = 'number';
        }

        if (!preg_match('/^[a-z][a-z0-9_]*$/i', $field)) {
            return null;
        }

        try {
            return Schema::hasColumn('conversations', $field) ? $field : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Strict numeric query heuristic.
     * Treat plain numeric input and common wrappers as an intentional "go to conversation" lookup.
     */
    protected function isStrictNumericQuery(string $q)
    {
        $q = trim($q);
        if ($q === '') {
            return false;
        }
        // Allow: 123, #123, [#123], (123)
        return (bool)preg_match('/^\s*[\[#\(]*\s*\d{1,12}\s*[\]\)]*\s*$/', $q);
    }

    /**
     * Deterministically resolve a numeric query to the correct conversation.
     * Plain digits prefer conversations.id; #digits prefer FreeScout's display number.
     * A matching thread ID is accepted as the final backward-compatible fallback.
     */
    protected function resolveNumericConversation(
        int $number,
        array $mailboxIds,
        string $inPlaceholders,
        int $status = -1,
        int $folderId = 0,
        int $assigneeId = 0,
        int $assignedOnlyUserId = 0,
        bool $preferDisplayNumber = false
    ) {
        try {
            $filter = $this->buildExactConversationFilterSql(
                $status,
                $folderId,
                $assigneeId,
                $assignedOnlyUserId
            );

            $lookups = $preferDisplayNumber ? ['number', 'id'] : ['id', 'number'];
            foreach ($lookups as $lookup) {
                if ($lookup === 'id') {
                    $row = $this->fetchExactConversationById(
                        $number,
                        $mailboxIds,
                        $inPlaceholders,
                        (string)$filter['sql'],
                        (array)$filter['bindings']
                    );
                } else {
                    $row = $this->fetchExactConversationByNumber(
                        $number,
                        $mailboxIds,
                        $inPlaceholders,
                        (string)$filter['sql'],
                        (array)$filter['bindings']
                    );
                }

                if ($row !== null && $this->canViewConversationId((int)$row['id'])) {
                    return $row;
                }
            }

            // A thread ID is also accepted for backward compatibility.
            if ($this->threadsTableOk()) {
                $thread = DB::selectOne(
                    'SELECT conversation_id FROM threads WHERE id = ? LIMIT 1',
                    [$number]
                );
                $conversationId = $thread ? (int)($thread->conversation_id ?? 0) : 0;
                if ($conversationId > 0) {
                    $row = $this->fetchExactConversationById(
                        $conversationId,
                        $mailboxIds,
                        $inPlaceholders,
                        (string)$filter['sql'],
                        (array)$filter['bindings']
                    );
                    if ($row !== null && $this->canViewConversationId((int)$row['id'])) {
                        return $row;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fall through to normal text search.
        }

        return null;
    }
    protected function buildExactConversationFilterSql(int $status, int $folderId, int $assigneeId, int $assignedOnlyUserId = 0)
    {
        $sql = '';
        $bindings = [];

        $statusFilter = $this->buildStatusFilterSql($status);
        if ($statusFilter['sql'] !== '') {
            $sql .= $statusFilter['sql'];
            $bindings = array_merge($bindings, $statusFilter['bindings']);
        }

        if ($folderId) {
            $sql .= ' AND c.folder_id = ?';
            $bindings[] = $folderId;
        }

        if ($assigneeId === -1) {
            $sql .= ' AND (c.user_id IS NULL OR c.user_id <= 0)';
        } elseif ($assigneeId > 0) {
            $sql .= ' AND c.user_id = ?';
            $bindings[] = $assigneeId;
        }

        $visibility = $this->buildAssignedVisibilitySql($assignedOnlyUserId, 'c');
        $sql .= (string)$visibility['sql'];
        $bindings = array_merge($bindings, (array)$visibility['bindings']);

        return [
            'sql' => $sql,
            'bindings' => $bindings,
        ];
    }

    protected function fetchExactConversationById(int $conversationId, array $mailboxIds, string $inPlaceholders, string $extraSql = '', array $extraBindings = [])
    {
        $row = DB::select(
            "SELECT c.id, c.subject, c.mailbox_id, c.status, c.state, c.updated_at\n".
            "FROM conversations c\n".
            "WHERE c.mailbox_id IN ($inPlaceholders) AND c.id = ?".$extraSql."\n".
            "LIMIT 1",
            array_merge($mailboxIds, [$conversationId], $extraBindings)
        );

        if (empty($row)) {
            return null;
        }

        $r = $row[0];
        return [
            'id' => (int)$r->id,
            'subject' => (string)$r->subject,
            'mailbox_id' => (int)$r->mailbox_id,
            'status' => (int)$r->status,
            'state' => isset($r->state) ? (int)$r->state : 0,
            'updated_at' => (string)$r->updated_at,
        ];
    }


    protected function fetchExactConversationByNumber(int $number, array $mailboxIds, string $inPlaceholders, string $extraSql = '', array $extraBindings = [])
    {
        $numberField = $this->conversationNumberFieldName();
        if (!$numberField) {
            return null;
        }

        $rows = DB::select(
            "SELECT c.id, c.subject, c.mailbox_id, c.status, c.state, c.updated_at\n".
            "FROM conversations c\n".
            "WHERE c.mailbox_id IN ($inPlaceholders) AND c.$numberField = ?".$extraSql."\n".
            "LIMIT 1",
            array_merge($mailboxIds, [$number], $extraBindings)
        );

        if (empty($rows)) {
            return null;
        }

        $row = $rows[0];
        return [
            'id' => (int)$row->id,
            'subject' => (string)$row->subject,
            'mailbox_id' => (int)$row->mailbox_id,
            'status' => (int)$row->status,
            'state' => isset($row->state) ? (int)$row->state : 0,
            'updated_at' => (string)$row->updated_at,
        ];
    }

    protected function appendStatusMeta(array $results)
    {
        foreach ($results as $key => $row) {
            $status = (int)($row['status'] ?? 0);
            $state = (int)($row['state'] ?? 0);
            $badge = $this->getConversationBadgeMeta($status, $state);

            $results[$key]['status_name'] = (string)($badge['name'] ?? '');
            $results[$key]['status_class'] = (string)($badge['class'] ?? 'default');
        }

        return $results;
    }

    protected function buildStatusFilterSql(int $status)
    {
        if ($status === -1) {
            return ['sql' => '', 'bindings' => []];
        }

        $deletedState = $this->deletedStateValue();
        if ($status === $this->deletedStatusFilterValue()) {
            return [
                'sql' => ' AND c.state = ?',
                'bindings' => [$deletedState],
            ];
        }

        return [
            'sql' => ' AND c.status = ? AND (c.state IS NULL OR c.state <> ?)',
            'bindings' => [$status, $deletedState],
        ];
    }

    protected function deletedStatusFilterValue()
    {
        return -10;
    }

    protected function deletedStateValue()
    {
        $deletedState = 3;
        try {
            if (defined(Conversation::class.'::STATE_DELETED')) {
                $deletedState = (int)constant(Conversation::class.'::STATE_DELETED');
            }
        } catch (\Throwable $e) {
            $deletedState = 3;
        }

        return $deletedState;
    }

    protected function getConversationBadgeMeta(int $status, int $state = 0)
    {
        if ($this->isDeletedConversationState($state)) {
            return [
                'name' => (string)__('adamsmartsearchui::messages.deleted'),
                'class' => 'danger',
            ];
        }

        $statusName = '';
        $statusClass = 'default';
        try {
            $statusName = (string)Conversation::statusCodeToName($status);
            if (isset(Conversation::$status_classes[$status])) {
                $statusClass = (string)Conversation::$status_classes[$status];
            }
        } catch (\Throwable $e) {
            $statusName = '';
            $statusClass = 'default';
        }

        return [
            'name' => $statusName,
            'class' => $statusClass,
        ];
    }

    protected function isDeletedConversationState(int $state)
    {
        return $state > 0 && $state === $this->deletedStateValue();
    }

    protected function runSearch(string $countSql, string $sql, array $bindings)
    {
        try {
            $cntRow = DB::select($countSql, $bindings);
            $total = (int)($cntRow[0]->cnt ?? 0);

            $rows = DB::select($sql, $bindings);
            $results = [];
            foreach ($rows as $r) {
                $results[] = [
                    'id' => (int)$r->id,
                    'subject' => (string)$r->subject,
                    'mailbox_id' => (int)$r->mailbox_id,
                    'status' => (int)$r->status,
                    'state' => isset($r->state) ? (int)$r->state : 0,
                    'updated_at' => (string)$r->updated_at,
                ];
            }

            return [$results, $total];
        } catch (\Throwable $e) {
            // Fail-safe: return no results, never crash UI.
            try {
                \Helper::logException($e);
            } catch (\Throwable $ignored) {
                // no-op
            }
            return [[], 0];
        }
    }
}
