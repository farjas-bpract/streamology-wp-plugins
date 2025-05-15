jQuery(document).ready(function($) {
    $('#mlm-sync-products').on('click', function(e) {
        e.preventDefault();
        if (confirm('Are you sure you want to sync all products? This may take some time.')) {
            $.ajax({
                url: mlmSync.ajax_url,
                type: 'POST',
                data: {
                    action: 'mlm_sync_all_products',
                    nonce: mlmSync.nonce
                },
                beforeSend: function() {
                    $('#mlm-sync-products').prop('disabled', true).text('Syncing...');
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                    } else {
                        alert('Failed to sync products: ' + response.data.message);
                    }
                },
                error: function() {
                    alert('An error occurred while syncing products.');
                },
                complete: function() {
                    $('#mlm-sync-products').prop('disabled', false).text('Sync All Products');
                }
            });
        }
    });
});
