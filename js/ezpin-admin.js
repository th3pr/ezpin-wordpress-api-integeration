jQuery(document).ready(function($) {
    $('#product_category').change(function() {
        $('#category-form').submit();
    });

    // logout
    $('#ezpin-logout-button').on('click', function(e) {
        e.preventDefault();

        $.ajax({
            url: ezpin_ajax_obj.ajax_url,
            type: 'post',
            data: {
                action: 'ezpin_logout',
                _ajax_nonce: ezpin_ajax_obj.nonce
            },
            success: function(response) {
                if (response.state === 'LogOut') {
                    alert('Successfully logged out');
                    location.reload();
                }
            },
            error: function(xhr, status, error) {
                console.log('Logout failed: ' + error);
            }
        });
    });

});
