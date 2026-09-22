<% if not $node.IsInDB %><%-- Only render root node if it's the true root --%>
    <ul><li id="record-0" data-id="0" class="Root nodelete"><span class="jstree-icon jstree-icon--arrow"><span class="font-icon-right-dir" aria-hidden="true"></span>&nbsp;</span>
        <strong tabindex="-1">$rootTitle</strong>
<% end_if %>
<% if $limited %>
    <ul><li class="readonly">
        <span class="item">
            <%t SilverStripe\\CMS\\Controllers\\CMSMain.TOO_MANY_RECORDS 'Too many records' %>
            (<a href="{$listViewLink.ATT}" class="subtree-list-link" data-id="$node.ID" data-pjax-target="Content"><%t SilverStripe\\CMS\\Controllers\\CMSMain.SHOW_AS_LIST 'show as list' %></a>)
        </span>
    </li></ul>
<% else_if $children %>
    <ul>
        <% loop $children %>
            <li id="record-{$node.ID}" data-id="{$node.ID}" data-recordtype="{$node.ClassName}" class="$markingClasses $extraClass"><span class="jstree-icon jstree-icon--arrow"><span class="font-icon-right-dir" aria-hidden="true"></span>&nbsp;</span>
                <%-- IMPORTANT: There MUST NOT be any whitespace between the <a> element and the <ins> element below or it will break things in the JS --%>
                <a href="{$Controller.LinkRecordEdit($node.ID).ATT}" title="{$Title.ATT}"<% if $isCurrentPage %> tabindex="0" aria-current="page"<% else_if not $hasCurrentPage && $isFirstPage %> tabindex="0"<% else %> tabindex="-1"<% end_if %>><ins class="jstree-icon jstree-icon--drag-handle"><span class="font-icon-drag-handle" aria-hidden="true"></span>&nbsp;</ins>
                    <span class="text">{$TreeTitle}</span>
                </a>
                $SubTree
            </li>

        <% end_loop %>
    </ul>
<% end_if %>
<% if not $node.IsInDB %>
    </li></ul>
<% end_if %>


<%-- NOTE: Need to swap out LinkRecordEdit for a link to the docs page --%>
