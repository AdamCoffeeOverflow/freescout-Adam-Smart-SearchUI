<?php

namespace Modules\AdamSmartSearchUI\Http\Controllers;

use App\Conversation;
use App\Http\Controllers\Controller;
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
        $sort = $this->normalizeSort((string)$request->get('sort', 'updated_desc'));

        $user = Auth::user();
        $allowedMailboxIds = $user ? $user->mailboxesIdsCanView() : [];

        // If user selected a mailbox they can't access, ignore it.
        if ($mailboxId && !in_array($mailboxId, $allowedMailboxIds)) {
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

        $schemaOk = $this->schemaOk();
        $cfOk = $this->customFieldsOk();
        $cfDefsOk = $this->customFieldDefinitionsOk();
        $error = $schemaOk ? null : 'Conversations table not found. FreeScout database schema is missing.';

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
                    $resolved = $this->resolveNumericConversation($numericTicket, $ids, $in);
                    if ($resolved !== null) {
                        $path = rtrim((string)\Helper::getSubdirectory(), '/').'/conversation/'.((int)$resolved['id']);
                        return redirect($path);
                    }
                }
            }
        }

        if ($schemaOk && $q !== '' && mb_strlen($q) >= (int)config('adamsmartsearchui.min_query_len', 2)) {
            [$results, $total] = $this->search($q, $allowedMailboxIds, $mailboxId, $fieldId, $status, $folderId, $page, $perPage, $sort);
            $mode = 'search';
        } elseif ($schemaOk && $q === '') {
            // No query yet: show newest conversations as a useful default.
            [$results, $total] = $this->recent($allowedMailboxIds, $mailboxId, $status, $folderId, $page, $perPage, $sort);
            $mode = 'recent';
        }

        // If a specific custom field is selected, always hydrate its value column
        // for the current results page (including "recent" mode).
        if ($fieldId && $cfOk && count($results)) {
            $results = $this->appendCustomFieldValues($results, $fieldId, $selectedField);
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
            'sort' => $sort,
            'fields' => $fields,
            'selectedField' => $selectedField,
            'allowedMailboxIds' => $allowedMailboxIds,
            'error' => $error,
        ]);
    }

    /**
     * Default listing when query is empty: newest conversations the user can access.
     */
    protected function recent(array $allowedMailboxIds, int $mailboxId, int $status, int $folderId, int $page, int $perPage, string $sort = 'updated_desc')
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
        if ($status !== -1) {
            $where .= " AND c.status = ?";
            $bWhere[] = $status;
        }
        if ($folderId) {
            $where .= " AND c.folder_id = ?";
            $bWhere[] = $folderId;
        }

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
                "SELECT c.id, c.subject, c.status, c.updated_at, c.mailbox_id
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
            $minLen = (int)config('adamsmartsearchui.min_query_len', 2);
            if ($q === '' || mb_strlen($q) < $minLen) {
                return response()->json(['items' => []]);
            }

            $user = Auth::user();
            $allowedMailboxIds = $user ? $user->mailboxesIdsCanView() : [];
            if (!count($allowedMailboxIds)) {
                return response()->json(['items' => []]);
            }

            $mailboxId = (int)$request->get('mailbox_id', 0);
            if ($mailboxId && !in_array($mailboxId, $allowedMailboxIds)) {
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

            $selectedField = null;
            if ($fieldId && $this->customFieldsOk()) {
                $selectedField = $this->loadFieldById($allowedMailboxIds, $fieldId);
            }

            if (!$this->schemaOk()) {
                return response()->json(['items' => []]);
            }

            // Keep it intentionally small and fast.
            $perPage = 8;
            // Always suggest newest first (deterministic UX).
            [$results, $total] = $this->search($q, $allowedMailboxIds, $mailboxId, $fieldId, $status, $folderId, 1, $perPage, 'updated_desc');

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

            $inIds = implode(',', array_fill(0, count($ids), '?'));
            $inMailbox = implode(',', array_fill(0, count($allowedMailboxIds), '?'));

            $rows = DB::select(
                "SELECT c.id, c.subject, c.mailbox_id, c.status, c.updated_at
                 FROM conversations c
                 WHERE c.id IN ($inIds)
                   AND c.mailbox_id IN ($inMailbox)",
                array_merge($ids, $allowedMailboxIds)
            );

            if (!count($rows)) {
                return response()->json(['items' => []]);
            }

            $results = [];
            foreach ($rows as $r) {
                $results[] = [
                    'id' => (int)$r->id,
                    'subject' => (string)$r->subject,
                    'mailbox_id' => (int)$r->mailbox_id,
                    'status' => (int)$r->status,
                    'updated_at' => (string)$r->updated_at,
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

            $path = rtrim((string)\Helper::getSubdirectory(), '/').'/conversation/'.$id;
            $status = (int)($r['status'] ?? 0);
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

            $items[] = [
                'id' => $id,
                'subject' => (string)($r['subject'] ?? ''),
                'url' => url($path),
                'mailbox_id' => $mailboxId,
                'mailbox_name' => (string)($mailboxNames[$mailboxId] ?? ''),
                'status' => $status,
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
            if ($mailboxId && !in_array($mailboxId, $allowedMailboxIds)) {
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

    protected function customFieldDefinitionsOk()
    {
        $cacheMinutes = (int)config('adamsmartsearchui.schema_cache_minutes', 10);
        $key = 'adam_smart_search_ui.custom_field_defs_ok';

        return (bool)Cache::remember($key, now()->addMinutes($cacheMinutes), function () {
            try {
                return Schema::hasTable('custom_fields');
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    protected function customFieldsOk()
    {
        $cacheMinutes = (int)config('adamsmartsearchui.schema_cache_minutes', 10);
        $key = 'adam_smart_search_ui.custom_fields_ok';

        return (bool)Cache::remember($key, now()->addMinutes($cacheMinutes), function () {
            try {
                return Schema::hasTable('conversation_custom_field') && Schema::hasTable('custom_fields');
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    protected function schemaOk()
    {
        $cacheMinutes = (int)config('adamsmartsearchui.schema_cache_minutes', 10);
        $key = 'adam_smart_search_ui.schema_ok';

        return (bool)Cache::remember($key, now()->addMinutes($cacheMinutes), function () {
            try {
                return Schema::hasTable('conversations');
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    protected function foldersOk()
    {
        $cacheMinutes = (int)config('adamsmartsearchui.schema_cache_minutes', 10);
        $key = 'adam_smart_search_ui.folders_ok';

        return (bool)Cache::remember($key, now()->addMinutes($cacheMinutes), function () {
            try {
                if (!Schema::hasTable('folders')) {
                    return false;
                }
                // FreeScout stores folder on conversation in most versions.
                return Schema::hasColumn('conversations', 'folder_id');
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

            $ids = $allowedMailboxIds;
            if ($mailboxId) {
                $ids = [$mailboxId];
            }
            if (!count($ids)) {
                return [];
            }

            // Some FreeScout versions include folder mailbox_id; if not, we still load all.
            $hasMailbox = false;
            try {
                $hasMailbox = Schema::hasColumn('folders', 'mailbox_id');
            } catch (\Throwable $e) {
                $hasMailbox = false;
            }

            if ($hasMailbox) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $rows = DB::select("SELECT id, name, mailbox_id FROM folders WHERE mailbox_id IN ($in) ORDER BY name", $ids);
            } else {
                // Fallback (no mailbox column): load all folders.
                $rows = DB::select("SELECT id, name FROM folders ORDER BY name");
            }

            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'id' => (int)$r->id,
                    'name' => (string)$r->name,
                    'mailbox_id' => (int)($r->mailbox_id ?? 0),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
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

        // Keep stable order in UI.
        ksort($out);
        return $out;
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

    /**
     * Append selected custom field values to results (single query, no N+1).
     */
    protected function appendCustomFieldValues(array $results, int $fieldId, ?array $fieldMeta = null)
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
                return 'c.updated_at ASC';
            case 'id_desc':
                return 'c.id DESC';
            case 'id_asc':
                return 'c.id ASC';
            case 'updated_desc':
            default:
                return 'c.updated_at DESC';
        }
    }

    protected function search(string $q, array $allowedMailboxIds, int $mailboxId, int $fieldId, int $status, int $folderId, int $page, int $perPage, string $sort = 'updated_desc')
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
            $resolved = $this->resolveNumericConversation($numericTicket, $ids, $in);
            if ($resolved !== null) {
                // Deterministic hit: return a single conversation.
                return [[[
                    'id' => (int)$resolved['id'],
                    'subject' => (string)$resolved['subject'],
                    'mailbox_id' => (int)$resolved['mailbox_id'],
                    'status' => (int)$resolved['status'],
                    'updated_at' => (string)$resolved['updated_at'],
                ]], 1];
            }

            // Strict numeric query with no deterministic hit: do NOT fall back to LIKE-based search,
            // as it can return unrelated conversations containing the digits.
            return [[], 0];
        }

        $cfExistsSql = $cfOk ? "EXISTS (\n                    SELECT 1 FROM conversation_custom_field ccf\n                    WHERE ccf.conversation_id = c.id\n                      AND ccf.value $op ?\n                )" : "0=1";

        // Driver-specific concats.
        $fullNameExpr = ($driver === 'pgsql')
            ? "COALESCE(cu.first_name,'') || ' ' || COALESCE(cu.last_name,'')"
            : "CONCAT(COALESCE(cu.first_name,''), ' ', COALESCE(cu.last_name,''))";

        $bindings = [];
        $bindings = array_merge($bindings, $ids);

        // Optional filters (ANDed).
        $filterSql = '';
        $filterBindings = [];
        if ($status !== -1) {
            $filterSql .= " AND c.status = ?";
            $filterBindings[] = $status;
        }
        if ($folderId) {
            $filterSql .= " AND c.folder_id = ?";
            $filterBindings[] = $folderId;
        }

        // If a specific custom field is selected, restrict search to that field only.
        if ($fieldId) {
            $countSql = "SELECT COUNT(DISTINCT c.id) AS cnt
                FROM conversations c
                JOIN conversation_custom_field ccf ON ccf.conversation_id = c.id
                WHERE c.mailbox_id IN ($in)
                  $filterSql
                  AND ccf.custom_field_id = ?
                  AND ccf.value $op ?";

            $sql = "SELECT DISTINCT c.id, c.subject, c.mailbox_id, c.status, c.updated_at
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
            // $cfExistsSql contains one placeholder for ccf.value
            $whereParts[] = $cfExistsSql;
            $searchBindings[] = $like;
        }

        // No ticket-number searching: we only show and prioritize conversation IDs.
        $whereParts[] = "COALESCE(c.subject,'') $op ?";
        $whereParts[] = "COALESCE(c.preview,'') $op ?";
        $whereParts[] = "COALESCE(c.customer_email,'') $op ?";
        $whereParts[] = "COALESCE(cu.first_name,'') $op ?";
        $whereParts[] = "COALESCE(cu.last_name,'') $op ?";
        $whereParts[] = "$fullNameExpr $op ?";
        $whereParts[] = "COALESCE(cu.phones,'') $op ?";

        // Bindings for the common LIKE fields (one per where part appended above, excluding cf/num handled already)
        $searchBindings = array_merge($searchBindings, [$like, $like, $like, $like, $like, $like, $like]);

        $whereSql = implode("\n                OR ", $whereParts);

        $countSql = "SELECT COUNT(DISTINCT c.id) AS cnt
            FROM conversations c
            LEFT JOIN customers cu ON cu.id = c.customer_id
            WHERE c.mailbox_id IN ($in)
              $filterSql
              AND (
                $whereSql
              )";

        $sql = "SELECT DISTINCT c.id, c.subject, c.mailbox_id, c.status, c.updated_at
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

    // Ticket-number searching intentionally removed (we show conversation IDs only).

    /**
     * Normalize an *exact* numeric query into an int.
     *
     * We treat plain numeric queries as conversation/thread IDs.
     * Accept common wrappers like "#123", "[#123]", "(123)", etc.
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
     * Priority:
     *  1) conversations.id = N
     *  2) threads.id = N -> threads.conversation_id
     */
    protected function resolveNumericConversation(int $n, array $mailboxIds, string $inPlaceholders)
    {
        try {
            // 1) conversations.id
            $row = DB::select(
                "SELECT c.id, c.subject, c.mailbox_id, c.status, c.updated_at\n".
                "FROM conversations c\n".
                "WHERE c.mailbox_id IN ($inPlaceholders) AND c.id = ?\n".
                "LIMIT 1",
                array_merge($mailboxIds, [$n])
            );
            if (!empty($row)) {
                $r = $row[0];
                return [
                    'id' => (int)$r->id,
                    'subject' => (string)$r->subject,
                    'mailbox_id' => (int)$r->mailbox_id,
                    'status' => (int)$r->status,
                    'updated_at' => (string)$r->updated_at,
                ];
            }

            // 2) threads.id -> conversation_id
            $threadsOk = false;
            try {
                $threadsOk = Schema::hasTable('threads');
            } catch (\Throwable $e) {
                $threadsOk = false;
            }
            if ($threadsOk) {
                $t = DB::select("SELECT conversation_id FROM threads WHERE id = ? LIMIT 1", [$n]);
                $cid = !empty($t) ? (int)($t[0]->conversation_id ?? 0) : 0;
                if ($cid) {
                    $row = DB::select(
                        "SELECT c.id, c.subject, c.mailbox_id, c.status, c.updated_at\n".
                        "FROM conversations c\n".
                        "WHERE c.mailbox_id IN ($inPlaceholders) AND c.id = ?\n".
                        "LIMIT 1",
                        array_merge($mailboxIds, [$cid])
                    );
                    if (!empty($row)) {
                        $r = $row[0];
                        return [
                            'id' => (int)$r->id,
                            'subject' => (string)$r->subject,
                            'mailbox_id' => (int)$r->mailbox_id,
                            'status' => (int)$r->status,
                            'updated_at' => (string)$r->updated_at,
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return null;
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
                    'updated_at' => (string)$r->updated_at,
                ];
            }

            return [$results, $total];
        } catch (\Throwable $e) {
            // Fail-safe: return no results, never crash UI.
            try {
                \Log::error('[AdamSmartSearchUI] Search failed: '.$e->getMessage());
            } catch (\Throwable $e2) {}
            return [[], 0];
        }
    }
}
