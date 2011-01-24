/*
 * 
 * phpVirtualBox medium (disk / CD image etc.) select box
 * 
 * $Id$
 * Copyright (C) 2011 Ian Moore (imoore76 at yahoo dot com)
 * 
 */

(function($) {

	$.fn.mediumselect = function(options) {
		
		/* Defaults */
		if(!options.type) options.type = 'HardDisk';
		if(!options.mediums) options.mediums = [];
		
		/* Select Medium */
		function selectmedium(d,sel) {
						
			if($(d).hasClass('vboxMediumReadOnly')) {
				$(sel).addClass('vboxMediumSelectReadOnly').addClass('vboxMediumReadOnly');
			} else {
				$(sel).removeClass('vboxMediumSelectReadOnly').removeClass('vboxMediumReadOnly');
			}
			
			// Set text
			$(sel).html(($(d).data('label') ? $(d).data('label') : ''));
			
			// Hide list
			$('#'+$(sel).attr('id')+'-list').hide();
			
			// Set hidden select box value and
			// trigger change
			var old = $('#'+$(sel).data('origId'));
			$(old).val($(d).data('id'));
			$(old).trigger('change',old);


		}
		

		/* Generate and return list item */
		function listItem(m,sel,old,children) {
			
			var li = document.createElement('li');
			
			
			var d = document.createElement('div');
			d.setAttribute('id',$(sel).attr('id')+'-'+m.attachedId);
			
			var opt = $(old).children('option[value='+m.attachedId+']');
			
			if($(opt).hasClass('vboxMediumReadOnly')) {
				$(d).addClass('vboxMediumReadOnly');
				$(li).addClass('vboxMediumReadOnly');
			}
			
			if($(opt).attr('title')) {
				$(d).attr('title',$(opt).attr('title'));
				$(d).tipped({'source':'title'});
			}
			
			$(d).addClass('vboxMediumSelectDiv').hover(function(){$(this).addClass('vboxMediumSelectHover')},function(){$(this).removeClass('vboxMediumSelectHover')});			
			$(d).html(m.label);
			$(d).data('label',m.label);
			$(d).data('id',m.attachedId);
			
			$(d).click(function(){selectmedium(this,sel);});
			
			$(li).append(d);

			// Traverse children
			if(children && m.children && m.children.length) {
				var ul = document.createElement('ul');
				for(var c = 0; c < m.children.length; c++) {
					$(ul).append(listItem(m.children[c],sel,old,true));
				}
				$(li).append(ul);	
			}
			
			return li;	
		}
		
		/* Show list */
		function showList(sel) {
			
			var list = $('#'+$(sel).attr('id')+'-list');
			var sTop = $(sel).offset().top + $(sel).outerHeight();
			var sLeft = $(sel).offset().left;
			var sWidth = $(sel).outerWidth() + $(sel).parentsUntil('table').parent().find('.vboxMediumSelectImg').outerWidth();
						
			// Hide menu when clicking anywhere else
			$(document).one('click',function(){$(list).hide();});
			
			$(list).css({'left':sLeft+'px','top':sTop+'px','min-width':sWidth}).show();
			
			return false;

		}
		
		/* 
		 * Main
		 */
		this.each(function() {
			
			// Generate select box replacement
			if(!$('#'+$(this).attr('id')+'-mediumselect').attr('id')) {
				
				var sel = document.createElement('div');
				$(sel).data('origId', $(this).attr('id'));
				$(sel).attr('id',$(this).attr('id')+'-mediumselect');
				$(sel).attr('class','vboxMediumSelect');
				$(this).hide();
				
				var img = document.createElement('div');
				img.setAttribute('id',$(this).attr('id')+'-mediumselectimg');
				img.setAttribute('class','vboxMediumSelectImg');
				
				var tbl = document.createElement('table');
				$(tbl).attr('id',$(this).attr('id')+'-table');
				$(tbl).attr('class','vboxMediumSelect');
				$(tbl).css({'padding':'0px','margin':'0px','border':'0px','width':'100%','border-spacing':'0px'});
				var tr = document.createElement('tr');
				var td = document.createElement('td');
				$(td).css({'padding':'0px','margin':'0px','border':'0px','width':'100%'});
				$(td).append(sel);
				$(tr).append(td);
				td = document.createElement('td');
				$(td).css({'padding':'0px','margin':'0px','border':'0px','width':'auto'});
				$(td).append(img);
				$(tr).append(td);
				$(tbl).append(tr);
				
				// Handle enabled / disabled
				$(tbl).bind('enable',function(){
					$(this).removeClass('vboxDisabled');
				}).bind('disable',function(){
					$(this).addClass('vboxDisabled');
				});
				
				$(this).before(tbl);
				
				$(sel).click(function(){
					if($('#'+$(this).data('origId')+'-table').hasClass('vboxDisabled')) return;
					return showList(this);
				});
				
				var list = document.createElement('ul');
				$(list).attr('id',$(this).attr('id')+'-mediumselect-list');
				$(list).attr('class', 'vboxMediumSelect');
				$(list).css({'display':'none'});
				
				$('#vboxIndex').append(list);
			}

			// Hide list if it exists
			$('#'+$(this).attr('id')+'-mediumselect-list').hide();
					
			// Set background image to medium attachment type	       
			var bgimg = 'hd';
			switch(options.type) {
				case 'DVD': bgimg = 'cd'; break;
				case 'Floppy': bgimg = 'fd'; break;
			}
			$('#'+$(this).attr('id')+'-mediumselectimg').css({'background-image':'url(images/vbox/'+bgimg+'_16px.png)'});
			
			// Compile list
			var list = $('#'+$(this).attr('id')+'-mediumselect-list');
			$(list).children().remove();
			
			var sel = $('#'+$(this).attr('id')+'-mediumselect');
			var old = this;
			
			for(var i = 0; i < options.mediums.length; i++) {
				if(options.mediums[i].base && options.mediums[i].id != options.mediums[i].base) continue;
				$(list).append(listItem(options.mediums[i],sel,old,options.showdiff));
			}

			// Set initial text and styles
			var oldopt = $(this).children('option:eq('+Math.max($(this).attr('selectedIndex'),0)+')');
			
			if(!$(oldopt).val()) {
				selectmedium($(list).find('div').first(), sel, old);
			} else {
				selectmedium($('#'+$(sel).attr('id')+'-'+$(oldopt).val()), sel, old);
			}
		}); // </ .each() >
	 
		return this;
 
	}; // </mediumselect()>
	
})(jQuery);
