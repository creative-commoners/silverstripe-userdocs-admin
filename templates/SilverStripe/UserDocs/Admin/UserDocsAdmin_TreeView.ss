<% if $TreeIsFiltered %>
    <div class="cms-tree-filtered cms-notice flexbox-area-grow">
        <strong><%t SilverStripe\CMS\Controllers\CMSMain.TreeFiltered 'Showing search results.' %></strong><%-- @TODO proper localisation --%>
        <a href="javascript:void(0)" class="clear-filter">
            <%t SilverStripe\CMS\Controllers\CMSMain.TreeFilteredClear 'Clear' %>
        </a>

        <nav class="cms-tree filtered-list no-context-menu" aria-label="Documentation Navigation"
            data-url-tree="$LinkWithSearch($Link('getsubtree')).ATT"
            data-extra-params="SecurityID=$SecurityID.ATT">
            $TreeAsUL
        </nav>
    </div>
<% else %>
    <nav class="cms-tree flexbox-area-grow no-context-menu" aria-label="Documentation Navigation"
        data-url-tree="$LinkWithSearch($Link('getsubtree')).ATT"
        data-extra-params="SecurityID=$SecurityID.ATT">
        $TreeAsUL
    </nav>
<% end_if %>
