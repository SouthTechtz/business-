        </main>
    </div>
</div>

<div class="modal fade" id="appAlertModal" tabindex="-1" role="dialog" aria-labelledby="appAlertModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="appAlertModalTitle">Notice</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body" id="appAlertModalMessage"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<script>
    function showAppAlert(message, title, onClose) {
        var $modal = $('#appAlertModal');
        $modal.off('hidden.bs.modal');
        if (typeof onClose === 'function') {
            $modal.one('hidden.bs.modal', onClose);
        }
        $('#appAlertModalTitle').text(title || 'Notice');
        $('#appAlertModalMessage').text(message || 'An unexpected issue occurred.');
        $modal.modal('show');
    }

    if ($.fn.dataTable && $.fn.dataTable.ext) {
        $.fn.dataTable.ext.errMode = function (settings, techNote, message) {
            showAppAlert(message, 'Table Error');
        };
    }

    $(function () {
        $('table.data-table').each(function () {
            var configuredPageLength = parseInt($(this).attr('data-page-length'), 10);
            var table = $(this).DataTable({
                pageLength: configuredPageLength > 0 ? configuredPageLength : 10,
                lengthMenu: [[10, 20, 25, 50, -1], [10, 20, 25, 50, 'All']],
                order: [],
                dom: 'lrtip'
            });
            var input = $(this).closest('.card').find('.dt-search-input');
            if (input.length) {
                input.on('keyup search input', function () {
                    table.search(this.value).draw();
                });
            }
        });
    });

    function searchSuggest($input, $box, $scope) {
        if (!$box.length || !$input.length) return;
        var timer = null, currentQ = '';
        var inputEl = $input[0];
        var wrapEl = $input.closest('.search-wrap')[0];

        $(document).on('click', function (e) {
            if (!wrapEl.contains(e.target)) {
                $box.empty().hide();
            }
        });

        $input.on('input', function () {
            var q = this.value.trim();
            clearTimeout(timer);
            if (q.length < 2) { $box.empty().hide(); return; }
            timer = setTimeout(function () {
                if (currentQ === q) return;
                currentQ = q;
                $.getJSON('ajax-search.php', { q: q, scope: $scope }, function (data) {
                    var results = data.results || [];
                    if (q !== inputEl.value.trim()) return;
                    if (!results.length) {
                        $box.html('<div class="s-empty">No matches for "' + $('<span>').text(q).html() + '"</div>').show();
                        return;
                    }
                    var html = '', group = '';
                    $.each(results, function (_, r) {
                        if (r.group !== group) {
                            group = r.group;
                            html += '<div class="s-group">' + group + '</div>';
                        }
                        html += '<a href="' + encodeURI(r.url) + '">' +
                                '<div class="s-title">' + $('<span>').text(r.title).html() + '</div>' +
                                '<div class="s-sub">' + $('<span>').text(r.subtitle || '').html() + '</div>' +
                                '</a>';
                    });
                    $box.html(html).show();
                }).fail(function () { $box.empty().hide(); });
            }, 200);
        });

        $input.on('keydown', function (e) {
            if (e.key === 'Escape') { $box.empty().hide(); }
        });
        $input.on('focus', function () {
            if ($box.children().length) $box.show();
        });
    }

    $(function () {
        $('.dt-search-input').each(function () {
            var scope = $(this).data('scope') || 'all';
            var $wrap = $('<div class="search-wrap"></div>');
            $(this).wrap($wrap).after('<div class="search-suggest"></div>');
            searchSuggest($(this), $(this).next('.search-suggest'), scope);
        });
        searchSuggest($('#globalSearch'), $('#globalSearchSuggest'), 'all');
    });
</script>
</body>
</html>
