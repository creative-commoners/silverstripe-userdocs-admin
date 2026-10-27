<div class="cms-content-header north vertical-align-items">
    <div class="cms-content-header-info fill-width vertical-align-items">
        <div class="breadcrumbs-wrapper">
            <span class="crumb last">$SectionTitle</span>
        </div>
        $SearchForm.FilterButton($TreeIsFiltered) <%-- @TODO here and elsewhere the button/filtering is added, only add it if search is supported (pdo installed or redis setup correctly) --%>
    </div>
</div>
<div class="panel panel--scrollable cms-panel-content flexbox-area-grow fill-height">
    $SearchForm.Placeholder($TreeIsFiltered)
    <% if $TreeIsFiltered %>
        $SearchResults
    <% else %>
    <div
        tabindex="-1"
        class="panel panel--padded panel--scrollable flexbox-area-grow fill-height flexbox-display cms-content-view cms-tree-view-sidebar cms-panel-deferred"
        data-url="$Link('treeview')"
        data-url-treeview="$Link('treeview')"
        data-no-ajax="false"
    >
    </div>
    <% end_if %>
</div>
<%-- This is a bit of a hack to get the current doc slug passed in by jstree when calling treeview --%>
<div class="cms-edit-form visually-hidden"><input type="hidden" value="$CurrentDocSlugAsId.RAWURLATT.HTMLATT" name="ID"></div>
