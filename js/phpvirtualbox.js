/*

 * 	Common classes used
 * 
 * $Id$
 * Copyright (C) 2011 Ian Moore (imoore76 at yahoo dot com)
 * 
 */

/*
 * Wizard (new HardDisk or VM)
 */
function vboxWizard(name, title, img) {
	
	var self = this;
	this.steps = 0;
	this.name = name;
	this.title = title;
	this.img = img;
	this.finish = null;
	this.width = 700;
	this.height = 450;
	
	// Initialize / display dialog
	this.run = function() {

		var d = document.createElement('div');
		d.setAttribute('id',this.name+'Dialog');
		d.setAttribute('style','display: none');
		d.setAttribute('class','vboxWizard');
		
		var f = document.createElement('form');
		f.setAttribute('name','frm'+this.name);
		f.setAttribute('onSubmit','return false;');

		// main table
		var tbl = document.createElement('table');
		tbl.setAttribute('class','vboxWizard');
		var tr = document.createElement('tr');
		
		if(this.img) {
			var td = document.createElement('td');
			td.setAttribute('class','vboxWizardImg');
			td.innerHTML = '<img src="' + self.img + '" />';
			tr.appendChild(td);
		}
		
		var td = document.createElement('td');
		td.setAttribute('id',self.name+'Content');
		td.setAttribute('class','vboxWizardContent');
		tr.appendChild(td);
		tbl.appendChild(tr);
		
		// Title
		var t = document.createElement('h3');
		t.setAttribute('id',self.name+'Title');
		t.innerHTML = self.title;
		d.appendChild(t);
		
		f.appendChild(tbl);
		d.appendChild(f);
		
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
			
			$(d).dialog({'closeOnEscape':false,'width':self.width,'height':self.height,'buttons':buttons,'modal':true,'autoOpen':true,'stack':true,'dialogClass':'vboxDialogContent','title':self.title});

			self.displayStep(1);
		};
		l.run();
				
	}
	
	self.close = function() {
		$('#'+self.name+'Dialog').remove();
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
		$('#vboxToolbarButton-'+self.id+'-'+b.name).children('img').attr('src','images/vbox/'+b.icon+'_'+self.size+'px.png');
		$('#vboxToolbarButton-'+self.id+'-'+b.name).addClass('buttonEnabled').removeClass('buttonDisabled');
	}

	self.disableButton = function(b) {
		$('#vboxToolbarButton-'+self.id+'-'+b.name).children('img').attr('src','images/vbox/'+b.icon+'_disabled_'+self.size+'px.png');
		$('#vboxToolbarButton-'+self.id+'-'+b.name).addClass('buttonDisabled').removeClass('buttonEnabled');
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
		td.setAttribute('id','vboxToolbarButton-' + self.id + '-' + b.name);
		td.setAttribute('class','vboxToolbarButton buttonEnabled vboxToolbarButton'+self.size);
		td.setAttribute('style',self.buttonStyle+'; min-width: '+(self.size+12)+'px;');
		td.innerHTML = '<img src="images/vbox/'+b.icon+'_'+self.size+'px.png" /><br />' + $('<div />').html(trans(b.label)).text();
		
		// bind click
		td.onclick = function(){
			if($(this).hasClass('buttonDisabled')) return;
			$(this).data('toolbar').click($(this).data('name'));
		}
		
		// store data
		$(td).data(b);
		
		if(!self.noHover) {
			$(td).hover(
					function(){if($(this).hasClass('buttonEnabled')){$(this).addClass('vboxToolbarButtonHover');}},
					function(){$(this).removeClass('vboxToolbarButtonHover');}		
			);
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
				td.setAttribute('class','vboxToolbarSeparator');
				td.innerHTML = '<br />';
				tr.appendChild(td);
			}

		}

		tbl.appendChild(tr);
		$('#'+id).append(tbl);
		
		$('#'+id).addClass('vboxToolbar vboxToolbar'+this.size);
		
		$('#'+id).bind('disable',self.disable);
		$('#'+id).bind('enable',self.enable);
		
		
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
		$('#vboxToolbarButton-' + self.id + '-' + b.name).css('background-image','url(images/vbox/' + b.icon + '_'+self.size+'px.png)').attr('disabled','');
	}
	self.disableButton = function(b) {
		$('#vboxToolbarButton-' + self.id + '-' + b.name).css('background-image','url(images/vbox/' + b.icon + '_'+self.disabledString+'_'+self.size+'px.png)').attr('disabled','disabled').removeClass('vboxToolbarSmallButtonHover').addClass('vboxToolbarSmallButton');
		$.fn.tipped.hideTip($('#vboxToolbarButton-' + self.id + '-' + b.name));
	}

	// Generate HTML element for button
	self.buttonElement = function(b) {

		// Pre-load disabled version of icon if enabled function exists
		if(b.enabled) {
			var a = new Image();
			a.src = "images/vbox/" + b.icon + "_" + self.disabledString + "_" + self.size + "px.png";
		}
		
		var btn = document.createElement('input');
		btn.setAttribute('id','vboxToolbarButton-' + self.id + '-' + b.name);
		btn.setAttribute('type','button');
		btn.setAttribute('value','');
		btn.setAttribute('class','vboxImgButton vboxToolbarSmallButton');
		btn.setAttribute('title', trans(b.label));
		$(btn).click(b.click);
		btn.setAttribute('style',self.buttonStyle+' background-image: url(images/vbox/' + b.icon + '_'+self.size+'px.png);');
		
		
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
		
		if(!self.buttonStyle)
			self.buttonStyle = 'height: ' + (self.size+4) + 'px; width: ' + (self.size+4) + 'px; ';
		
		for(var i = 0; i < self.buttons.length; i++) {
			
			$('#'+id).append(self.buttonElement(self.buttons[i]));
			
			if(self.buttons[i].separator) {
				var hr = document.createElement('hr');
				hr.setAttribute('style','display: inline');
				hr.setAttribute('class','vboxToolbarSmall vboxSeperatorLine');
				$('#'+id).append(hr);
			}
				
		}
		
		$('#'+id).attr('name',self.name);
		$('#'+id).addClass('vboxToolbarSmall vboxEnablerTrigger');
		
		$('#'+id).bind('disable',self.disable);
		$('#'+id).bind('enable',self.enable);
		
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
		ul.setAttribute('id',m.name+'Menu');
		ul.setAttribute('class','vboxMenuBar');
		ul.setAttribute('style','display: none;');
		
		for(var i in m.menu) {
			if(typeof i == 'function') continue;
			var li = document.createElement('li');
			var a = document.createElement('a');
			a.setAttribute('id',m.menu[i].name);
			a.setAttribute('href','#'+m.menu[i].name);
			if(m.menu[i].icon_absolute) a.setAttribute('style','background-image: url('+m.menu[i].icon+')');
			else a.setAttribute('style','background-image: url(images/vbox/'+m.menu[i].icon+'_16px.png)');
			a.innerHTML = trans(m.menu[i].title);
			if(m.menu[i].separator) $(li).addClass('separator');
			li.appendChild(a)
			ul.appendChild(li);
			
			this.menuClick[m.menu[i].name] = m.menu[i].click;
		}
		
		$('#vboxIndex').append(ul);
	}

	
	/* Create and add menu bar */
	self.addMenuBar = function(id) {
		
		var d = document.createElement('div');
		d.setAttribute('class','vboxMenuBar');
		d.setAttribute('id',self.name+'MenuBar');
		$('#'+id).prepend(d);
		
		for(var i = 0; i < self.menus.length; i++) {
			$('#'+self.name+'MenuBar').append('<span id="'+self.menus[i].name+'">'+trans(self.menus[i].title)+'</span>');	
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
			div.setAttribute('id', 'vboxLoaderDialog');
			div.setAttribute('title', '');
			div.setAttribute('style','display: none;');
			div.setAttribute('class','vboxDialogContent');
	
			var tbl = document.createElement('table');
			var tr = document.createElement('tr');
			var td = document.createElement('td');
			td.setAttribute('class', 'vboxLoaderSpinner');
			td.innerHTML = '<img src="images/spinner.gif" />';
			tr.appendChild(td);
			var td = document.createElement('td');
			td.setAttribute('class','vboxLoaderText');
			td.innerHTML = trans('Loading ...');
			tr.appendChild(td);
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

		if (self.onLoad) self.onLoad();

		if(!self.noLoadingScreen) $('#vboxLoaderDialog').remove();
		
		if(self.hideRoot) $('#vboxIndex').css('display', '');
		
		if(self.onShow) self.onShow();
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
		if(m.hostDrive) return trans('Host Drive')+" '"+m.location+"'";
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
		'slotName' : function(p,d) { return trans('SATA Port') + ' ' + p; },
		'slots' : function() {
					var s = {};
					for(var i = 0; i < 30; i++) {
						s[i+'-0'] = trans('SATA Port') + ' ' + i;
					}
					return s;
				}
	};
		
	this.SCSI = {
		'maxPortCount' : 16,
		'maxDevicesPerPortCount' : 1,
		'driveTypes' : ['disk'],
		'types' : ['LsiLogic','BusLogic'],
		'slotName' : function(p,d) { return trans('SCSI Port') + ' ' + p; },
		'slots' : function() {
						var s = {};
						for(var i = 0; i < 16; i++) {
							s[i+'-0'] = trans('SCSI Port') + ' ' + i;
						}
						return s;				
					}
	};
		
	this.Floppy = {
		'maxPortCount' : 1,
		'maxDevicesPerPortCount' : 2,
		'types' : ['I82078'],
		'driveTypes' : ['floppy'],
		'slotName' : function(p,d) { return trans('Floppy Device')+' '+d; },
		'slots' : function() { return { '0-0':trans('Floppy Device')+' 0', '0-1':trans('Floppy Device')+' 1' }; }
	};

	
	this.SAS = {
			'maxPortCount' : 8,
			'maxDevicesPerPortCount' : 1,
			'types' : ['LsiLogicSas'],
			'driveTypes' : ['disk'],
			'slotName' : function(p,d) { return trans('SAS Port') + ' ' + p; },
			'slots' : function() {
							var s = {};
							for(var i = 0; i < 8; i++) {
								s[i+'-0'] = trans('SAS Port') + ' ' + i;
							}
							return s;				
						},
			'displayInherit' : 'SATA'
		};
	
	

}
