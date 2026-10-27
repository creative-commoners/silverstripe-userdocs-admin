<div class="cms-tree-filtered cms-notice flexbox-area-grow">
    <strong><%t SilverStripe\UserDocs\Admin\UserDocsAdmin.TreeFiltered 'Showing search results' %></strong>
    <a href="javascript:void(0)" class="clear-filter">
        <%t SilverStripe\UserDocs\Admin\UserDocsAdmin.TreeFilteredClear 'Clear' %>
    </a>

    <nav class="awawa" aria-label="<%t SilverStripe\UserDocs\Admin\UserDocsAdmin.SearchResults 'Documentation Search Results' %>">
        <%-- @TODO if no results show "did you mean x", etc --%>
        <ol>
        <% loop $Results %>
            <li>ID: $id
                <ul>
                    <li>title: $title</li>
                    <li>slug: $slug</li>
                    <li>URL: $Top.Link($Top.join_links('docs', $slug))</li>
                    <li>locale: $matched_locale</li>
                    <li>isFallback: $is_fallback</li>
                    <li>score: $score</li>
                    <li>summary: $Top.SearchResultSummary($id)</li>
                </ul>
            </li>
        <% end_loop %>
        </ol>

        <% with $Results %>
        <% if $MoreThanOnePage %>
            <%-- @TODO Need styling to match gridfield pagination and add some JS so the textfield bit works as expected --%>
            <nav class="pagination-nav" aria-label="<%t SilverStripe\UserDocs\Admin\UserDocsAdmin.Pagination 'Pagination' %>">
                <a <% if $FirstLink && $NotFirstPage %>href="$FirstLink"<% end_if %> class="btn btn-secondary btn--no-text btn-sm<% if not $FirstLink || $FirstPage %> disabled<% end_if %>"
                    title="<%t SilverStripe\UserDocs\Admin\UserDocsAdmin.PaginationFirst 'First' %>"
                    aria-label="<%t SilverStripe\UserDocs\Admin\UserDocsAdmin.PaginationFirst 'First' %>"
                >
                    <span aria-hidden="true" class="btn__icon font-icon-angle-double-left"></span>
                </a>
                <a <% if $PrevLink %>href="$PrevLink"<% end_if %> class="btn btn-secondary btn--no-text btn-sm <% if not $PrevLink %> disabled<% end_if %>"
                    title="<%t SilverStripe\UserDocs\Admin\UserDocsAdmin.PaginationPrevious 'Previous' %>"
                    aria-label="<%t SilverStripe\UserDocs\Admin\UserDocsAdmin.PaginationPrevious 'Previous' %>"
                >
                    <span aria-hidden="true" class="btn__icon font-icon-angle-left"></span>
                </a>
                <span class="pagination-page-number">
                    <%t SilverStripe\UserDocs\Admin\UserDocsAdmin.PaginationPage 'Page' %>
                    <input
                    class="text no-change-track"
                    title="<%t SilverStripe\UserDocs\Admin\UserDocsAdmin.PagionationInputTitle 'Current page, currently {current} of {total}' current=$CurrentPage total=$TotalPages %>"
                    value="$CurrentPage"
                    data-skip-autofocus="true"
                    />
                    <%t SilverStripe\UserDocs\Admin\UserDocsAdmin.PagionationOf 'of' is 'Example: View 1 of 2' %>
                    $TotalPages
                </span>
                <a <% if $NextLink %>href="$NextLink"<% end_if %> class="btn btn-secondary btn--no-text btn-sm<% if not $NextLink %> disabled<% end_if %>"
                    title="<%t SilverStripe\UserDocs\Admin\UserDocsAdmin.PaginationNext 'Next' %>"
                    aria-label="<%t SilverStripe\UserDocs\Admin\UserDocsAdmin.PaginationNext 'Next' %>"
                >
                    <span aria-hidden="true" class="btn__icon font-icon-angle-right"></span>
                </a>
                <a <% if $LastLink && $NotLastPage %>href="$LastLink"<% end_if %> class="btn btn-secondary btn--no-text btn-sm<% if not $LastLink || $LastPage %> disabled<% end_if %>"
                    title="<%t SilverStripe\UserDocs\Admin\UserDocsAdmin.PaginationLast 'Last' %>"
                    aria-label="<%t SilverStripe\UserDocs\Admin\UserDocsAdmin.PaginationLast 'Last' %>"
                >
                    <span aria-hidden="true" class="btn__icon font-icon-angle-double-right"></span>
                </a>
                </nav>

        <% end_if %>
        <% end_with %>
    </nav>
</div>
