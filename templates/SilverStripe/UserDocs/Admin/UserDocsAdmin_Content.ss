<% if $CurrentDocSlug %>
<%-- Left and right panel together (i.e. we are viewing a docc page) --%>
<div class="has-panel cms-content flexbox-area-grow fill-width fill-height $BaseCSSClasses" data-layout-type="border" data-pjax-fragment="Content">
	<%-- Tools includes the left panel --%>
    $Tools
    <%-- The rest of this is the right panel --%>
    <div class="fill-height flexbox-area-grow">
		<div class="panel panel--padded panel--scrollable flexbox-area-grow fill-height docs-shadow-root">
            <template shadowrootmode="open">
                <% loop $CssFiles %>
                    <link rel="stylesheet" href="$resourceURL($Me)">
                <% end_loop %>
			    $RenderedDocs
            </template>
		</div>
	</div>
</div>

<% else %>
<%-- Left panel only --%>
<div id="pages-controller-cms-content" class="flexbox-area-grow fill-height cms-content $BaseCSSClasses" data-layout-type="border" data-pjax-fragment="Content">
    <% include SilverStripe\\UserDocs\\Admin\\UserDocsAdmin_LeftPanel %>
</div>
<% end_if %>
