<div class="cms-content-header north vertical-align-items">
    <div class="cms-content-header-info fill-width vertical-align-items">
        <% if $TreeIsFiltered %>
            <% include SilverStripe\\Admin\\BackLink_Button Backlink=$BreadcrumbsBacklink %>
        <% end_if %>
        <%-- Explicit breadcrumb item for this menu section --%>
        <div class="section-heading flexbox-area-grow">
            <span class="section-label">$MenuCurrentItem.Title</span>
        </div>
        $SearchForm.FilterButton($TreeIsFiltered) Search button here
    </div>
</div>
<div class="panel panel--scrollable cms-panel-content flexbox-area-grow fill-height">
    $SearchForm.Placeholder($TreeIsFiltered)

    <div
        class="panel panel--padded panel--scrollable flexbox-area-grow fill-height flexbox-display cms-content-view cms-tree-view-sidebar cms-panel-deferred"
        data-url="$Link('treeview')"
        data-url-treeview="$Link('treeview')"
        <%-- data-url-listview="$LinkListViewDeferred"
        data-url-listviewroot="$LinkListViewRoot" --%>
        data-no-ajax="<% if $TreeIsFiltered %>true<% else %>false<% end_if %>"
    >
        <%-- if $TreeIsFiltered %>
            <% include SilverStripe\\CMS\\Controllers\\CMSMain_ListView %>
        <% else %>
            Lazy-loaded via ajax
        <% end_if --%>
    </div>
</div>
<%-- This is a bit of a hack to get the current doc slug passed in when calling treeview --%>
<div class="cms-edit-form visually-hidden"><input type="hidden" value="$CurrentDocSlugAsId.RAWURLATT.HTMLATT" name="ID"></div>
