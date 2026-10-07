<% if $node.isRoot %><ul><% end_if %>
$controller.classname
<%-- TODO: Add the appropriate marking classes and note iscurrentpage etc. --%>
<li id="record-{$controller.CurrentDocSlugAsId($node.slug).RAWURLATT.HTMLATT}" data-id="{$controller.CurrentDocSlugAsId($node.slug).RAWURLATT.HTMLATT}" class="<% if $node.isRoot %>Root nodelete <% end_if %>$markingClasses">
    <span class="jstree-icon jstree-icon--arrow"><span class="font-icon-right-dir" aria-hidden="true"></span>&nbsp;</span>
    <% if $node.slug %>
        <%-- IMPORTANT: There MUST NOT be any whitespace between the <a> element and the <ins> element below or it will break things in the JS --%>
        <%-- @TODO Get rid of the drag handle in a way that doesnt add a default one. Visually hidden works but is a hack! --%>
        <%-- @TODO Get rid of the right click menu --%>
        <%-- @TODO Find out why indentation of the first below root doesnt work as expected --%>
        <a href="{$controller.Link($controller.join_links('docs', $node.slug)).ATT}" title="{$Title.ATT}"<% if $node.isCurrentPage %> tabindex="0" aria-current="page"<% else_if not $node.hasCurrentPage && $node.isFirstPage %> tabindex="0"<% else %> tabindex="-1"<% end_if %>><ins class="jstree-icon jstree-icon--drag-handle"><span class="font-icon-drag-handle" aria-hidden="true"></span>&nbsp;</ins>
            <span class="text">{$node.title}</span>
        </a>
    <% else %>
        <strong tabindex="-1">$node.title</strong>
    <% end_if %>

<% if $children %>
    <ul>
        <% loop $children %>
            <% include SilverStripe/UserDocs/Admin/UserDocsAdmin_SubTree controller=$Up.controller %>
        <% end_loop %>
    </ul>
<% end_if %>

</li>
<% if $node.isRoot %></ul><% end_if %>
