jQuery(document).ready(function() {
    
    optt_load_term();
    optt_order_posts();
    
});


/**
 * Initialise le formulaire principale :
 * - rend la liste des articles triable
 * - Au clic sur un des boutons radio, on enregistre la préférence concernée *
 */
function optt_order_posts()
{
	
    // make the list sortable ##
    jQuery("#sortable-list").sortable(
        {
            update: function( event, ui ) {

                jQuery('#spinner_order_posts').show();

                data = {
                    'action'			: 'order_posts',
                    'order'			: jQuery(this).sortable('toArray').toString(),
                    'term_id'			: jQuery(this).attr("rel"),
                    'secure_posts'              : OPTT.secure_posts
                }
                jQuery.post(ajaxurl, data, function (response){

                    if ( OPTT.debug ) jQuery('#debug').append( jQuery("<div class='debug'>"+response+"</div>") );
                    jQuery('#spinner_order_posts').hide();

                });
            }
        }
    );

    // Au clic sur les boutons radio on enrehistre les préférences ##
    jQuery("#form_result input.option_order").change(function (){

        jQuery('#spinner_radio').show();

        if ( 
            jQuery("#form_result input.option_order:checked").val() ==  "true" 
            && jQuery("#sortable-list li").length >= 2 
        ){
            jQuery('#spinner_radio').show();

            data = {
                'action'			: 'order_posts',
                'order'				: jQuery("#sortable-list").sortable('toArray').toString(),
                'term_id'			: jQuery("#sortable-list").attr("rel"),
                'secure_posts'                  : OPTT.secure_posts
            }
            jQuery.post(ajaxurl, data, function (response){
                if ( OPTT.debug ) jQuery('#debug').append( jQuery("<div class='debug'>"+response+"</div>") );
                jQuery('#spinner_radio').hide();
            });

        }

        jQuery("#form_result input.option_order").attr('disabled', 'disabled');

        data = {
            'action'                    : 'term_status',
            'term_id'                   : jQuery("#term_id").val(),
            'status'                    : jQuery("#form_result input.option_order:checked").val(),
            'secure_taxonomy'           : OPTT.secure_taxonomy
        }

        jQuery.post( ajaxurl, data, function (response){
            if ( OPTT.debug ) jQuery('#debug').append( jQuery("<div class='debug'>"+response+"</div>") );
            jQuery('#spinner_radio').hide();
            jQuery("#form_result input.option_order").attr('disabled', false);
        });

        return false;

    })
    
}

/**
 * Initialise le comportement JavaScript lors du choix de catégorie (premier formulaire)
 * Au changement, on stocke le slug de la taxonomie concerné dans un champs caché
 * et on soulet le formulaire
 */
function optt_load_term()
{
    
    jQuery("#select_term_to_load").change(
        function(event){
            
            var taxonomy = jQuery("#select_term_to_load option:selected").parent().attr("id");
            jQuery("#taxonomy_id").val(taxonomy);
            jQuery("form#select_term").submit();
            
        }
    );
        
}