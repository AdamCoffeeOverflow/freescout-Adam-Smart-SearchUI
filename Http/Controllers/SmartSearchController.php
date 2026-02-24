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
        $sort = $this->normalizeSort((string)$request->get('sort', 'updated_desc'));

        $user = Auth::user();
        $allowedMailboxIds = $user ? $user->mailboxesIdsCanView() : [];

        // If user selected a mailbox they can't access, ignore it.
        if ($mailboxId && !in_array($mailboxId, $allowedMailboxIds)) {
            $mailboxId = 0;
        }

        $schemaOk = $this->schemaOk();
        $cfOk = $this->customFieldsOk();
        $error = $schemaOk ? null : 'Conversations table not found. FreeScout database schema is missing.';

        $fields = [];
        if ($cfOk) {
            $fields = $this->loadFields($allowedMailboxIds, $mailboxId);
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
            [$results, $total] = $this->search($q, $allowedMailboxIds, $mailboxId, $fieldId, $page, $perPage, $sort);
            $mode = 'search';
        } elseif ($schemaOk && $q === '') {
            // No query yet: show newest conversations as a useful default.
            [$results, $total] = $this->recent($allowedMailboxIds, $mailboxId, $page, $perPage, $sort);
            $mode = 'recent';
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
            'sort' => $sort,
            'fields' => $fields,
            'allowedMailboxIds' => $allowedMailboxIds,
            'error' => $error,
        ]);
    }

    /**
     * Default listing when query is empty: newest conversations the user can access.
     */
    protected function recent(array $allowedMailboxIds, int $mailboxId, int $page, int $perPage, string $sort = 'updated_desc'): array
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

        $total = 0;
        try {
            $row = DB::selectOne("SELECT COUNT(*) as cnt FROM conversations c WHERE c.mailbox_id IN ($in)", $ids);
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
                 WHERE c.mailbox_id IN ($in)
                 ORDER BY $orderBy
                 LIMIT ? OFFSET ?",
                array_merge($ids, [$perPage, $offset])
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

            $fieldId = (int)$request->get('field_id', 0);

            if (!$this->schemaOk()) {
                return response()->json(['items' => []]);
            }

            // Keep it intentionally small and fast.
            $perPage = 8;
            // Always suggest newest first (deterministic UX).
            [$results, $total] = $this->search($q, $allowedMailboxIds, $mailboxId, $fieldId, 1, $perPage, 'updated_desc');

            // Resolve mailbox names in one query (avoid N+1).
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
                    $in2 = implode(',', array_fill(0, count($mbIds), '?'));
                    $rows = DB::select("SELECT id, name FROM mailboxes WHERE id IN ($in2)", $mbIds);
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
                $mailboxIdRow = (int)($r['mailbox_id'] ?? 0);
                $updatedAt = (string)($r['updated_at'] ?? '');

                // Human time (best-effort; never break navbar)
                $updatedHuman = '';
                try {
                    if ($updatedAt) {
                        $updatedHuman = Carbon::parse($updatedAt)->diffForHumans();
                    }
                } catch (\Throwable $e) {
                    $updatedHuman = '';
                }

                // Status label + class (match FreeScout core)
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
                    'mailbox_id' => $mailboxIdRow,
                    'mailbox_name' => (string)($mailboxNames[$mailboxIdRow] ?? ''),
                    'status' => $status,
                    'status_name' => $statusName,
                    'status_class' => $statusClass,
                    'updated_at' => $updatedAt,
                    'updated_human' => $updatedHuman,
                ];
            }

            return response()->json([
                'items' => $items,
                'total' => (int)$total,
            ]);
        } catch (\Throwable $e) {
            // Never break the navbar.
            return response()->json(['items' => []]);
        }
    }

    
    protected function customFieldsOk(): bool
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

    protected function schemaOk(): bool
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

    protected function loadFields(array $allowedMailboxIds, int $mailboxId = 0): array
    {
        try {
            $ids = $allowedMailboxIds;
            if ($mailboxId) {
                $ids = [$mailboxId];
            }
            if (!count($ids)) {
                return [];
            }

            $in = implode(',', array_fill(0, count($ids), '?'));
            $sql = "SELECT id, name, mailbox_id FROM custom_fields WHERE mailbox_id IN ($in) ORDER BY name";
            $rows = DB::select($sql, $ids);

            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'id' => (int)$r->id,
                    'name' => (string)$r->name,
                    'mailbox_id' => (int)$r->mailbox_id,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
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
    protected function normalizeSort(string $sort): string
    {
        $sort = trim(strtolower($sort));
        $allowed = ['updated_desc', 'updated_asc', 'id_desc', 'id_asc'];
        return in_array($sort, $allowed, true) ? $sort : 'updated_desc';
    }

    /**
     * Convert sort key into a safe ORDER BY clause.
     */
    protected function sortToOrderBy(string $sort): string
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

    protected function search(string $q, array $allowedMailboxIds, int $mailboxId, int $fieldId, int $page, int $perPage, string $sort = 'updated_desc'): array
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

        // If a specific custom field is selected, restrict search to that field only.
        if ($fieldId) {
            $countSql = "SELECT COUNT(DISTINCT c.id) AS cnt
                FROM conversations c
                JOIN conversation_custom_field ccf ON ccf.conversation_id = c.id
                WHERE c.mailbox_id IN ($in)
                  AND ccf.custom_field_id = ?
                  AND ccf.value $op ?";

            $sql = "SELECT DISTINCT c.id, c.subject, c.mailbox_id, c.status, c.updated_at
                FROM conversations c
                JOIN conversation_custom_field ccf ON ccf.conversation_id = c.id
                WHERE c.mailbox_id IN ($in)
                  AND ccf.custom_field_id = ?
                  AND ccf.value $op ?
                ORDER BY $orderBy
                LIMIT $perPage OFFSET $offset";

            $b = array_merge($bindings, [$fieldId, $like]);
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
              AND (
                $whereSql
              )";

        $sql = "SELECT DISTINCT c.id, c.subject, c.mailbox_id, c.status, c.updated_at
            FROM conversations c
            LEFT JOIN customers cu ON cu.id = c.customer_id
            WHERE c.mailbox_id IN ($in)
              AND (
                $whereSql
              )
            ORDER BY $orderBy
            LIMIT $perPage OFFSET $offset";

        $b = array_merge($bindings, $searchBindings);
        return $this->runSearch($countSql, $sql, $b);
    }

    // Ticket-number searching intentionally removed (we show conversation IDs only).

    /**
     * Normalize an *exact* numeric query into an int.
     *
     * We treat plain numeric queries as conversation/thread IDs.
     * Accept common wrappers like "#123", "[#123]", "(123)", etc.
     */
    protected function normalizeTicketNumberExact(string $q): ?int
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
    protected function isStrictNumericQuery(string $q): bool
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
    protected function resolveNumericConversation(int $n, array $mailboxIds, string $inPlaceholders): ?array
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

    protected function runSearch(string $countSql, string $sql, array $bindings): array
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
