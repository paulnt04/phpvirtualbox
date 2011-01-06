/*
 * 
 * phpVirtualBox tree view for snapshots
 * 
 * $Id$
 * Copyright (C) 2011 Ian Moore (imoore76 at yahoo dot com)
 * 
 */

(function($) {


	$.fn.vbtree = function(options, toplevel) {
		
		if(!toplevel)
			var toplevel = this;
	
	this.each(function() {
       
		$(this).addClass('vboxTreeView').children('li').each(function(i,li){
		
			// Change class
			/////////////////////
			var children = $(li).children('ul').length;
			var last = !$(this).next().is('li');
			var classadd = null;
			
			// Children and last
			if(children && !last) {
				classadd = 'collapsable';
			// Children but no last
			} else if(children && last) {
				classadd = 'lastCollapsable';
			} else if(!children && last) {
				classadd = 'last';
			}
			$(li).addClass(classadd);
			
			// Update vboxListItem elements
			$(li).children('div.vboxListItem').first().click(function(){
				$(toplevel).find('div.vboxListItemSelected').first().removeClass('vboxListItemSelected');
				$(this).addClass('vboxListItemSelected');
				$(toplevel).trigger('select',$(this).parent());
			});

			// Insert hitarea
			var d = document.createElement('div');
			$(d).addClass('hitarea').addClass((classadd ? classadd + '-hitarea' : '')).toggle(
				function(){
					if($(this).hasClass('last-hitarea')) return;
					if($(this).hasClass('lastCollapsable-hitarea'))
						$(this).addClass('lastExpandable-hitarea').removeClass('lastCollapsable-hitarea').parent().parent().children('ul').css({'display':'none'});
					else
						$(this).addClass('expandable-hitarea').removeClass('collapsable-hitarea').parent().parent().children('ul').css({'display':'none'});
				},
				function(){
					if($(this).hasClass('last-hitarea')) return;				
					if($(this).hasClass('lastExpandable-hitarea'))
						$(this).addClass('lastCollapsable-hitarea').removeClass('lastExpandable-hitarea').parent().parent().children('ul').css({'display':''});
					else
						$(this).addClass('collapsable-hitarea').removeClass('expandable-hitarea').parent().parent().children('ul').css({'display':''});				
				}
			);
			$(li).children('div').first().prepend(d);
									
			// Tree each UL under li one
			$(li).children('ul').vbtree({},toplevel);
						
			
		});
    
	});
 
	return this;
 
	};
 
})(jQuery);
