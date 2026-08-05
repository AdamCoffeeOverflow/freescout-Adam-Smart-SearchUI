<?php

return [
    // Page
    'page_title' => 'Smart Search',
    'schema_missing' => 'The FreeScout conversations table is unavailable.',
    'heading' => 'Smart Search',

    // Form
    'query' => 'Query',
    'query_placeholder' => 'Search conversations, customers, custom fields…',
    'query_tip' => 'Tip: #1234 prefers the FreeScout conversation number; plain 1234 prefers the internal conversation ID. Fast search checks conversation fields first.',
    'search_message_content' => 'Search inside messages and internal notes',
    'content_search_help' => 'Deep content search runs separately and requires at least :min characters. It may be slower on very large installations.',
    'content_search_custom_field_disabled' => 'Deep content search is unavailable while a specific custom field is selected.',
    'content_search_unavailable' => 'Message-content search is unavailable on this installation.',

    'mailbox' => 'Mailbox',
    'all_accessible_mailboxes' => 'All accessible mailboxes',

    'field' => 'Field',
    'any_custom_field' => 'Any custom field',
    'loading_recent' => 'Loading…',
    'loading_fields' => 'Loading fields…',

    'sort' => 'Sort',
    'updated_newest' => 'Updated: newest',
    'updated_oldest' => 'Updated: oldest',
    'ticket_highest' => 'Ticket #: highest',
    'ticket_lowest' => 'Ticket #: lowest',

    'status' => 'Status',
    'deleted' => 'Deleted',
    'any_status' => 'Any status',

    'folder' => 'Folder',
    'any_folder' => 'Any folder',

    'assignee' => 'Assignee',
    'any_assignee' => 'Any assignee',
    'unassigned' => 'Unassigned',

    'search' => 'Search',

    // Meta / Results
    'query_too_short' => 'Query is too short.',
    'content_query_too_short' => 'Message-content searches require at least :min characters.',
    'total_count' => ':count total',
    'recent_conversations' => 'Recent conversations',
    'results_count_text' => '{1} result|[2,*] results',
    'content_results' => 'Message-content results',
    'content_total_not_counted' => '(exact total not calculated)',
    'content_fallback_used' => 'Deep fallback',

    'showing_on_page' => '(showing :count on this page)',
    'no_matches' => 'No matching conversations found.',

    // Table
    'conversation' => 'Conversation',
    'subject' => 'Subject',
    'no_subject' => '(no subject)',
    'updated' => 'Updated',

    // Pager
    'pagination' => 'Search result pages',
    'prev' => 'Prev',
    'next' => 'Next',

    // Inline topbar + suggest (JS)
    'inline_placeholder' => 'Search…',
    'focus_search' => 'Focus search',
    'open_smart_search' => 'Open Smart Search',
    'suggestions' => 'Suggestions',
    'search_smart_for' => 'Search Smart for “:q”',
    'enter' => 'Enter',

    'bulk_action' => 'Bulk action',
    'bulk_choose_action' => 'Choose action…',
    'bulk_assign' => 'Assign selected',
    'bulk_update_status' => 'Update status',
    'bulk_add_note' => 'Add internal note',
    'bulk_choose_assignee' => 'Choose assignee…',
    'bulk_choose_status' => 'Choose status…',
    'bulk_note' => 'Internal note',
    'bulk_note_placeholder' => 'Internal note for selected conversations…',
    'bulk_apply' => 'Apply',
    'bulk_processing' => 'Applying…',
    'bulk_selected_count' => ':count selected',
    'bulk_select_visible' => 'Select visible conversations',
    'bulk_select_visible_short' => 'Select visible',
    'bulk_select_conversation' => 'Select conversation #:id',
    'bulk_select_required' => 'Select at least one conversation.',
    'bulk_action_required' => 'Choose a bulk action.',
    'bulk_assignee_required' => 'Choose an assignee.',
    'bulk_status_required' => 'Choose a status.',
    'bulk_note_too_long' => 'The internal note must not exceed :max characters.',
    'bulk_note_required' => 'Enter an internal note.',
    'bulk_success' => 'Bulk action completed: :updated updated, :skipped skipped.',
    'bulk_no_updates' => 'No conversations were updated.',
    'bulk_failed' => 'Bulk action failed.',
    'recent_searches' => 'Recent searches',
];
