<div class="cms-content-tools fill-height cms-panel cms-panel-layout" data-expandOnClick="true" data-layout-type="border" id="cms-content-tools-UserDocsAdmin">

    <%-- left panel --%>
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

    <%-- left panel (collapsed) --%>
    <div class="cms-panel-content-collapsed">
        <h3 class="cms-panel-header">Collapsed menu title here?</h3>
    </div>
    <div class="toolbar toolbar--south cms-panel-toggle">
        <button
            class="cms-panel-toggle__button"
            title="<%t SilverStripe\\Admin\\LeftAndMain.CollapsePanel "Collapse panel" %>"
            data-bs-toggle="tooltip"
            aria-expanded="true"
            aria-controls="cms-content-tools-CMSMain"
            data-expanded-label="&laquo;"
            data-expanded-title="<%t SilverStripe\\Admin\\LeftAndMain.CollapsePanel "Collapse panel" %>"
            data-collapsed-label="&raquo;"
            data-collapsed-title="<%t SilverStripe\\Admin\\LeftAndMain.ExpandPanel "Expand panel" %>"
        >&laquo;</button>
    </div>

</div>
