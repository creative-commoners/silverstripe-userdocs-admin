<div class="has-panel cms-content flexbox-area-grow fill-width fill-height $BaseCSSClasses" data-layout-type="border" data-pjax-fragment="Content">

	$Tools

    <%-- Tools has the left panel. This is the right panel. --%>
    <div class="fill-height flexbox-area-grow">
		<div class="cms-content-header north">
			<div class="cms-content-header-info flexbox-area-grow vertical-align-items">
				<% include SilverStripe\\Admin\\BackLink_Button Backlink=$BreadcrumbsBacklink %>
				<% include SilverStripe\\Admin\\CMSBreadcrumbs %> <%-- REPLACE THIS with docs-specific breadcrumbs --%>
			</div>
		</div>

		<div class="flexbox-area-grow fill-height">
			Actual docs go here
		</div>
	</div>

    <%-- thoughts from earlier
    Maybe the preview panel gets co-opted for the right panel where docs go.
    Just need links in the tree to set the preview panel location, and set the width to be wider than normal. Boom.
    The trick then is getting the tree to update when navigating in the preview panel without refreshing everything.
    Look at how Danni got his preview panel updating when updating shit in the edit form.
    https://github.com/silverstripeltd/fabric/blob/develop/client/channels/frame-communicator.js
    https://github.com/silverstripeltd/fabric/blob/develop/client/channels/action-bar-communicator.js

    Or we could do something CMSMain-y - I'm not sure how specific that logic and templating is to silverstripe/cms right now.

    Alternatively this could be an entirely new admin paradigm, but I don't really wanna do that.

    Or we could use the react-based preview panel, which might open up some avenues, but then we need a react tree as well.
    I'd rather not go there.
    --%>

</div>
