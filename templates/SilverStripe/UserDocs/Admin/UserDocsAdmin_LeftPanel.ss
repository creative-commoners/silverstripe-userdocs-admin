<div class="cms-content-header north vertical-align-items">
    <div class="cms-content-header-info fill-width vertical-align-items">
        <div class="breadcrumbs-wrapper">
            <span class="cms-panel-link crumb last">$SectionTitle</span>
        </div>
        <div class="menu-button"><span class="menu-button__icon font-icon-list" aria-hidden="true"></span><%t SilverStripe\UserDocs\Admin\UserDocsAdmin.ShowTreeContents 'Contents' %></div>
    </div>
</div>
<div class="panel panel--scrollable cms-panel-content flexbox-area-grow fill-height">

    <div
        class="panel panel--padded panel--scrollable flexbox-area-grow fill-height flexbox-display cms-content-view cms-tree-view-sidebar cms-panel-deferred"
        data-url="$Link('treeview')"
        data-url-treeview="$Link('treeview')"
        data-no-ajax="<% if $TreeIsFiltered %>true<% else %>false<% end_if %>"
    >
    </div>
</div>
<%-- This is a bit of a hack to get the current doc slug passed in by jstree when calling treeview --%>
<div class="cms-edit-form visually-hidden"><input type="hidden" value="$CurrentDocSlugAsId.RAWURLATT.HTMLATT" name="ID"></div>
