<div class="modal fade" id="requestStockModal" tabindex="-1" role="dialog" aria-labelledby="requestStockModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form method="POST" action="<?php echo e($request_transfer_action); ?>" id="requestStockForm" class="modal-content">
            <?php echo csrf_field(); ?>
            <?php echo form_token_field(); ?>
            <div class="modal-header">
                <h5 class="modal-title" id="requestStockModalTitle">Request Stock</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger d-none" id="requestStockError" role="alert"></div>
                <div class="form-group">
                    <label>Request Destination</label>
                    <div class="form-control-plaintext font-weight-semibold">
                        <?php echo e($request_destination_label); ?>
                    </div>
                    <small class="form-text text-muted">The destination is fixed to your active account location.</small>
                </div>
                <div class="form-group">
                    <label for="requestStockSupplier">Supplying Store or Godown <span class="text-danger">*</span></label>
                    <select class="form-control" id="requestStockSupplier" name="source_warehouse_id" required>
                        <option value="">— Select a supplying location —</option>
                        <?php foreach ($request_source_locations as $location): ?>
                            <?php
                            $supplier_label = trim(($location['shop_name'] ?? '') . ' / ' . $location['warehouse_name'], ' /');
                            $supplier_type = ucfirst((string)($location['location_type'] ?? 'shop'));
                            ?>
                            <option value="<?php echo (int)$location['warehouse_id']; ?>">
                                <?php echo e($supplier_label . ' (' . $supplier_type . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-text text-muted">Products and quantities are searched at the selected supplying location.</small>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">Products</h6>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addRequestProduct">
                        <i class="fa fa-plus mr-1"></i> Add another product
                    </button>
                </div>
                <div id="requestProductRows"></div>
                <template id="requestProductRowTemplate">
                    <div class="request-product-row border rounded p-2 mb-2">
                        <div class="form-row align-items-start">
                            <div class="col-md-6 mb-2">
                                <label class="small mb-1">Search product</label>
                                <input type="search" class="form-control form-control-sm request-product-search"
                                       placeholder="Type a product name or code" autocomplete="off" disabled>
                                <div class="request-product-results list-group mt-1" style="position:relative; z-index:1051; max-height:220px; overflow-y:auto;"></div>
                                <input type="hidden" name="product_id[]" class="request-product-id">
                                <small class="form-text text-muted request-product-availability">Select a supplying location, then search.</small>
                            </div>
                            <div class="col-md-3 mb-2">
                                <label class="small mb-1">Requested quantity</label>
                                <input type="number" name="quantity[]" class="form-control form-control-sm request-product-quantity"
                                       min="1" step="1" placeholder="Quantity" required disabled>
                            </div>
                            <div class="col-md-3 mb-2 text-right">
                                <button type="button" class="btn btn-sm btn-light request-product-remove" title="Remove product">
                                    <i class="fa fa-times mr-1"></i> Remove
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
                <?php if (!$request_source_locations): ?>
                    <div class="alert alert-warning mb-0">No active eligible supplying location is available for this request.</div>
                <?php endif; ?>
                <div class="form-group mt-3 mb-0">
                    <label for="requestStockNotes">Notes</label>
                    <textarea name="notes" id="requestStockNotes" class="form-control" rows="2" maxlength="2000"
                              placeholder="Optional notes for the Inventory team"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" <?php echo $request_source_locations ? '' : 'disabled'; ?>>
                    <i class="fa fa-paper-plane mr-1"></i> Submit Request
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    $(function () {
        var $form = $('#requestStockForm');
        var $rows = $('#requestProductRows');
        var $supplier = $('#requestStockSupplier');
        var endpoint = 'ajax-transfer-product-search.php';
        var context = <?php echo json_encode($request_transfer_context, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

        function addProductRow() {
            var template = document.getElementById('requestProductRowTemplate');
            $rows.append(template.content.cloneNode(true));
            $rows.find('.request-product-search').last().prop('disabled', !$supplier.val());
        }

        function clearProductRow($row) {
            $row.find('.request-product-search').val('');
            $row.find('.request-product-id').val('');
            $row.find('.request-product-quantity').val('').prop('disabled', true).attr({ min: 1, step: 1 });
            $row.find('.request-product-availability').text(
                $supplier.val() ? 'Search and choose a product.' : 'Select a supplying location, then search.'
            );
            $row.find('.request-product-results').empty();
        }

        function escapeHtml(value) {
            return $('<div>').text(value == null ? '' : String(value)).html();
        }

        function searchProducts($row) {
            var query = $row.find('.request-product-search').val().trim();
            var sourceId = $supplier.val();
            var $results = $row.find('.request-product-results').empty();
            if (!sourceId || query.length < 2) return;

            var searchedSource = sourceId;
            $.getJSON(endpoint, $.extend({}, context, {
                source_warehouse_id: sourceId,
                q: query
            }), function (response) {
                if (searchedSource !== $supplier.val()
                    || query !== $row.find('.request-product-search').val().trim()) return;

                var products = response.products || [];
                if (!products.length) {
                    $results.html('<div class="list-group-item small text-muted">No matching products.</div>');
                    return;
                }
                $.each(products, function (_, product) {
                    var label = product.name + ' (' + product.code + ')';
                    var $link = $('<a href="#" class="list-group-item list-group-item-action small"></a>');
                    $link.html('<span>' + escapeHtml(label) + '</span><span class="float-right text-muted">'
                        + escapeHtml(product.available_label) + ' available</span>');
                    $link.data('product', product);
                    $results.append($link);
                });
            }).fail(function (xhr) {
                if (searchedSource !== $supplier.val()) return;
                var message = xhr.responseJSON && xhr.responseJSON.error
                    ? xhr.responseJSON.error
                    : 'Could not search products. Please try again.';
                $results.html('<div class="list-group-item small text-danger">'
                    + escapeHtml(message) + '</div>');
            });
        }

        function scheduleSearch($input) {
            var timer = $input.data('searchTimer');
            window.clearTimeout(timer);
            var $row = $input.closest('.request-product-row');
            var query = $input.val().trim();
            $row.find('.request-product-id').val('');
            $row.find('.request-product-quantity').val('').prop('disabled', true);
            $row.find('.request-product-availability').text('Choose a product from the search results.');
            if (!query || query.length < 2) {
                $row.find('.request-product-results').empty();
                return;
            }
            $input.data('searchTimer', window.setTimeout(function () {
                searchProducts($row);
            }, 250));
        }

        addProductRow();

        $('#addRequestProduct').on('click', addProductRow);
        $supplier.on('change', function () {
            $('#requestStockError').addClass('d-none').text('');
            $rows.find('.request-product-row').each(function () {
                clearProductRow($(this));
                $(this).find('.request-product-search').prop('disabled', !$supplier.val());
            });
        });

        $rows.on('input', '.request-product-search', function () {
            scheduleSearch($(this));
        });
        $rows.on('click', '.request-product-results a', function (event) {
            event.preventDefault();
            var $link = $(this);
            var product = $link.data('product');
            var $row = $link.closest('.request-product-row');
            var decimal = Number(product.is_decimal) === 1;
            $row.find('.request-product-search').val(product.name + ' (' + product.code + ')');
            $row.find('.request-product-id').val(product.product_id);
            $row.find('.request-product-quantity')
                .prop('disabled', false)
                .attr({ min: decimal ? '0.01' : '1', step: decimal ? '0.01' : '1' });
            $row.find('.request-product-availability').text(product.available_label + ' currently available at this location.');
            $row.find('.request-product-results').empty();
        });
        $rows.on('click', '.request-product-remove', function () {
            var $row = $(this).closest('.request-product-row');
            if ($rows.find('.request-product-row').length === 1) {
                clearProductRow($row);
                return;
            }
            $row.remove();
        });

        $form.on('submit', function (event) {
            var validProducts = 0;
            $rows.find('.request-product-row').each(function () {
                var $row = $(this);
                var productId = $row.find('.request-product-id').val();
                var quantity = Number($row.find('.request-product-quantity').val());
                if (productId && Number.isFinite(quantity) && quantity > 0) validProducts++;
            });
            if (!$supplier.val() || validProducts === 0) {
                event.preventDefault();
                $('#requestStockError')
                    .text(!$supplier.val()
                        ? 'Select a supplying location first.'
                        : 'Search for and select at least one product, then enter a valid quantity.')
                    .removeClass('d-none');
            }
        });
    });
</script>
