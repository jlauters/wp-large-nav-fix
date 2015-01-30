jQuery(document).ready(function($) {

    // Hijack the WP Menu Save form submit action
    $('#update-nav-menu').submit(function() {
        large_nav_save();
        return false;
    });

    // Ajax Save function that restructures the form data to avoid the max_input_vars issue
    large_nav_save = function() {

        // Throw up spinners while AJAX processes.
        $('.publishing-action :input').before('<span class="menu-save-status"><img style="vertical-align: middle;" src="/wp-admin/images/wpspin_light.gif"/></span>&nbsp;');

        // Traverse the nav tree. Pull out the inputs and serialize into URL encoded string.
        var serialized   = $('#menu-to-edit > .menu-item > .menu-item-settings').find(':input').serialize();
        var update_nonce = $('#update-nav-menu-nonce').val();
        var menu         = $('#menu').val();
        var menu_name    = $('#menu-name').val();

        try {
            jQuery.ajax({
                type: 'POST',
                url: WPJS.siteurl + '?action=wp-large-save-nav',
                data: {
                    menu: menu,
                    menu_name: menu_name,
                    update_nav_menu_nonce: update_nonce,
                    items: serialized
                },
                success: function(data, textStatus, jqXHR) {
                    $('.menu-save-status').remove();
                    location.reload(true);
                },
                error: function(XMLHttpRequest, textStatus, errorThrown) {
                    // Do something more with the error?
                    console.log(errorThrown);
                }
            });
        } catch(err) {
            alert(err);
        }
    }
});
