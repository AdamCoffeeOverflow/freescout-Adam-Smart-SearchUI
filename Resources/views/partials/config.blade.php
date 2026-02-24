{{--
  Tiny, CSP-safe config node.
  JS reads these to stay subdirectory-safe and to respect module settings.
--}}
<span
    id="adamsmartsearchui-config"
    data-search-url="{{ route('adamsmartsearchui.search') }}"
    data-suggest-url="{{ route('adamsmartsearchui.suggest') }}"
    data-use-core="{{ config('adamsmartsearchui.use_core_search_icon') ? '1' : '0' }}"
    data-hide-core="{{ config('adamsmartsearchui.hide_core_search_icon') ? '1' : '0' }}"
    data-show-inline="{{ config('adamsmartsearchui.show_topbar') ? '1' : '0' }}"
    style="display:none"
></span>
