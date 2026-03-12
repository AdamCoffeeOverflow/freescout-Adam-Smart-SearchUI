{{--
  Tiny, CSP-safe config node.
  JS reads these to stay subdirectory-safe and to respect module settings.
--}}
<span
    id="adamsmartsearchui-config"
    data-search-url="{{ route('adamsmartsearchui.search') }}"
    data-suggest-url="{{ route('adamsmartsearchui.suggest') }}"
    data-fields-url="{{ route('adamsmartsearchui.fields') }}"
    data-recent-meta-url="{{ route('adamsmartsearchui.recent_meta') }}"
    data-i18n-any-custom-field="{{ __('adamsmartsearchui::messages.any_custom_field') }}"
    data-i18n-loading-fields="{{ __('adamsmartsearchui::messages.loading_fields') }}"
    data-i18n-loading-recent="{{ __('adamsmartsearchui::messages.loading_recent') }}"
    data-use-core="{{ config('adamsmartsearchui.use_core_search_icon') ? '1' : '0' }}"
    data-hide-core="{{ config('adamsmartsearchui.hide_core_search_icon') ? '1' : '0' }}"
    data-show-inline="{{ config('adamsmartsearchui.show_topbar') ? '1' : '0' }}"
    data-i18n-inline-placeholder="{{ __('adamsmartsearchui::messages.inline_placeholder') }}"
    data-i18n-focus-search="{{ __('adamsmartsearchui::messages.focus_search') }}"
    data-i18n-open-smart-search="{{ __('adamsmartsearchui::messages.open_smart_search') }}"
    data-i18n-search="{{ __('adamsmartsearchui::messages.search') }}"
    data-i18n-suggestions="{{ __('adamsmartsearchui::messages.suggestions') }}"
    data-i18n-search-smart-for="{{ __('adamsmartsearchui::messages.search_smart_for') }}"
    data-i18n-enter="{{ __('adamsmartsearchui::messages.enter') }}"
    data-i18n-recent-searches="{{ __('adamsmartsearchui::messages.recent_searches') }}"
    hidden
></span>
