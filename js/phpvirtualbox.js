/*

 * 	Common classes used
 * 
 * $Id$
 * Copyright (C) 2011 Ian Moore (imoore76 at yahoo dot com)
 * 
 */


/*
 * Common VM Actions - These assume that they will be run on the
 * selected VM as stored in $('#vboxIndex').data('selectedVM')
 */
var vboxVMActions = {
		
	/* New VM Wizard */
	'new':{
			'label':'New',
			'icon':'vm_new',
			'icon_16':'new',
			'click':function(){vboxWizardNewVMInit(function(){return;})}
	},
	
	/* Add a VM */
	'add': {
		'label':'Add',
		'icon':'vm_add',
		'click':function(){
			vboxFileBrowser($('#vboxIndex').data('vboxSystemProperties').defaultMachineFolder,function(f){
				if(!f) return;
				var l = new vboxLoader();
				l.mode = 'save';
				l.add('addVM',function(){},{'file':f});
				l.onLoad = function(){
					var lm = new vboxLoader();
					lm.add('Mediums',function(d){$('#vboxIndex').data('vboxMediums',d);});
					lm.onLoad = function() {$('#vboxIndex').trigger('vmlistreload');}
					lm.run();
				}
				l.run();
				
			},false);
		}
	},

	/* Start VM */
	'start' : {
		'name' : 'start',
		'label' : 'Start',
		'icon' : 'vm_start',
		'icon_16' : 'start',
		'click' : function (btn) {
		
			// Disable toolbar button that triggered this action?
			if(btn && btn.toolbar) btn.toolbar.disableButton(btn);
			
			vboxAjaxRequest('setStateVMpowerUp',{'vm':$('#vboxIndex').data('selectedVM').id},function(d){
				// check for progress operation
				if(d && d.progress) {
					var icon = null;
					if($('#vboxIndex').data('selectedVM').state == 'Saved') icon = 'progress_state_restore_90px.png';
					else icon = 'progress_start_90px.png';
					vboxProgress(d.progress,function(){$('#vboxIndex').trigger('vmlistrefresh');},{},icon);
					return;
				}
				$('#vboxIndex').trigger('vmlistrefresh');
			});
			
		},
		'enabled' : function (vm) { return (vm && (jQuery.inArray(vm.state,['PoweredOff','Paused','Saved','Aborted']) > -1));}	
	},
	
	/* VM Settings */
	'settings': {
		'label':'Settings',
		'icon':'vm_settings',
		'icon_16':'settings',
		'click':function(){
			if($('#vboxIndex').data('selectedVM') && $('#vboxIndex').data('selectedVM').state == 'Running') return;
			
			vboxVMsettingsInit($('#vboxIndex').data('selectedVM').id,function(){
				$('#vboxIndex').trigger('vmselect',[$('#vboxIndex').data('selectedVM')]);
			});
		},
		'enabled':function(vm){ return vm && (vm.state == 'PoweredOff' || vm.state == 'Aborted'); }
	},
	
	/* Refresh a VM */
	'refresh': {
		'label':'Refresh',
		'icon':'refresh',
		'icon_disabled':'refresh_disabled',
		'click':function(){
			var l = new vboxLoader();
			l.add('VMDetails',function(d){
				// Special case for host refresh
				if(d.id == 'host') {
					$('#vboxIndex').data('vboxHostDetails',d);
				}
				$('#vboxIndex').trigger('vmselect',[$('#vboxIndex').data('selectedVM')]);
			},{'vm':$('#vboxIndex').data('selectedVM').id,'force_refresh':1});
			
			// Host refresh also refreshes system properties, VM sort order
			if($('#vboxIndex').data('selectedVM').id == 'host') {
				l.add('SystemProperties',function(d){$('#vboxIndex').data('vboxSystemProperties',d);},{'force_refresh':1});
				l.add('VMSortOrder',function(d){return;},{'force_refresh':1});
			}
			l.run();
    	},
		'enabled':function(vm){ return vm; }
    },
    
    /* Delete / Remove a VM */
    'remove' : {
		'label':'Remove',
		'icon':'delete',
		'click':function(){

			var buttons = {};

			/* Unregister Inaccessible or Delete? */
			if($('#vboxIndex').data('selectedVM').state == 'Inaccessible') {
				
				buttons[trans('Unregister')] = function(){
					$(this).empty().remove();
					var l = new vboxLoader();
					l.add('removeVM',function(){},{'vm':$('#vboxIndex').data('selectedVM').id,'unregister':1});
					l.mode = 'save';
					l.onLoad = function(){$('#vboxIndex').trigger('vmlistreload');};
					l.run();
				}
				var q = trans('Unregister VM Message1').replace('%s','<b>'+$('#vboxIndex').data('selectedVM').name+'</b>') + '<p>'+trans('Unregister VM Message2')+'</p>';
				
			} else {
				buttons[trans('Delete all files')] = function(){
					$(this).empty().remove();
					vboxAjaxRequest('removeVM',{'vm':$('#vboxIndex').data('selectedVM').id,'delete':1},function(d){
						// check for progress operation
						if(d && d.progress) {
							vboxProgress(d.progress,function(){$('#vboxIndex').trigger('vmlistreload');},{},'progress_delete_90px.png');
							return;
						}
						$('#vboxIndex').trigger('vmlistreload');
					});
				}
				buttons[trans('Remove only')] = function(){
					$(this).empty().remove();
					vboxAjaxRequest('removeVM',{'vm':$('#vboxIndex').data('selectedVM').id,'keep':1},function(d){
						// check for progress operation
						if(d && d.progress) {
							vboxProgress(d.progress,function(){$('#vboxIndex').trigger('vmlistreload');});
							return;
						}
						$('#vboxIndex').trigger('vmlistreload');
					});
				}
				
				var q = trans('Delete VM Message1').replace('%s','<b>'+$('#vboxIndex').data('selectedVM').name+'</b>') + '<p>'+trans('Delete VM Message2')+'</p>';
			}				
			vboxConfirm(q,buttons);
    	
    	},
		'enabled':function(vm){ return vm && (vm.state == 'PoweredOff' || vm.state == 'Aborted' || vm.state == 'Inaccessible'); }
    },
    
    /* Discard VM State */
    'discard' : {
		'label':'Discard',
		'icon':'discard',
		'click':function(){
			var buttons = {};
			buttons[trans('Discard')] = function(){
				$(this).empty().remove();
				var l = new vboxLoader();
				l.add('setStateVMdiscardSavedState',function(){},{'vm':$('#vboxIndex').data('selectedVM').id});
				l.mode = 'save';
				l.onLoad = function(){$('#vboxIndex').trigger('vmlistrefresh');};
				l.run();
			}
			vboxConfirm(trans('Discard Message1').replace('%s','<b>'+$('#vboxIndex').data('selectedVM').name+'</b>') + '<p><b>'+trans('Discard Message2')+'</b></p>',buttons);
		},
		'enabled':function(vm){ return (vm && vm.state == 'Saved'); }
    },
    
    /* Show VM Logs */
    'logs' : {
		'label':'Show Log',
		'icon':'show_logs',
		'icon_disabled':'show_logs_disabled',
		'click':function(){
    		vboxShowLogsDialogInit($('#vboxIndex').data('selectedVM').id);
		},
		'enabled':function(vm){ return (vm && vm.id && vm.id != 'host'); }
    },

    /* Save VM State */
	'savestate' : {
		'label' : 'Save State',
		'icon' : 'fd',
		'enabled' : function(vm){ return (vm && vm.state == 'Running'); },
		'click' : function() {vboxVMActions.powerAction('savestate');}
	},
	/* Send sleep button */
	'sleep' : {
		'label' : 'ACPI Sleep Button',
		'icon' : 'acpi',
		'enabled' : function(vm){ return (vm && vm.state == 'Running'); },
		'click' : function() {vboxVMActions.powerAction('sleep');}
	},
	/* Send Power Button */
	'powerbutton' : {
		'label' : 'ACPI Power Button',
		'icon' : 'acpi',
		'enabled' : function(vm){ return (vm && vm.state == 'Running'); },
		'click' : function() {vboxVMActions.powerAction('powerbutton');}
	},
	/* Pause VM */
	'pause' : {
		'label' : 'Pause',
		'icon' : 'pause',
		'icon_disabled' : 'pause_disabled',
		'enabled' : function(vm){ return (vm && vm.state == 'Running'); },
		'click' : function() {vboxVMActions.powerAction('pause'); }
	},
	/* Power Off VM */
	'powerdown' : {
		'label' : 'Power Off',
		'icon' : 'poweroff',
		'enabled' : function(vm) { return (vm && jQuery.inArray(vm.state,['Running','Paused','Stuck']) > -1); },
		'click' : function() {vboxVMActions.powerAction('powerdown'); }
	},
	/* Reset VM */
	'reset' : {
		'label' : 'Reset',
		'icon' : 'reset',
		'enabled' : function(vm){ return (vm && vm.state == 'Running'); },
		'click' : function() {vboxVMActions.powerAction('reset'); }
	},
	
	/* Power Action Helper function */
	'powerAction' : function(pa){
		switch(pa) {
			case 'powerdown': fn = 'setStateVMpowerDown'; icon='progress_poweroff_90px.png'; break;
			case 'powerbutton': fn = 'setStateVMpowerButton'; break;
			case 'sleep': fn = 'setStateVMsleepButton'; break;
			case 'savestate': fn = 'setStateVMsaveState'; icon='progress_state_save_90px.png'; break;
			case 'pause': fn = 'setStateVMpause'; break;
			case 'reset': fn = 'setStateVMreset'; break;
			default: return;
		}
		vboxAjaxRequest(fn,{'vm':$('#vboxIndex').data('selectedVM').id},function(d){
			// check for progress operation
			if(d && d.progress) {
				vboxProgress(d.progress,function(){
					if(pa != 'reset' && pa != 'sleep' && pa != 'powerbutton') $('#vboxIndex').trigger('vmlistrefresh');
				},{},icon);
				return;
			}
			if(pa != 'reset' && pa != 'sleep' && pa != 'powerbutton') $('#vboxIndex').trigger('vmlistrefresh');
		});		
		
	}
    
}
	

/*
 * Wizard (new HardDisk or VM)
 */
function vboxWizard(name, title, img, bg, icon) {
	
	var self = this;
	this.steps = 0;
	this.name = name;
	this.title = title;
	this.img = img;
	this.finish = null;
	this.width = 700;
	this.height = 400;
	this.bg = bg;
	
	// Initialize / display dialog
	this.run = function() {

		var d = $('<div />').attr({'id':this.name+'Dialog','style':'display: none','class':'vboxWizard'});
		
		var f = $('<form />').attr({'name':('frm'+this.name),'onSubmit':'return false;','style':'height:100%;margin:0px;padding:0px;border:0px;'});

		// main table
		var tbl = $('<table />').attr({'class':'vboxWizard','style':'height: 100%; margin:0px; padding:0px;border:0px;'});
		var tr = $('<tr />');
		
		if(this.img) {
			$('<td />').attr('class','vboxWizardImg').html('<img src="' + self.img + '" />').appendTo(tr);
		}
		
		var td = $('<td />').attr({'id':self.name+'Content','class':'vboxWizardContent'});
		if(self.bg) {
			/*
			 Disabled for now. Must run on Mac to see what Oracle was going for.
			if($.browser.msie)
				$(td).css({"filter":"progid:DXImageTransform.Microsoft.AlphaImageLoader(enabled='true', src='"+this.bg+"', sizingMethod='scale')"});
			else
				$(td).css({'background':'url('+this.bg+') top left no-repeat','-moz-background-size':'auto 100%','background-size':'auto 100%','-webkit-background-size':'auto 100%'});
			*/
				
		}
		// Title and content table
		var t = $('<h3 />').attr('id',self.name+'Title').html(self.title).appendTo(td);

		$(tr).append(td).appendTo(tbl);		
		
		f.append(tbl);
		d.append(f);
		
		$('#vboxIndex').append(d);
		
		
		// load panes
		var l = new vboxLoader();
		l.addFile('panes/'+self.name+'.html',function(f,name){
			$('#'+name+'Content').append(f);
			},self.name);
		
		l.onLoad = function(){
			
			// Opera hidden select box bug
			////////////////////////////////
			if($.browser.opera) {
				$('#'+self.name+'Content').find('select').bind('change',function(){
					$(this).data('vboxSelected',$(this).val());
				}).bind('show',function(){
					$(this).val($(this).data('vboxSelected'));
				}).each(function(){
					$(this).data('vboxSelected',$(this).val());
				});
			}

			// buttons
			var buttons = { };
			buttons['< '+trans('Back')] = self.displayPrev;
			buttons[trans('Next')+' >'] = self.displayNext;
			buttons[trans('Cancel')] = self.close;
			
			vboxSetLangContext('vbox'+self.name);
			vboxInitDisplay(self.name+'Content');
			vboxUnsetLangContext();
			
			$(d).dialog({'closeOnEscape':false,'width':self.width,'height':'auto','buttons':buttons,'modal':true,'autoOpen':true,'stack':true,'dialogClass':'vboxDialogContent vboxWizard','title':(icon ? '<img src="images/vbox/'+icon+'_16px.png" class="vboxDialogTitleIcon" /> ' : '') + self.title});

			self.displayStep(1);
		};
		l.run();
				
	}
	
	self.close = function() {
		$('#'+self.name+'Dialog').trigger('close').empty().remove();
	}
	
	self.displayStep = function(step) {
		self._curStep = step;
		for(var i = 0; i < self.steps; i++) {
			$('#'+self.name+'Step'+(i+1)).css({'display':'none'});
		}
		/* update buttons */
		if(step == 1) {
			$('#'+self.name+'Dialog').parent().find('span:contains("< '+trans('Back')+'")').parent().addClass('disabled');
			$('#'+self.name+'Dialog').parent().find('span:contains("'+trans('Finish')+'")').html($('<div />').text(trans('Next')+' >').html());
		} else {
			
			$('#'+self.name+'Dialog').parent().find('span:contains("< '+trans('Back')+'")').parent().removeClass('disabled');
			
			if(step == self.steps) {
				$('#'+self.name+'Dialog').parent().find('span:contains("'+trans('Next')+' >")').html($('<div />').text(trans('Finish')).html());
			} else {
				$('#'+self.name+'Dialog').parent().find('span:contains("'+trans('Finish')+'")').html($('<div />').text(trans('Next')+' >').html());
			}
		}
		$('#'+self.name+'Title').html(trans($('#'+self.name+'Step'+step).attr('title')));
		$('#'+self.name+'Step'+step).css({'display':''});

		// Opera hidden select box bug
		////////////////////////////////
		if($.browser.opera) {
			$('#'+self.name+'Step'+step).find('select').trigger('show');
		}

		$('#'+self.name+'Step'+step).trigger('show',self);

	}
	
	self.displayPrev = function() {
		if(self._curStep <= 1) return;
		self.displayStep(self._curStep - 1);
	}
	self.displayNext = function() {
		if(self._curStep >= self.steps) {
			self.onFinish(self,$('#'+self.name+'Dialog'));
			return;
		}
		self.displayStep(self._curStep + 1);
	}
	
}
/*
 * Common toolbar
 */
function vboxToolbar(buttons) {

	var self = this;
	self.buttons = buttons;
	self.size = 22;
	self.addHeight = 24;
	self.lastItem = null;
	self.id = null;
	self.buttonStyle = '';

	// Called on list item selection change
	self.update = function(target,item) {
		
		// Event target or manually passed item
		self.lastItem = (item||target);
		
		for(var i = 0; i < self.buttons.length; i++) {
			if(self.buttons[i].enabled && !self.buttons[i].enabled(self.lastItem)) {
				self.disableButton(self.buttons[i]);
			} else {
				self.enableButton(self.buttons[i]);
			}
		}		
	}

	self.enable = function() {
		self.update(self.lastItem);
	}

	self.disable = function() {
		for(var i = 0; i < self.buttons.length; i++) {
			self.disableButton(self.buttons[i]);
		}		
	}
	
	self.enableButton = function(b) {
		$('#vboxToolbarButton-'+self.id+'-'+b.name).addClass('vboxEnabled').removeClass('vboxDisabled').children('img.vboxToolbarImg').attr('src','images/vbox/'+b.icon+'_'+self.size+'px.png');
	}

	self.disableButton = function(b) {
		$('#vboxToolbarButton-'+self.id+'-'+b.name).addClass('vboxDisabled').removeClass('vboxEnabled').children('img.vboxToolbarImg').attr('src','images/vbox/'+b.icon+'_disabled_'+self.size+'px.png');
	}

	// Generate HTML element for button
	self.buttonElement = function(b) {

		// Pre-load disabled version of icon if enabled function exists
		if(b.enabled) {
			var a = new Image();
			a.src = "images/vbox/"+b.icon+"_disabled_"+self.size+"px.png";
		}
		
		// TD
		var td = document.createElement('td');
		$(td).attr({'id':'vboxToolbarButton-' + self.id + '-' + b.name,
			'class':'vboxToolbarButton ui-corner-all vboxEnabled vboxToolbarButton'+self.size,
			'style':self.buttonStyle+'; min-width: '+(self.size+12)+'px;'
		}).html('<img src="images/vbox/'+b.icon+'_'+self.size+'px.png" class="vboxToolbarImg" /><br />' + $('<div />').html(trans(b.label)).text()).bind('click',function(){
			if($(this).hasClass('vboxDisabled')) return;
			$(this).data('toolbar').click($(this).data('name'));
		// store data
		}).data(b);
		
		if(!self.noHover) {
			$(td).hover(
					function(){if($(this).hasClass('vboxEnabled')){$(this).addClass('vboxToolbarButtonHover');}},
					function(){$(this).removeClass('vboxToolbarButtonHover');}		
			).mousedown(function(e){
				if($.browser.msie && e.button == 1) e.button = 0;
				if(e.button != 0 || $(this).hasClass('vboxDisabled')) return true;
				$(this).addClass('vboxToolbarButtonDown');
				var btn = $(this)
				$(document).one('mouseup',function(){
					$(btn).removeClass('vboxToolbarButtonDown');
				});
			});
		}
		
		return td;
		
	}

	// Add buttons to element with id
	this.addButtons = function(id) {
		
		self.id = id;
		self.height = self.size + self.addHeight; 
		
		//Create table
		var tbl = document.createElement('table');
		tbl.setAttribute('class','vboxToolbar vboxToolbar'+this.size);
		var tr = document.createElement('tr');
		
		for(var i = 0; i < self.buttons.length; i++) {
			self.buttons[i].toolbar = self;
			$(tr).append(self.buttonElement(self.buttons[i]));
			// If button can be enabled / disabled, disable by default
			if(self.buttons[i].enabled) {
				self.disableButton(self.buttons[i]);
			}
			if(self.buttons[i].separator) {
				var td = document.createElement('td');
				$(td).attr('class','vboxToolbarSeparator').html('<br />').appendTo(tr);
			}

		}

		$(tbl).append(tr);
		$('#'+id).append(tbl).addClass('vboxToolbar vboxToolbar'+this.size).bind('disable',self.disable).bind('enable',self.enable);
		
	}

	// return button by name
	self.getButtonByName = function(n) {
		for(var i = 0; i < self.buttons.length; i++) {
			if(self.buttons[i].name == n)
				return self.buttons[i];
		}
		return null;
	}
	
	// send "click" to named button
	self.click = function(btn) {
		var btn = self.getButtonByName(btn);
		return btn.click(btn);
	}
		
}

function vboxToolbarSmall(buttons) {

	var self = this;
	this.selected = null;
	this.buttons = buttons;
	this.lastItem = null;
	this.buttonStyle = '';
	this.enabled = true;
	this.size = 16;
	this.disabledString = 'disabled';
	this.mode = 'toolbar';

	// Called on list item selection change
	self.update = function(target,item) {
		
		if(!self.enabled) return;
		
		self.lastItem = (item||target);
		
		for(var i = 0; i < self.buttons.length; i++) {
			if(self.buttons[i].enabled && !self.buttons[i].enabled(self.lastItem)) {
				self.disableButton(self.buttons[i]);
			} else {
				self.enableButton(self.buttons[i]);
			}
		}		
	}

	self.enable = function() {
		self.enabled = true;
		self.update(self.lastItem);
	}

	self.disable = function() {
		self.enabled = false;
		for(var i = 0; i < self.buttons.length; i++) {
			self.disableButton(self.buttons[i]);
		}		
	}
	
	self.enableButton = function(b) {
		if(b.noDisabledIcon)
			$('#vboxToolbarButton-' + self.id + '-' + b.name).css('display','').attr('disabled','');
		else
			$('#vboxToolbarButton-' + self.id + '-' + b.name).css('background-image','url(images/vbox/' + b.icon + '_'+self.size+'px.png)').attr('disabled','');
	}
	self.disableButton = function(b) {
		if(b.noDisabledIcon)
			$('#vboxToolbarButton-' + self.id + '-' + b.name).css('display','none').attr('disabled','disabled').removeClass('vboxToolbarSmallButtonHover').addClass('vboxToolbarSmallButton');
		else
			$('#vboxToolbarButton-' + self.id + '-' + b.name).css('background-image','url(images/vbox/' + b.icon + '_'+self.disabledString+'_'+self.size+'px.png)').attr('disabled','disabled').removeClass('vboxToolbarSmallButtonHover').addClass('vboxToolbarSmallButton');
	}

	// Generate HTML element for button
	self.buttonElement = function(b) {

		// Pre-load disabled version of icon if enabled function exists
		if(b.enabled) {
			var a = new Image();
			a.src = "images/vbox/" + b.icon + "_" + self.disabledString + "_" + self.size + "px.png";
		}
		
		var btn = document.createElement('input');
		$(btn).attr({'id':'vboxToolbarButton-' + self.id + '-' + b.name,'type':'button','value':'',
			'class':'vboxImgButton vboxToolbarSmallButton ui-corner-all',
			'title':trans(b.label),
			'style':self.buttonStyle+' background-image: url(images/vbox/' + b.icon + '_'+self.size+'px.png);'
		}).click(b.click);		
		
		if(!self.noHover) {
			$(btn).hover(
					function(){if($(this).attr('disabled')!='disabled'){$(this).addClass('vboxToolbarSmallButtonHover').removeClass('vboxToolbarSmallButton');}},
					function(){$(this).addClass('vboxToolbarSmallButton').removeClass('vboxToolbarSmallButtonHover');}		
			);
		
		}
		
		return btn;
		
	}

	// Add buttons to element with id
	self.addButtons = function(id) {
		
		self.id = id;
		
		var targetElm = $('#'+id);
		
		if(!self.buttonStyle)
			self.buttonStyle = 'height: ' + (self.size+8) + 'px; width: ' + (self.size+8) + 'px; ';
		
		for(var i = 0; i < self.buttons.length; i++) {
			
			$(targetElm).append(self.buttonElement(self.buttons[i]));
			
			if(self.buttons[i].separator) {
				var hr = document.createElement('hr');
				$(hr).attr({'style':'display: inline','class':'vboxToolbarSmall vboxSeperatorLine'});
				$(targetElm).append(hr);
			}
				
		}

		$(targetElm).attr({'name':self.name}).addClass('vboxToolbarSmall vboxEnablerTrigger').bind('disable',self.disable).bind('enable',self.enable);
		
	}
	
	// Click named button
	self.click = function(btn) {
		for(var i = 0; i < self.buttons.length; i++) {
			if(self.buttons[i].name == btn)
				return self.buttons[i].click();
		}
		return false;
	}
		
}

/*
 * Media menu 
 */
function vboxButtonMediaMenu(type,callback,mediumPath) {
	
	var self = this;
	this.buttonStyle = '';
	this.enabled = true;
	this.size = 16;
	this.disabledString = 'disabled';
	this.type = type;
	this.lastItem = null;
	
	self.mediaMenu = new vboxMediaMenu(type,callback,mediumPath);
	
	// Buttons
	self.buttons = {};
	self.buttons['HardDisk'] = {
			'name' : 'mselecthdbtn',
			'label' : 'Set up the virtual hard disk',
			'icon' : 'hd',
			'click' : function () {
				return;				
			}
	};
	
	self.buttons['DVD'] = {
			'name' : 'mselectcdbtn',
			'label' : 'Set up the virtual CD/DVD drive',
			'icon' : 'cd',
			'click' : function () {
				return;				
			}
	};
	
	self.buttons['Floppy'] = {
			'name' : 'mselectfdbtn',
			'label' : 'Set up the virtual floppy drive',
			'icon' : 'fd',
			'click' : function () {
				return;				
			}
	};
	
	// Set button
	self.button = self.buttons[self.type];

	// Called on list item selection change
	self.update = function(target,item) {
		
		if(!self.enabled) return;
		
		self.lastItem = (item||target);
		
		if(self.button.enabled && !self.button.enabled(self.lastItem)) {
			self.disableButton();
		} else {
			self.enableButton();
		}
	}
	
	self.enableButton = function() {
		var b = self.button;
		$('#vboxButtonMenuButton-' + self.id + '-' + b.name).css('background-image','url(images/vbox/' + b.icon + '_'+self.size+'px.png)').removeClass('vboxDisabled');
	}
	self.disableButton = function() {
		var b = self.button;
		$('#vboxButtonMenuButton-' + self.id + '-' + b.name).css('background-image','url(images/vbox/' + b.icon + '_'+self.disabledString+'_'+self.size+'px.png)').removeClass('vboxToolbarSmallButtonHover').addClass('vboxDisabled');
	}

	// Enable menu
	self.enable = function() {
		self.enabled = true;
		self.update(self.lastItem);
	}

	// Disable menu
	self.disable = function() {
		self.enabled = false;
		self.disableButton();
	}
	
	
	// Generate HTML element for button
	self.buttonElement = function() {

		var b = self.button;
		
		// Pre-load disabled version of icon if enabled function exists
		if(b.enabled) {
			var a = new Image();
			a.src = "images/vbox/" + b.icon + "_" + self.disabledString + "_" + self.size + "px.png";
		}
		
		return $('<td />').attr({'id':'vboxButtonMenuButton-' + self.id + '-' + b.name,'type':'button','value':'',
			'class':'vboxImgButton vboxToolbarSmallButton vboxButtonMenuButton ui-corner-all',
			'title':trans(b.label),
			'style':self.buttonStyle+' background-image: url(images/vbox/' + b.icon + '_'+self.size+'px.png);text-align:right;vertical-align:bottom;'
		}).click(function(e){
			$(this).addClass('vboxButtonMenuButtonDown');
			var tbtn = $(this);
			e.stopPropagation();
			e.preventDefault();
			$(document).one('mouseup',function(){
				$(tbtn).removeClass('vboxButtonMenuButtonDown');
			});
		}).html('<img src="images/downArrow.png" style="margin:0px;padding:0px;float:right;width:6px;height:6px;" />').hover(
					function(){if(!$(this).hasClass('vboxDisabled')){$(this).addClass('vboxToolbarSmallButtonHover');}},
					function(){$(this).removeClass('vboxToolbarSmallButtonHover');}		
		);
		
		
	}
	
	// Return a jquery object containing button element.
	self.getButtonElm = function () {
		return $('#vboxButtonMenuButton-' + self.id + '-' + self.button.name);
	}

	// Add button to element with id
	self.addButton = function(id) {
		
		self.id = id;
		
		var targetElm = $('#'+id);
		
		if(!self.buttonStyle)
			self.buttonStyle = 'height: ' + (self.size + ($.browser.msie || $.browser.webkit ? 3 : 7)) + 'px; width: ' + (self.size+10) + 'px; ';
		
		var tbl = document.createElement('table');
		$(tbl).attr({'style':'border:0px;margin:0px;padding:0px;'+self.buttonStyle});
		var tr = document.createElement('tr');
		$(tr).css({'vertical-align':'bottom'}).append(self.buttonElement()).appendTo(tbl);
		
		$(targetElm).attr({'name':self.name}).addClass('vboxToolbarSmall vboxButtonMenu vboxEnablerTrigger').bind('disable',self.disable).bind('enable',self.enable).append(tbl);
		
		// Generate and attach menu
		var m = self.mediaMenu.menuElement();
		
		self.getButtonElm().contextMenu({
	 		menu: self.mediaMenu.menu_id(),
	 		mode:'menu',
	 		button: 0
	 	},self.mediaMenu.menuCallback);
		
		
	}
	
	self.menuUpdateRemoveMedia = function(enabled) {
		self.mediaMenu.menuUpdateRemoveMedia(enabled);
	}
}

/*
 * Button media menu object
 */
function vboxMediaMenu(type,callback,mediumPath) {

	var self = this;
	this.type = type;
	this.callback = callback;
	this.mediumPath = mediumPath;
	this.storage = new vboxStorage();
	this.removeEnabled = true;

	
	// Generate menu element ID
	self.menu_id = function(){
		return 'vboxMediaListMenu'+this.type;
	}
		
	// Generate menu element
	self.menuElement = function() {
		
		// Pointer already held
		if(self._menu) return self._menu;
		
		var id = self.menu_id();
		var elm = $('#'+id);
		if(!elm.attr('id')) {
			var ul = document.createElement('ul');
			ul.setAttribute('class','contextMenu');
			ul.setAttribute('style','display: none');
			ul.setAttribute('id',id);
			$('#vboxIndex').append(ul);
			elm = $('#'+id);
		} else {
			elm.children().remove();
		}
		
		// Hold pointer
		self.menuAddDefaults(elm);
		self.menuUpdateRecent();
		self._menu = elm;
		return elm;
	}
	
	// Add host drives to menu
	self.menuAddDrives = function(ul) {
		
		// Add host drives
		var meds = self.storage.mediumsForAttachmentType(self.type);
		for(var i =0; i < meds.length; i++) {
			if(!meds[i].hostDrive) continue;
			var li = document.createElement('li');
			$(li).html("<a href='#"+meds[i].id+"'>"+self.storage.getMediumName(meds[i])+"</a>").appendTo(ul);
		}
		
	}
	
	
	// Add defaults to menu
	self.menuAddDefaults = function (ul) {
		
		switch(this.type) {
			
			// HardDisk defaults
			case 'HardDisk':
				
				var li = document.createElement('li');
				li.innerHTML = "<a href='#createD' style='background-image: url(images/vbox/hd_new_16px.png);' >"+trans('Create a new hard disk...')+"</a>";
				$(ul).append(li);

				var li = document.createElement('li');
				$(li).html("<a href='#chooseD' style='background-image: url(images/vbox/select_file_16px.png);' >"+trans('Choose a virtual hard disk file...')+"</a>").appendTo(ul);
				
				// Add VMM?
				if($('#vboxIndex').data('vboxConfig').enableAdvancedConfig) {
					var li = document.createElement('li');
					$(li).html("<a href='#vmm' style='background-image: url(images/vbox/diskimage_16px.png);' >"+trans('Virtual Media Manager')+"</a>").appendTo(ul);					
				}

				// Hidden elm
				var li = document.createElement('li');
				$(li).addClass('vboxMediumRecentBefore').css('display','none').appendTo(ul);
				
				break;
				
			// CD/DVD Defaults
			case 'DVD':
				
				var li = document.createElement('li');
				$(li).html("<a href='#chooseD' style='background-image: url(images/vbox/select_file_16px.png);' >"+trans('Choose a virtual CD/DVD disk file...')+"</a>").appendTo(ul);

				// Add VMM?
				if($('#vboxIndex').data('vboxConfig').enableAdvancedConfig) {
					var li = document.createElement('li');
					$(li).html("<a href='#vmm' style='background-image: url(images/vbox/diskimage_16px.png);' >"+trans('Virtual Media Manager')+"</a>").appendTo(ul);					
				}
				
				// Add host drives
				self.menuAddDrives(ul);
				
				// Add remove drive
				var li = document.createElement('li');
				if(!self.removeEnabled) {
					$(li).addClass('disabled');
				}				
				$(li).html("<a href='#removeD' style='background-image: url(images/vbox/cd_unmount"+(self.removeEnabled ? '' : '_dis')+"_16px.png);' >"+trans('Remove disk from virtual drive')+"</a>").addClass('separator').addClass('vboxMediumRecentBefore').appendTo(ul);

				break;
			
			// Floppy defaults
			default:
				
				var li = document.createElement('li');
				$(li).html("<a href='#chooseD' style='background-image: url(images/vbox/select_file_16px.png);' >"+trans('Choose a virtual floppy disk file...')+"</a>").appendTo(ul);

				// Add VMM?
				if($('#vboxIndex').data('vboxConfig').enableAdvancedConfig) {
					var li = document.createElement('li');
					$(li).html("<a href='#vmm' style='background-image: url(images/vbox/diskimage_16px.png);' >"+trans('Virtual Media Manager')+"</a>").appendTo(ul);					
				}
				
				// Add host drives
				self.menuAddDrives(ul);

				// Add remove drive
				var li = document.createElement('li');
				if(!self.removeEnabled) {
					$(li).addClass('disabled');
				}
				$(li).html("<a href='#removeD' style='background-image: url(images/vbox/fd_unmount"+(self.removeEnabled ? '' : '_dis')+"_16px.png);' >"+trans('Remove disk from virtual drive')+"</a>").addClass('separator').addClass('vboxMediumRecentBefore').appendTo(ul);
				
				break;
				
		}
		
	}

	// Update "recent" media list
	this.menuUpdateRecent = function() {
		
		var elm = $('#'+self.menu_id());
		var list = $('#vboxIndex').data('vboxRecentMediums')[self.type];
		elm.children('li.vboxMediumRecent').remove();
		var ins = elm.children('li.vboxMediumRecentBefore').last();
		for(var i = 0; i < list.length; i++) {
			if(!list[i]) continue;
			if(!self.storage.getMediumByLocation(list[i])) continue;
			var li = document.createElement('li');
			$(li).attr({'class':'vboxMediumRecent'}).html("<a href='#path:"+list[i]+"'>"+vboxBasename(list[i])+"</a>").insertBefore(ins);
		}
	}
		
	// Update "remove image from disk" menu item
	self.menuUpdateRemoveMedia = function(enabled) {
		var menu = $('#'+self.menu_id());
		self.removeEnabled = enabled;
		if(enabled) {
			menu.enableContextMenuItems('#removeD');
			menu.find('a[href=#removeD]').css('background-image','url(images/vbox/'+(self.type == 'DVD' ? 'cd' : 'fd')+'_unmount_16px.png)');			
		} else {
			menu.disableContextMenuItems('#removeD');
			menu.find('a[href=#removeD]').css('background-image','url(images/vbox/'+(self.type == 'DVD' ? 'cd' : 'fd')+'_unmount_dis_16px.png)');			
		}
	}
	
	// Update recent media menu and global recent media list
	this.updateRecent = function(m) {
		
		// Only valid media that is not a host drive or iSCSI
		if(!m || !m.location || m.hostDrive || m.format == 'iSCSI') return;
		
		var changed = vboxAddRecentMedium(m.location, $('#vboxIndex').data('vboxRecentMediums')[self.type]);
		
		if(changed) {
			// Update menu
			self.menuUpdateRecent();
			// Update Recent Mediums in background
			vboxAjaxRequest('mediumRecentUpdate',{'type':m.deviceType,'list':$('#vboxIndex').data('vboxRecentMediums')[m.deviceType]},function(){});
		}
	}
	
	// Called when menu item is selected
	self.menuCallback = function(action,el,pos) {
		
		switch(action) {
		
			// Create hard disk
			case 'createD':
				vboxWizardNewHDInit(function(res,id){
					if(!id) return;
					var l = new vboxLoader();
					l.add('Mediums',function(d){$('#vboxIndex').data('vboxMediums',d);});
					l.onLoad = function() {
						var med = self.storage.getMediumById(id);
						self.callback(med);
						self.updateRecent(med);
					};
					l.run();
				},{'path':self.mediumPath+$('#vboxIndex').data('vboxConfig').DSEP}); 				
				break;
			
			// VMM
			case 'vmm':
				// vboxVMMDialogInit(callback,type,hideDiff,attached,vmPath)
				vboxVMMDialogInit(function(m){
					if(m && m.id) {
						var med = self.storage.getMediumById(m.id);
						self.callback(med);
						self.updateRecent(med);						
					}
				},self.type,false,{},self.mediumPath);
				break;
				
			// Choose medium file
			case 'chooseD':
				
				vboxFileBrowser(self.mediumPath,function(f){
					if(!f) return;
					var med = self.storage.getMediumByLocation(f);
					if(med && med.deviceType == self.type) {
						self.callback(med);
						self.updateRecent(med);
						return;
					} else if(med) {
						return;
					}
					var ml = new vboxLoader();
					ml.mode='save';
					ml.add('mediumAdd',function(ret){
						var l = new vboxLoader();
						if(ret && ret.id) {
							var med = self.storage.getMediumById(ret.id);
							// Not registered yet. Refresh mediums.
							if(!med)
								l.add('Mediums',function(data){$('#vboxIndex').data('vboxMediums',data);});
						}
						l.onLoad = function() {
							if(ret && ret.id) {
								var med = self.storage.getMediumById(ret.id);
								if(med && med.deviceType == self.type) {
									self.callback(med);
									self.updateRecent(med);
									return;
								}
							}
						}
						l.run();
					},{'path':f,'type':self.type});
					ml.run();
				});
				
				break;
				
			// Existing medium was selected
			default:
				if(action.indexOf('path:') == 0) {
					var path = action.substring(5);
					var med = self.storage.getMediumByLocation(path);
					if(med && med.deviceType == self.type) {
						self.callback(med);
						self.updateRecent(med);
					}
					return;
				}
				var med = self.storage.getMediumById(action);
				self.callback(med);
				self.updateRecent(med);
		}
	}
		
		
}



/*
 * Data Mediator Object
 * 
 * Queues data requests so that multiple requests for the
 * same data do not generate multiple server requests.
 * Safeguard against users who may pound on buttons and
 * slow server response.
 * 
 */
function vboxDataMediator() {
	
	this._data = {};
	this._inProgress = {};
	var self = this;
	
	this.get = function(type,id,callback) {
		
		
		// Data exists
		if(id == null && this._data[type]) {
			callback(this._data[type]);
			return;
		} else if(id != null && this._data[type] && this._data[type][id]) {
			callback(this._data[type][id]);
			return;
		}
		
		// Data does not exist. Request in progress?
		
		// UUID was not passed
		if(id == null) {
			// In progress. Add callback to list
			if(this._inProgress[type]) {
				this._inProgress[type][this._inProgress[type].length] = callback;
				this._inProgress[type] = $.unique(this._inProgress[type]);
			// Not in progress, create list && get data
			} else {
				this._inProgress[type] = [callback];
				vboxAjaxRequest('get' + type, {}, this._ajaxhandler,{'type':type});
			}
		// UUID was passed
		} else {
			// In progress. Add callback to list
			if(this._inProgress[type] && this._inProgress[type][id]) {
				this._inProgress[type][id][this._inProgress[type][id].length] = callback;
				this._inProgress[type][id] = $.unique(this._inProgress[type][id]);
			// Not in progress, create list && get data
			} else {
				if(!this._inProgress[type]) this._inProgress[type] = new Array();
				this._inProgress[type][id] = [callback];
				vboxAjaxRequest('get' + type, {'vm':id}, this._ajaxhandler,{'type':type,'id':id});
			}
		}
	}
	
	// Handle returned ajax data
	this._ajaxhandler = function(data, keys) {
		
		// First set data and release queued callbacks
		if(keys['id']) {
			if(!self._data[keys['type']]) self._data[keys['type']] = new Array();
			self._data[keys['type']][keys['id']] = data
			callbacks = self._inProgress[keys['type']][keys['id']];
			delete self._inProgress[keys['type']][keys['id']];
		} else {
			self._data[keys['type']] = data;
			callbacks = self._inProgress[keys['type']];
			delete self._inProgress[keys['type']];
		}
		
		for(var i = 0; i < callbacks.length; i++)
			self.get(keys['type'],keys['id'],callbacks[i])
		
		if(keys['id']) { delete self._data[keys['type']][keys['id']]; }
		else { delete self._data[keys['type']]; }
	}
}




/*
 * 
 * Top Menu Bar
 * 
 * 
 */
function vboxMenuBar(name) {
	
	var self = this;
	this.name = name;
	this.menus = new Array();
	this.menuClick = {};
	this.iconStringDisabled = '_dis';
	
	
	/* Add menu to object */
	self.addMenu = function(m) {
		
		self.menus[self.menus.length] = m;
		
		var ul = document.createElement('ul');
		$(ul).attr({'id':m.name+'Menu','class':'vboxMenuBar','style':'display: none;'});
		
		for(var i in m.menu) {
			if(typeof i == 'function') continue;
			// 16px icon?
			if(m.menu[i].icon_16) m.menu[i].icon = m.menu[i].icon_16;
			var li = document.createElement('li');
			var a = document.createElement('a');
			$(a).attr({'id':m.menu[i].name,'href':'#'+m.menu[i].name}).html(trans(m.menu[i].label));
			if(m.menu[i].icon_absolute) a.setAttribute('style','background-image: url('+m.menu[i].icon+')');
			else a.setAttribute('style','background-image: url(images/vbox/'+m.menu[i].icon+'_16px.png)');
			if(m.menu[i].separator) $(li).addClass('separator');
			li.appendChild(a)
			ul.appendChild(li);
			
			this.menuClick[m.menu[i].name] = m.menu[i].click;
		}
		
		$('#vboxIndex').append(ul);
	}

	/* add floating link or text */
	self.addFloat = function (f) {
		$('#'+self.name+'MenuBar').append($('<div />').attr({'class':'vboxFloatText'}).css({'float':'right'}).html(f));
	}
	
	/* Create and add menu bar */
	self.addMenuBar = function(id) {
		
		var d = document.createElement('div');
		$(d).attr({'class':'vboxMenuBar','id':self.name+'MenuBar'});
		$('#'+id).prepend(d);
		
		for(var i = 0; i < self.menus.length; i++) {
			$('#'+self.name+'MenuBar').append('<span id="'+self.menus[i].name+'">'+trans(self.menus[i].label)+'</span>');	
			$('#'+self.menus[i].name).contextMenu({
			 		menu: self.menus[i].name+'Menu',
			 		button: 0,
			 		mode: 'menu'
				},
				self.click
			).hover(
				function(){$(this).addClass('vboxBordered');},
				function(){$(this).removeClass('vboxBordered');}
			);
		}
		self.update();
		
	}
	
	
	/* Update Menu items */
	self.update = function(e,item) {
		
		for(var i = 0; i < self.menus.length; i++) {
			for(var a = 0; a < self.menus[i].menu.length; a++) {
				var icon = self.menus[i].menu[a].icon;
				if(self.menus[i].menu[a].enabled && !self.menus[i].menu[a].enabled(item)) {
					if(self.menus[i].menu[a].icon_disabled) icon = self.menus[i].menu[a].icon_disabled;
					else icon += self.iconStringDisabled;
					$('#'+self.menus[i].menu[a].name).parent().addClass('disabled');
				} else {
					$('#'+self.menus[i].menu[a].name).parent().removeClass('disabled');
				}
				if(self.menus[i].menu[a].enabled)
					$('#'+self.menus[i].menu[a].name).css({'background-image':'url(images/vbox/'+icon+'_16px.png)'});
			}
		}
		
	}
	
	/* Pass click on to menu item */
	self.click = function(fn) { self.menuClick[fn]();}
	
}

/*
 * 
 * Displays "Loading ..." screen until all data items
 * have completed loading
 * 
 */
function vboxLoader() {

	var self = this;
	this._load = [];
	this.onLoad = null;
	this._loadStarted = {};
	this.hideRoot = false;
	this.noLoadingScreen = false;
	this.mode = 'get';

	/* Add item to list of items to load */
	this.add = function(dataType, callback, params) {
		if (params === undefined) params = {};
		this._load[this._load.length] = {
			'dataType' : dataType,
			'type' : 'data',
			'callback' : callback,
			'params' : params
		};
	}

	/* Add file to list of items to load */
	this.addFile = function(file,callback,params) {
		if (params === undefined) params = {};		
		this._load[this._load.length] = {
				'type' : 'file',
				'callback' : callback,
				'file' : file,
				'params' : params
			};		
	}
	
	/* Add a script to the list of items to load */
	this.addScript = function(file,callback,params) {
		if (params === undefined) params = {};		
		this._load[this._load.length] = {
				'type' : 'script',
				'callback' : callback,
				'file' : file,
				'params' : params
			};		
	}
	
	
	/* Load data and present "Loading..." screen */
	this.run = function() {

		this._loadStarted = {'data':false,'files':false,'scripts':false};
		
		if(!self.noLoadingScreen) {

			var div = document.createElement('div');
			$(div).attr({'id':'vboxLoaderDialog','title':'','style':'display: none;','class':'vboxDialogContent'});
	
			var tbl = document.createElement('table');
			var tr = document.createElement('tr');
			var td = document.createElement('td');
			$(td).attr('class', 'vboxLoaderSpinner').html('<img src="images/spinner.gif" />').appendTo(tr);
			
			var td = document.createElement('td');
			$(td).attr('class','vboxLoaderText').html(trans('Loading ...')).appendTo(tr);

			tbl.appendChild(tr);
			div.appendChild(tbl);
	
			/* Display loading screen and hide body */
			$('#vboxIndex').append(div);
			
			if(self.hideRoot)
				$('#vboxIndex').css('display', 'none');

			$('#vboxLoaderDialog').dialog({
				'dialogClass' : 'vboxLoaderDialog',
				'width' : 'auto',
				'height' : 60,
				'modal' : true,
				'resizable' : false,
				'draggable' : false,
				'closeOnEscape' : false,
				'buttons' : {}
			});
		}
		
		this._loadOrdered();
	}
	
	/* Load items in order */
	this._loadOrdered = function(t) {
		
		var dataLeft = 0;
		var scriptsLeft = 0;
		var filesLeft = 0;

		for ( var i = 0; i < self._load.length; i++) {
			if(!self._load[i]) continue;
			if(self._load[i].type == 'data') {
				dataLeft = 1;
			} else if(self._load[i].type == 'script') {
				scriptsLeft = 1;
			} else if(self._load[i].type == 'file') {
				filesLeft = 1;
			}
		}
		
		// Everything loaded? Stop
		if(dataLeft + scriptsLeft + filesLeft == 0) { self._stop();	return; }
		
		// Data left to load
		if(dataLeft) {
			if(self._loadStarted['data']) return;
			self._loadStarted['data'] = true;
			self._loadData();
			return;
		}
		
		// Scripts left to load
		if(scriptsLeft) {
			if(self._loadStarted['scripts']) return;
			self._loadStarted['scripts'] = true;
			self._loadScripts();
			return;
		}

		// files left to load
		if(self._loadStarted['files']) return;
		self._loadStarted['files'] = true;
		self._loadFiles();
		
		
	}
	

	/* Load all data requests */
	this._loadData = function() {
		for ( var i = 0; i < self._load.length; i++) {
			if(self._load[i] && self._load[i].type == 'data') {
				vboxAjaxRequest((self.mode == 'get' ? 'get' : '') + self._load[i].dataType,self._load[i].params,self._ajaxhandler,{'id':i});
			}
		}
	}

	/* Load all script requests */
	this._loadScripts = function() {
		for ( var i = 0; i < self._load.length; i++) {
			if(self._load[i] && self._load[i].type == 'script') {
				vboxGetScript(self._load[i].file,self._ajaxhandler,{'id':i});
			}
		}
	}

	/* Load all file requests */
	this._loadFiles = function() {
		for ( var i = 0; i < self._load.length; i++) {
			if(self._load[i] && self._load[i].type == 'file') {
				vboxGetFile(self._load[i].file,self._ajaxhandler,{'id':i});
			}
		}
	}
	
	/* Call appropriate callback and check for completion */
	this._ajaxhandler = function(d, i) {
		if(self._load[i.id].callback) self._load[i.id].callback(d,self._load[i.id].params);
		self._load[i.id].loaded = true;
		delete self._load[i.id]
		self._loadOrdered();
	}

	
	/* Removes loading screen and show body */
	this._stop = function() {

		if(self.onLoad) self.onLoad();

		if(!self.noLoadingScreen) $('#vboxLoaderDialog').empty().remove();
		
		if(self.hideRoot) $('#vboxIndex').css('display', '');
		
		if(self.onShow) self.onShow();
	}

}

/*
 * Common serial port options
 */
function vboxSerialPorts() {
	
	this.ports = [
      { 'name':"COM1", 'irq':4, 'port':'0x3F8' },
      { 'name':"COM2", 'irq':3, 'port':'0x2F8' },
      { 'name':"COM3", 'irq':4, 'port':'0x3E8' },
      { 'name':"COM4", 'irq':3, 'port':'0x2E8' },
	];
	
	this.getPortName = function(irq,port) {
		for(var i = 0; i < this.ports.length; i++) {
			if(this.ports[i].irq == irq && this.ports[i].port.toUpperCase() == port.toUpperCase())
				return this.ports[i].name;
		}
		return 'User-defined';
	}
	
}

/*
 * Common LPT port options
 */
function vboxParallelPorts() {
	
	this.ports = [
      { 'name':"LPT1", 'irq':7, 'port':'0x3BC' },
      { 'name':"LPT2", 'irq':5, 'port':'0x378' },
      { 'name':"LPT3", 'irq':5, 'port':'0x278' }
	];
	
	this.getPortName = function(irq,port) {
		for(var i = 0; i < this.ports.length; i++) {
			if(this.ports[i].irq == irq && this.ports[i].port.toUpperCase() == port.toUpperCase())
				return this.ports[i].name;
		}
		return 'User-defined';
	}
	
}

/*
 * 	Common storage / controller functions
 */
function vboxStorage() {

	// Returns printable medium name
	this.mediumPrint = function(m,nosize) {
		name = this.getMediumName(m);
		if(nosize || !m || m.hostDrive) return name;
		return name + ' (' + (m.deviceType == 'HardDisk' ? trans(m.type) + trans('LIST_SEP') : '') + vboxMbytesConvert(m.logicalSize) + ')';
	}
	
	this.getMediumName = function(m) {
		if(!m) return trans('Empty');
		if(m.hostDrive) return trans('Host Drive')+(m.description ? " " + m.description + " ("+vboxBasename(m.location)+")" : " '"+m.location+"'");
		return m.name;
	}

	// Return list of bus types
	this.getBusTypes = function() {
		var busts = [];
		for(var i in this) {
			if(typeof i == 'function') continue;
			if(!this[i].maxPortCount) continue;
			busts[busts.length] = i;
		}
		return busts;
	}
	
	/* Return mediums and drives available for attachment type */
	this.mediumsForAttachmentType = function(t,children) {

		var mediums = new Array();
		
		// DVD Drives
		if(t == 'DVD') {
			mediums = mediums.concat($('#vboxIndex').data('vboxHostDetails').DVDDrives);

		// Floppy Drives
		} else if(t == 'Floppy') {
			mediums = mediums.concat($('#vboxIndex').data('vboxHostDetails').floppyDrives);
		}
		
		
		// media
		return mediums.concat(this.__getLeaf($('#vboxIndex').data('vboxMediums'),'deviceType',t,true,children));
	}

	this.__getLeaf = function(tree,prop,val,all,children) {
		var leafs = new Array();
		for(var a in tree) {
			if(tree[a][prop] == val) {
				if(!all) return tree[a];
				leafs[leafs.length] = tree[a];
			}
			if(children && tree[a].children && tree[a].children.length) {
				var c = this.__getLeaf(tree[a].children,prop,val,all,children);
				if(!all && c) { return c; }
				else if(c && c.length) {
					leafs = leafs.concat(c);
				}
			}
		}
		return (all ? leafs : null);
	}
	

	/* Return a medium by location */
	this.getMediumByLocation = function(p) {		
		return this.__getLeaf($('#vboxIndex').data('vboxMediums'),'location',p,false,true);
	}

	/* Return a medium by ID */
	this.getMediumById = function(id) {		
		return this.__getLeaf($('#vboxIndex').data('vboxMediums').concat($('#vboxIndex').data('vboxHostDetails').DVDDrives.concat($('#vboxIndex').data('vboxHostDetails').floppyDrives)),'id',id,false,true);
	}
	
	this.IDE = {
		'maxPortCount' : 2,
		'maxDevicesPerPortCount' : 2,
		'types':['PIIX3','PIIX4','ICH6' ],
		'slotName' : function(p,d) {
			return trans((p ? 'Secondary' : 'Primary') +' '+(d ? 'Slave' : 'Master'));
		},
		'driveTypes' : ['dvd','disk'],
		'slots' : function() { return {
		          	'0-0' : (trans('Primary Master')),
		          	'0-1' : (trans('Primary Slave')),
		          	'1-0' : (trans('Secondary Master')),
		          	'1-1' : (trans('Secondary Slave'))
			}}
	};
		
	this.SATA = {
		'maxPortCount' : 30,
		'maxDevicesPerPortCount' : 1,
		'types' : ['IntelAhci'],
		'driveTypes' : ['dvd','disk'],
		'slotName' : function(p,d) { return trans('SATA Port %s').replace('%s',p); },
		'slots' : function() {
					var s = {};
					for(var i = 0; i < 30; i++) {
						s[i+'-0'] = trans('SATA Port %s').replace('%s',i);
					}
					return s;
				}
	};
		
	this.SCSI = {
		'maxPortCount' : 16,
		'maxDevicesPerPortCount' : 1,
		'driveTypes' : ['disk'],
		'types' : ['LsiLogic','BusLogic'],
		'slotName' : function(p,d) { return trans('SCSI Port %s').replace('%s',p); },
		'slots' : function() {
						var s = {};
						for(var i = 0; i < 16; i++) {
							s[i+'-0'] = trans('SCSI Port %s').replace('%s',i);
						}
						return s;				
					}
	};
		
	this.Floppy = {
		'maxPortCount' : 1,
		'maxDevicesPerPortCount' : 2,
		'types' : ['I82078'],
		'driveTypes' : ['floppy'],
		'slotName' : function(p,d) { return trans('Floppy Device %s').replace('%s',d); },
		'slots' : function() { return { '0-0':trans('Floppy Device %s').replace('%s','0'), '0-1':trans('Floppy Device %s').replace('%s','1') }; }
	};

	
	this.SAS = {
			'maxPortCount' : 8,
			'maxDevicesPerPortCount' : 1,
			'types' : ['LsiLogicSas'],
			'driveTypes' : ['disk'],
			'slotName' : function(p,d) { return trans('SAS Port %s').replace('%s',p); },
			'slots' : function() {
							var s = {};
							for(var i = 0; i < 8; i++) {
								s[i+'-0'] = trans('SAS Port %s').replace('%s',i);
							}
							return s;				
						},
			'displayInherit' : 'SATA'
		};
	
	

}
