/* jQuery Context Menu Plugin

Version 1.01

Cory S.N. LaViska
A Beautiful Site (http://abeautifulsite.net/)

More info: http://abeautifulsite.net/2008/09/jquery-context-menu-plugin/

//
// Terms of Use
//
// This plugin is dual-licensed under the GNU General Public License
//   and the MIT License and is copyright A Beautiful Site, LLC.
//

This jQuery plugin now hardly resembles the original.

$Id$


*/
if(jQuery)( function() {
	
	$.extend($.fn, {
		
		contextMenu: function(o, callback) {
			// Defaults
			if( o.menu == undefined ) return false;
			if( o.inSpeed == undefined ) o.inSpeed = 150;
			if( o.outSpeed == undefined ) o.outSpeed = 75;
			// 0 needs to be -1 for expected results (no fade)
			if( o.inSpeed == 0 ) o.inSpeed = -1;
			if( o.outSpeed == 0 ) o.outSpeed = -1;
			if( o.button == undefined) o.button = 2;
			if( o.clickthrough == undefined) o.clickthrough = false;
			// Loop each context menu
			$(this).each( function() {
				
				var el = $(this);
								
				var menu = $('#'+o.menu);
				
				// Add contextMenu class
				$(menu).addClass('contextMenu').data('level',0);

				/*
				 * 
				 * Detach sub menus
				 * 
				 */
				var subMenus = function(root,level) {
				
					if(!level) level = 1;
					
					$(root).children('li').children('ul').each(function(){
						
						
						var plink = $(this).siblings('a').first();
						var subId = $(this).attr('id');
						if(!subId) {
							var href = plink.attr('href').replace('#','');
							subId = href + '-Submenu';
							$(this).attr('id', subId);
						}
						$(this).addClass('contextMenu contextSubMenu').data({'level':level}).parent().addClass('contextMenuParent').data({'subId':subId,'level':level});
						var html = plink.html();
						plink.html('<table class="vboxInvisible" style="width:100%"><tr><td style="text-align:left">'+html+'</td><td style="text-align:right; width: 22px;"><img src="images/rightArrow.png" /></td></tr></table>');

						// Hide menus trigger
						var smenu = this;
						$('#vboxIndex').bind('contextMenuShowLevel',function(e,c){
							if($(smenu).data('level') >= c.level && $(smenu).attr('id') != c.id) $(smenu).hide();
						});
						
						$(this).detach().appendTo($('#vboxIndex'));
						subMenus($(this),level + 1);
					});
									
				}
				
				/*
				 * 
				 * Activate menu items
				 * 
				 */
				var menuItems = function(menu, srcElement) {
					
					// When items are selected
					$(menu).find('A').unbind('click');
					$(menu).find('li').unbind('mouseenter').unbind('mouseleave');
					$(menu).find('LI:not(.disabled)').hover( function(e) {
						
						$(menu).find('LI.hover').removeClass('hover');
						$(this).addClass('hover');
						
						$('#vboxIndex').trigger('contextMenuShowLevel', {'level':$(this).parent().data('level'), 'id':$(this).parent().attr('id')});
						
						var subMenuId = $(this).data('subId');
						if(subMenuId) showMenu($(this),$('#'+subMenuId),'submenu',e);
						
					},function() {
						$(menu).find('LI.hover').removeClass('hover');
					}).children('A').click( function() {
						$(document).unbind('click');
						$(".contextMenu").hide();
						// Callback
						if( callback ) callback( $(this).attr('href').substr(1), $(srcElement), null, this); //{x: x - offset.left, y: y - offset.top, docX: x, docY: y} , this);
						return false;
					});
					
				}
				
				var showMenu = function(srcElement, menu, mode, e) {
					
					// Check menu
					if(!$(menu)[0]) return;

					// Detach sub menus
					subMenus(menu,$(menu).data('level')+1);
					
					// Hide all other menus at this level
					$('#vboxIndex').trigger('contextMenuShowLevel', {'level':$(menu).data('level'), 'id':$(menu).attr('id')});
					
					
					// Detect mouse position
					var d = {}, posX, posY;
					
					if(mode == 'menu') {
				 		var x = $(srcElement).offset().left;
			 			var y = $(srcElement).offset().top + $(srcElement).outerHeight();		
					} else if(mode == 'submenu') {
						var y = $(srcElement).offset().top;									
				 		var x = $(srcElement).offset().left + $(srcElement).outerWidth();
					} else {
						
						if( self.innerHeight ) {
							d.pageYOffset = self.pageYOffset;
							d.pageXOffset = self.pageXOffset;
							d.innerHeight = self.innerHeight;
							d.innerWidth = self.innerWidth;
						} else if( document.documentElement &&
							document.documentElement.clientHeight ) {
							d.pageYOffset = document.documentElement.scrollTop;
							d.pageXOffset = document.documentElement.scrollLeft;
							d.innerHeight = document.documentElement.clientHeight;
							d.innerWidth = document.documentElement.clientWidth;
						} else if( document.body ) {
							d.pageYOffset = document.body.scrollTop;
							d.pageXOffset = document.body.scrollLeft;
							d.innerHeight = document.body.clientHeight;
							d.innerWidth = document.body.clientWidth;
						}

						$(menu).css({'left':0,'top':0});

						(e.pageX) ? x = e.pageX : x = e.clientX + d.scrollLeft;
						(e.pageY) ? y = e.pageY : y = e.clientY + d.scrollTop;
						
					
					}
					//adjust to ensure menu is inside viewable screen
					var right = x + $(menu).outerWidth();
					var bottom = y + $(menu).outerHeight();
					
					var windowWidth = $(window).width() + $(window).scrollLeft()-5;
					var windowHeight = $(window).height() + $(window).scrollTop()-5;
					
					x = (right > windowWidth) ? x - (right - windowWidth) : x;
					y = (bottom > windowHeight) ? y - (bottom - windowHeight) : y;

					
					$(menu).one('menuLoaded',function(){
						menuItems(menu, srcElement);
					});
					
					// Check for callback if nothing is present
					if($(menu).children().length == 0 && $(menu).data('callback')) {
						
						
						var m = window[$(menu).data('callback')](menu);
						
						// New menu returned?
						if(m) {
							$(m).addClass('contextSubMenu contextMenuLevel' + ($(menu).data('level')+1)).data('level',($(menu).data('level')+1))
							// Hide menus trigger
							$('#vboxIndex').bind('contextMenuShowLevel',function(e,c){
								if($(m).data('level') >= c.level && $(m).attr('id') != c.id) $(m).hide();
							});
;
							showMenu(srcElement, m, 'submenu', e);
							return;
						}
					} else {
						menuItems(menu, srcElement);
					}
					
					// Menu  show
					$(menu).css({ top: y, left: x }).fadeIn(o.inSpeed);
					
					
				}
				
				// If we have sub-menus, activate them on hover
				// Simulate a true click
				$(this).mousedown( function(e) {
					if( $(el).hasClass('disabled') ) return true;
					if(!( e.button == o.button || (o.button == 0 && e.button == 1 && $.browser.msie))) return;
					if(o.clickthrough) $(el).trigger('click');
					var evt = e;
					evt.stopPropagation();
					$(this).mouseup( function(e) {
						e.stopPropagation();
						var srcElement = $(this);
						$(this).unbind('mouseup');
						if( evt.button == o.button || (o.button == 0 && evt.button == 1 && $.browser.msie)) {
							
							// Menu setup function
							if(o.menusetup) {
								o.menusetup(el);
							}
							// Hide context menus that may be showing
							$(".contextMenu").hide();

							// Show the menu
							$(document).unbind('click');
							
							showMenu(srcElement, menu, o.mode, e);
																					
							// Hide bindings
							setTimeout( function() { // Delay for Mozilla
								$(document).one('click', function() {
									$(menu).fadeOut(o.outSpeed);
									$(".contextMenu").hide();
									return false;
								});
							}, 0);
						}
					});
				});
				
				// Disable text selection
				$('#' + o.menu).disableSelection();
				
				// Disable browser context menu (requires both selectors to work in IE/Safari + FF/Chrome)
				$(el).add($('UL.contextMenu')).bind('contextmenu', function() { return false; });
				
			});
			return $(this);
		},
		
		// Disable context menu items on the fly
		disableContextMenuItems: function(o) {
			if( o == undefined ) {
				// Disable all
				$(this).find('LI').addClass('disabled');
				return( $(this) );
			}
			$(this).each( function() {
				if( o != undefined ) {
					var d = o.split(',');
					for( var i = 0; i < d.length; i++ ) {
						$(this).find('A[href="' + d[i] + '"]').closest('li').addClass('disabled');
						
					}
				}
			});
			return( $(this) );
		},
		
		// Enable context menu items on the fly
		enableContextMenuItems: function(o) {
			if( o == undefined ) {
				// Enable all
				$(this).find('LI.disabled').removeClass('disabled');
				return( $(this) );
			}
			$(this).each( function() {
				if( o != undefined ) {
					var d = o.split(',');
					for( var i = 0; i < d.length; i++ ) {
						$(this).find('A[href="' + d[i] + '"]').closest('li').removeClass('disabled');
						
					}
				}
			});
			return( $(this) );
		},
		
		// Disable context menu(s)
		disableContextMenu: function() {
			$(this).each( function() {
				$(this).addClass('disabled');
			});
			return( $(this) );
		},
		
		// Enable context menu(s)
		enableContextMenu: function() {
			$(this).each( function() {
				$(this).removeClass('disabled');
			});
			return( $(this) );
		},
		
		// Destroy context menu(s)
		destroyContextMenu: function() {
			// Destroy specified context menus
			$(this).each( function() {
				// Disable action
				$(this).unbind('mousedown').unbind('mouseup');
			});
			return( $(this) );
		}
		
	});
})(jQuery);