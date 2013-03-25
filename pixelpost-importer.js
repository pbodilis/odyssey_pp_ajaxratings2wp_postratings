var migrate_stop = false;
var current_pp_post_idx = -1;

jQuery(document).on('click', '#pp_ajaxRatings2wp_postRatings_migration_stop', function(e) {
    migrate_stop = true;

    e.preventDefault();
    return false;
});

jQuery(document).on('click', '#pp_ajaxRatings2wp_postRatings_migration_resume', function(e) {
    migrate_stop = false;
    migrate();

    e.preventDefault();
    return false;
});

function migrate() {
    var pp_post_id = pp_post_ids[++current_pp_post_idx];
    if (typeof pp_post_id != 'undefined') {
        jQuery.ajax({
            url:      ajaxurl,
            dataType: 'json',
            data:     {action: 'pp_ajaxRating2wp_postRating_migrate', pp_post_id: pp_post_id}
        }).done(function(p) {
            jQuery('#pp_ajaxRatings2wp_postRatings_migration_log').html(Math.round((current_pp_post_idx + 1) * 10000.0 / pp_post_ids.length) / 100 + '% done!');
            if ( ! migrate_stop) {
                setTimeout('migrate()', 1);
            }
        });
    }
}

jQuery(document).ready(function($) {
    migrate();
});
