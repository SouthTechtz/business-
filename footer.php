        </main>
    </div>
</div>

<script>
    $(function () {
        $('table.data-table').each(function () {
            var table = $(this).DataTable({
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, 'All']],
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
