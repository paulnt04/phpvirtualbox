/*
 * $Id$
 * Copyright (C) 2011 Ian Moore (imoore76 at yahoo dot com)
 */

/*
 * 
 * Import appliance wizard
 * 
 */
function vboxImportApplianceDialogInit() {

	var l = new vboxLoader();
	l.add('EnumNetworkAdapterType',function(d){$('#vboxIndex').data('vboxNetworkAdapterTypes',d);});
	l.add('EnumAudioControllerType',function(d){$('#vboxIndex').data('vboxAudioControllerTypes',d);});	
	l.onLoad = function() {

		var vbw = new vboxWizard('wizardImportAppliance',trans('Appliance Import Wizard'),'images/vbox/vmw_ovf_import.png', 'images/vbox/vmw_ovf_import_bg.png','import');
		vbw.steps = 2;
		vbw.onFinish = function(wiz,dialog) {
		
			var file = $(document.forms['frmwizardImportAppliance'].elements.wizardImportApplianceLocation).val();
			var descriptions = $('#vboxImportProps').data('descriptions');
			
			// Step through each VM and obtain value
			for(var a = 0; a < descriptions.length; a++) {
				var children = $('#vboxImportProps').children('tr.vboxChildOf'+a);
				descriptions[a][5] = []; // enabled / disabled
				for(var b = 0; b < children.length; b++) {
					descriptions[a][5][b] = !$(children[b]).data('propdisabled');
					descriptions[a][3][$(children[b]).data('descOrder')] = $(children[b]).children('td:eq(1)').data('descValue');
				}
			}
			
			var l = new vboxLoader();
			l.mode = 'save';
			l.add('applianceImport',function(d){
				if(d && d.progress) {
					vboxProgress(d.progress,function(){
						$('#vboxIndex').trigger('vmlistreload');
						// Imported mediums must be refreshed
						var ml = new vboxLoader();
						ml.add('Mediums',function(d){$('#vboxIndex').data('vboxMediums',d);});
						ml.run();
					},{},'progress_import_90px.png',trans('Import Appliance'));
				}
			},{'descriptions':descriptions,'file':file});
			$(dialog).trigger('close').empty().remove();
			l.run();
	
	
		};
		vbw.run();

	};
	l.run();
}

/*
 * 
 * Export appliance wizard
 * 
 * 
 */
function vboxExportApplianceDialogInit() {

	var vbw = new vboxWizard('wizardExportAppliance',trans('Appliance Export Wizard'),'images/vbox/vmw_ovf_export.png','images/vbox/vmw_ovf_export_bg.png','export');
	vbw.steps = 3;
	vbw.height = 500;
	vbw.onFinish = function(wiz,dialog) {

		// Each VM
		var vmid = null;
		var vms = {};
		var vmsAndProps = $('#vboxExportProps').children('tr');
		for(var a = 0; a < vmsAndProps.length; a++) {
			if($(vmsAndProps[a]).hasClass('vboxTableParent')) {
				vmid = $(vmsAndProps[a]).data('vm').id
				vms[vmid] = {};
				vms[vmid]['id'] = vmid;
				continue;
			}
			
			var prop = $(vmsAndProps[a]).data('vmprop');
			vms[vmid][prop] = $(vmsAndProps[a]).children('td:eq(1)').children().first().text();
				
		}

		var file = $(document.forms['frmwizardExportAppliance'].elements.wizardExportApplianceLocation).val();
		var format = (document.forms['frmwizardExportAppliance'].elements.wizardExportApplianceLegacy.checked ? 'ovf-0.9' : '');
		
		var l = new vboxLoader();
		l.mode = 'save';
		l.add('applianceExport',function(d){
			if(d && d.progress)
				vboxProgress(d.progress,function(){return;},{},'progress_export_90px.png',trans('Export Appliance'));
		},{'format':format,'file':file,'vms':vms});
		$(dialog).trigger('close').empty().remove();
		l.run();


	};
	vbw.run();

}

/*
 * 
 * Port forwarding configuration dialog
 * 
 */
function vboxPortForwardConfigInit(rules,callback) {
	var l = new vboxLoader();
	l.addFile("panes/settingsPortForwarding.html",function(f){$('#vboxIndex').append(f);});
	l.onLoad = function(){
		vboxSettingsPortForwardingInit(rules);
		var buttons = {};
		buttons[trans('OK')] = function(){
			// Get rules
			var rules = $('#vboxSettingsPortForwardingList').children('tr');
			for(var i = 0; i < rules.length; i++) {
				if($(rules[i]).data('vboxRule')[3] == 0 || $(rules[i]).data('vboxRule')[5] == 0) {
					vboxAlert(trans('The current port forwarding rules are not valid'));
					return;
				}
				rules[i] = $(rules[i]).data('vboxRule');
			}
			callback(rules);
			$(this).trigger('close').empty().remove();
		};
		buttons[trans('Cancel')] = function(){$(this).trigger('close').empty().remove();};
		$('#vboxSettingsPortForwarding').dialog({'closeOnEscape':false,'width':600,'height':400,'buttons':buttons,'modal':true,'autoOpen':true,'stack':true,'dialogClass':'vboxDialogContent','title':'<img src="images/vbox/nw_16px.png" class="vboxDialogTitleIcon" /> ' + trans('Port Forwarding Rules')});
	}
	l.run();
}

/*
 * 
 * 
 * New Virtual Machine Wizard
 * 
 * 
 * 
 */
function vboxWizardNewVMInit(callback) {

	var l = new vboxLoader();
	l.add('Mediums',function(d){$('#vboxIndex').data('vboxMediums',d);});
	
	l.onLoad = function() {
		var vbw = new vboxWizard('wizardNewVM',trans('Create New Virtual Machine'),'images/vbox/vmw_new_welcome.png','images/vbox/vmw_new_welcome_bg.png','new');
		vbw.steps = 5;
		vbw.onFinish = function(wiz,dialog) {

			// Get parameters
			var disk = document.forms['frmwizardNewVM'].newVMDiskSelect.options[document.forms['frmwizardNewVM'].newVMDiskSelect.selectedIndex].value;
			var name = jQuery.trim(document.forms['frmwizardNewVM'].newVMName.value);
			var ostype = document.forms['frmwizardNewVM'].newVMOSType.options[document.forms['frmwizardNewVM'].newVMOSType.selectedIndex].value;
			var mem = parseInt(document.forms['frmwizardNewVM'].wizardNewVMSizeValue.value);
			if(!document.forms['frmwizardNewVM'].newVMBootDisk.checked) disk = null;

			vboxAjaxRequest('createVM',{'disk':disk,'ostype':ostype,'memory':mem,'name':name},function(){
				var lm = new vboxLoader();
				lm.add('Mediums',function(d){$('#vboxIndex').data('vboxMediums',d);});
				lm.onLoad = function(){
					$('#vboxIndex').trigger('vmlistreload');
					if(callback) callback();
				}
				lm.run();
			});
			
			$(dialog).trigger('close').empty().remove();

		};
		vbw.run();
	}
	l.run();
	
}

/*
 * 
 * 
 * Show vm logs
 * 
 * 
 */
function vboxShowLogsDialogInit(vm) {

	var d = document.createElement('div');
	d.setAttribute('id','vboxVMLogsDialog');
	$('#vboxIndex').append(d);
	
	var l = new vboxLoader();
	l.add('VMLogFileNames',function(r){$('#vboxVMLogsDialog').data('logs',r);},{'vm':vm});
	l.addFile('panes/vmlogs.html',function(f){$('#vboxVMLogsDialog').append(f);});
	l.onLoad = function(){
		var buttons = {};
		vboxSetLangContext('vboxVMLogs');
		buttons[trans('Refresh')] = function() {
			l = new vboxLoader();
			l.add('VMLogFileNames',function(r){$('#vboxVMLogsDialog').data('logs',r);},{'vm':vm});
			l.onLoad = function(){
				vboxShowLogsInit(vm);
			}
			l.run();
		};
		buttons[trans('Close')] = function(){$(this).trigger('close').empty().remove();};
		vboxUnsetLangContext();
		$('#vboxVMLogsDialog').dialog({'closeOnEscape':false,'width':800,'height':500,'buttons':buttons,'modal':true,'autoOpen':true,'stack':true,'dialogClass':'vboxDialogContent','title':'<img src="images/vbox/show_logs_16px.png" class="vboxDialogTitleIcon" /> '+trans('Logs')});
		vboxShowLogsInit(vm);
	};
	l.run();

}

/*
 * 
 * 	Virtual Media Manager Dialog
 * 
 * 
 */

function vboxVMMDialogInit(callback,type,hideDiff,attached,vmPath) {

	var d = document.createElement('div');
	d.setAttribute('id','vboxVMMDialog');
	$('#vboxIndex').append(d);
			
	var l = new vboxLoader();
	l.add('Config',function(d){$('#vboxIndex').data('vboxConfig',d);});
	l.add('SystemProperties',function(d){$('#vboxIndex').data('vboxSystemProperties',d);});
	l.add('Mediums',function(d){$('#vboxIndex').data('vboxMediums',d);});
	l.addFile('panes/vmm.html',function(f){$('#vboxVMMDialog').append(f);});
	l.onLoad = function() {
		var buttons = {};
		if(callback) {
			buttons[trans('OK')] = function() {
				var sel = null;
				switch($("#vboxVMMTabs").tabs('option','selected')) {
					case 0: /* HardDisks */
						sel = $('#vboxVMMHDList').find('tr.vboxListItemSelected').first();
						break;
					case 1: /* DVD */
						sel = $('#vboxVMMCDList').find('tr.vboxListItemSelected').first();
						break;
					default:
						sel = $('#vboxVMMFDList').find('tr.vboxListItemSelected').first();
				}
				if(!$(sel).html()) {
					vboxSetLangContext('vboxVMM');
					vboxAlert(trans('Please select a medium.'),{'width':'auto','height':'auto'});
					vboxUnsetLangContext();
					return;
				}
				callback($(sel).data());
				$('#vboxVMMDialog').trigger('close').empty().remove();
			}
		}
		buttons[trans('Close')] = function() {
			$('#vboxVMMDialog').trigger('close').empty().remove();
			if(callback) callback(null);
		};
		$("#vboxVMMDialog").dialog({'closeOnEscape':false,'width':800,'height':500,'buttons':buttons,'modal':true,'autoOpen':true,'stack':true,'dialogClass':'vboxDialogContent','title':'<img src="images/vbox/diskimage_16px.png" class="vboxDialogTitleIcon" /> '+trans('Virtual Media Manager')});
		vboxVMMInit(hideDiff,attached,vmPath);
		if(type) {
			switch(type) {
				case 'HardDisk':
					$("#vboxVMMTabs").tabs('select',0);
					$("#vboxVMMTabs").tabs('disable',1);
					$("#vboxVMMTabs").tabs('disable',2);					
					break;
				case 'DVD':
					$("#vboxVMMTabs").tabs('select',1);
					$("#vboxVMMTabs").tabs('disable',0);
					$("#vboxVMMTabs").tabs('disable',2);					
					break;
				case 'Floppy':
					$("#vboxVMMTabs").tabs('select',2);
					$("#vboxVMMTabs").tabs('disable',0);
					$("#vboxVMMTabs").tabs('disable',1);
					break; 
			}
		}
	}
	l.run();
}

/*
 * 
 * 	New Virtual Disk wizard dialog
 * 
 * 
 */
function vboxWizardNewHDInit(callback,suggested) {

	var l = new vboxLoader();
	l.add('SystemProperties',function(d){$('#vboxIndex').data('vboxSystemProperties',d);});
	l.add('Mediums',function(d){$('#vboxIndex').data('vboxMediums',d);});
	
	// Compose folder if suggested name exists
	if(suggested && suggested.name) {
		l.add('ComposedMachineFilename',function(d){suggested.path = vboxDirname(d.file)+$('#vboxIndex').data('vboxConfig').DSEP},{'name':suggested.name})
	}
	l.onLoad = function() {
		var vbw = new vboxWizard('wizardNewHD',trans('Create New Virtual Disk'),'images/vbox/vmw_new_harddisk.png','images/vbox/vmw_new_harddisk_bg.png','hd');
		vbw.steps = 4;
		vbw.suggested = suggested;
		vbw.onFinish = function(wiz,dialog) {

			var file = document.forms['frmwizardNewHD'].elements.wizardNewHDLocation.value;
			var size = vboxConvertMbytes(document.forms['frmwizardNewHD'].elements.wizardNewHDSizeValue.value);
			var type = (document.forms['frmwizardNewHD'].elements.newHardDiskType[1].checked ? 'fixed' : 'dynamic');

			$(dialog).trigger('close').empty().remove();

			var l = new vboxLoader();
			l.mode = 'save';
			l.add('mediumCreateBaseStorage',function(d,e){
				if(d && d.progress) {
					vboxProgress(d.progress,callback,d.id,'progress_media_create_90px.png',trans('Create New Virtual Disk'));
				} else {
					callback({},d.id);
				}
			},{'file':file,'type':type,'size':size});
			l.run();
			
		};
		vbw.run();
	}
	l.run();
	
}

/*
 * 
 * Initialize guest network dialog
 * 
 */
function vboxGuestNetworkAdaptersDialogInit(vm,nic) {

	/*
	 * 	Dialog
	 */
	var d = document.createElement('div');
	d.setAttribute('id','vboxGuestNetworkDialog');
	d.setAttribute('style','display: none');
	$('#vboxIndex').append(d);

	/*
	 * Loader
	 */
	var l = new vboxLoader();
	l.addFile('panes/guestNetAdapters.html',function(f){$('#vboxGuestNetworkDialog').append(f);})
	l.onLoad = function(){
		
		var buttons = {};
		buttons[trans('Close')] = function() {$('#vboxGuestNetworkDialog').trigger('close').empty().remove();};
		$(d).dialog({'closeOnEscape':false,'width':500,'height':250,'buttons':buttons,'modal':true,'autoOpen':true,'stack':true,'dialogClass':'vboxDialogContent','title':'<img src="images/vbox/nw_16px.png" class="vboxDialogTitleIcon" /> ' + trans('Guest Network Adapters')});
		
		// defined in pane
		vboxVMNetAdaptersInit(vm,nic);
	}
	l.run();
	
}

/*
 * 
 * Initialize a mount dialog
 * 
 */
function vboxMountInit(vm,bus,port,device,onmount) {
	
	/*
	 * 	Dialog
	 */
	var d = document.createElement('div');
	d.setAttribute('id','vboxMountDialog');
	d.setAttribute('style','display: none');
	$('#vboxIndex').append(d);

	/*
	 * Loader
	 */
	var l = new vboxLoader();
	l.add('VMDetails',function(d){$('#vboxMountDialog').data('vboxMachineData',d);},{'vm':vm});
	l.add('Mediums',function(d){$('#vboxIndex').data('vboxMediums',d);});
	l.add('RecentMediums',function(d){$('#vboxIndex').data('vboxRecentMediums',d);});
	l.addFile('panes/mount.html',function(f){$('#vboxMountDialog').append(f);})
	l.onLoad = function(){
		// defined in panes/mount.html
		var title = vboxMountPostInit(bus,port,device,onmount);
		var buttons = {};
		buttons[trans('Close')] = function() {$('#vboxMountDialog').trigger('close').empty().remove();};
		$(d).dialog({'closeOnEscape':false,'width':400,'height':160,'buttons':buttons,'modal':true,'autoOpen':true,'stack':true,'dialogClass':'vboxDialogContent','title':title});
	}
	l.run();

}

/*
 * 
 * Initialize a Preferences dialog
 * 
 */

function vboxPrefsInit() {
	
	// Prefs
	var panes = new Array(
		{'name':'GlobalGeneral','label':'General','icon':'machine'},
		{'name':'GlobalNetwork','label':'Network','icon':'nw'},
		{'name':'GlobalLanguage','label':'Language','icon':'site'},
		{'name':'GlobalUsers','label':'Users','icon':'register'}
	);
	
	var data = new Array(
		{'fn':'HostOnlyNetworking','callback':function(d){$('#vboxSettingsDialog').data('vboxHostOnlyNetworking',d);}},
		{'fn':'SystemProperties','callback':function(d){$('#vboxSettingsDialog').data('vboxSystemProperties',d);}},
		{'fn':'Users','callback':function(d){$('#vboxSettingsDialog').data('vboxUsers',d);}}
	);	
	
	// Check for noAuth setting
	if($('#vboxIndex').data('vboxConfig').noAuth || !$('#vboxIndex').data('vboxSession').admin) {
		panes.pop();
		data.pop();
	}
	
	vboxSettingsInit(trans('Preferences'),panes,data,function(){
		var l = new vboxLoader();
		l.mode = 'save';
		
		// Language change?
		if($('#vboxSettingsDialog').data('language') && $('#vboxSettingsDialog').data('language') != __vboxLangName) {
			vboxSetCookie('vboxLanguage',$('#vboxSettingsDialog').data('language'));
			l.onLoad = function(){location.reload(true);}
			
		// Update host info in case interfaces were added / removed
		} else if($('#vboxIndex').data('selectedVM') && $('#vboxIndex').data('selectedVM')['id'] == 'host') {
			l.onLoad = function() {
				$('#vboxIndex').trigger('vmselect',[$('#vboxIndex').data('selectedVM')]);
			}
		}
		l.add('saveHostOnlyInterfaces',function(){},{'networkInterfaces':$('#vboxSettingsDialog').data('vboxHostOnlyNetworking').networkInterfaces});
		l.add('saveSystemProperties',function(){},{'SystemProperties':$('#vboxSettingsDialog').data('vboxSystemProperties')});
		l.run();
	},null,'global_settings');
}



/*
 * 
 * Initialize a virtual machine settings dialog
 * 
 */

function vboxVMsettingsInit(vm,callback,pane) {
	
	var panes = new Array(
	
		{'name':'General','label':'General','icon':'machine','tabbed':true},
		{'name':'System','label':'System','icon':'chipset','tabbed':true},
		{'name':'Display','label':'Display','icon':'fullscreen','tabbed':true},
		{'name':'Storage','label':'Storage','icon':'attachment'},
		{'name':'Audio','label':'Audio','icon':'sound'},
		{'name':'Network','label':'Network','icon':'nw','tabbed':true},
		{'name':'SerialPorts','label':'Serial Ports','icon':'serial_port','tabbed':true},
		{'name':'ParallelPorts','label':'Parallel Ports','icon':'parallel_port','tabbed':true,'disabled':(!$('#vboxIndex').data('vboxConfig').enableLPTConfig)},
		{'name':'USB','label':'USB','icon':'usb'},
		{'name':'SharedFolders','label':'Shared Folders','icon':'shared_folder'}
			
	);
	
	var data = new Array(
		{'fn':'Mediums','callback':function(d){$('#vboxIndex').data('vboxMediums',d);}},
		{'fn':'HostNetworking','callback':function(d){$('#vboxSettingsDialog').data('vboxHostNetworking',d);}},
		{'fn':'HostDetails','callback':function(d){$('#vboxSettingsDialog').data('vboxHostDetails',d);}},
		{'fn':'VMDetails','callback':function(d){$('#vboxSettingsDialog').data('vboxMachineData',d);},'args':{'vm':vm,'force_refresh':$('#vboxIndex').data('vboxConfig').vmConfigRefresh}},
		{'fn':'HostUSBDevices','callback':function(d){$('#vboxSettingsDialog').data('vboxHostUSBDevices',d);}},
		{'fn':'EnumNetworkAdapterType','callback':function(d){$('#vboxSettingsDialog').data('vboxNetworkAdapterTypes',d);}},
		{'fn':'EnumAudioControllerType','callback':function(d){$('#vboxSettingsDialog').data('vboxAudioControllerTypes',d);}},
		{'fn':'RecentMediums','callback':function(d){$('#vboxIndex').data('vboxRecentMediums',d);}}

	);

	vboxSettingsInit(trans('Settings'),panes,data,function(){
		var loader = new vboxLoader();
		loader.mode = 'save';
		var sdata = $.extend($('#vboxSettingsDialog').data('vboxMachineData'),{'enableAdvancedConfig':$('#vboxIndex').data('vboxConfig').enableAdvancedConfig});
		loader.add('saveVM',function(){return;},sdata);
		loader.onLoad = function() {
			// Refresh mediums
			var mload = new vboxLoader();
			mload.add('Mediums',function(d){$('#vboxIndex').data('vboxMediums',d);});
			mload.onLoad = function() {
				if(callback){callback();}
			}
			mload.run();
		}
		loader.run();
	},pane,'settings');
}

/*
 * Network settings dialog for VM when VM is running 
 */
function vboxVMsettingsInitNetwork(vm,callback) {
	
	var panes = new Array(
		{'name':'Network','label':'Network','icon':'nw','tabbed':true}
	);
	
	var data = new Array(
			{'fn':'HostNetworking','callback':function(d){$('#vboxSettingsDialog').data('vboxHostNetworking',d);}},
			{'fn':'HostDetails','callback':function(d){$('#vboxSettingsDialog').data('vboxHostDetails',d);}},
			{'fn':'VMDetails','callback':function(d){$('#vboxSettingsDialog').data('vboxMachineData',d);},'args':{'vm':vm}},
			{'fn':'EnumNetworkAdapterType','callback':function(d){$('#vboxSettingsDialog').data('vboxNetworkAdapterTypes',d);}},
			{'fn':'EnumAudioControllerType','callback':function(d){$('#vboxSettingsDialog').data('vboxAudioControllerTypes',d);}}

	);

	vboxSettingsInit(trans('Settings'),panes,data,function(){
		var loader = new vboxLoader();
		loader.mode = 'save';
		var sdata = $.extend($('#vboxSettingsDialog').data('vboxMachineData'),{'enableAdvancedConfig':$('#vboxIndex').data('vboxConfig').enableAdvancedConfig});
		loader.add('saveVMNetwork',function(){if(callback){callback();}},sdata);
		loader.run();
	},'Network','nw');
}

/*
 * USB settings dialog for VM when VM is running 
 */
function vboxVMsettingsInitUSB(vm,callback) {
	
	var panes = new Array(
		{'name':'USBDevices','label':'USB Devices','icon':'usb'}
	);
	
	var data = new Array(
			{'fn':'HostUSBDevices','callback':function(d){$('#vboxSettingsDialog').data('vboxHostUSBDevices',d);}},
			{'fn':'VMDetails','callback':function(d){$('#vboxSettingsDialog').data('vboxMachineData',d);},'args':{'vm':vm}},
			{'fn':'VMUSBDevices','callback':function(d){$('#vboxSettingsDialog').data('vboxMachineUSBDevices',d);},'args':{'vm':vm}}
	);

	vboxSettingsInit(trans('Settings'),panes,data,function(){
		var loader = new vboxLoader();
		loader.mode = 'save';
		loader.add('saveVMUSBDevices',function(){if(callback){callback();}},$('#vboxSettingsDialog').data('vboxMachineData'));
		loader.run();
	},'USBDevices','usb');
}

/*
 * SharedFolders settings dialog for VM when VM is running 
 */
function vboxVMsettingsInitSharedFolders(vm,callback) {
	
	var panes = new Array(
		{'name':'SharedFolders','label':'Shared Folders','icon':'shared_folder','tabbed':false}
	);
	
	var data = new Array(
			{'fn':'HostDetails','callback':function(d){$('#vboxSettingsDialog').data('vboxHostDetails',d);}},
			{'fn':'VMDetails','callback':function(d){$('#vboxSettingsDialog').data('vboxMachineData',d);},'args':{'vm':vm}},
			{'fn':'VMTransientSharedFolders','callback':function(d){$('#vboxSettingsDialog').data('vboxTransientSharedFolders',d);},'args':{'vm':vm}}
	);

	vboxSettingsInit(trans('Settings'),panes,data,function(){
		var loader = new vboxLoader();
		loader.mode = 'save';
		loader.add('saveVMSharedFolders',function(){if(callback){callback();}},$('#vboxSettingsDialog').data('vboxMachineData'));
		loader.run();
	},'SharedFolders','shared_folder');
}



/*
 * 
 * 	Initialize a settings dialog (generic)
 * 		called by other dialog initializers
 * 
 * 
 */
function vboxSettingsInit(title,panes,data,onsave,pane,icon) {
	
	var d = document.createElement('div');
	d.setAttribute('id','vboxSettingsDialog');
	d.setAttribute('style','display: none;');
	
	var f = document.createElement('form');
	f.setAttribute('name','frmVboxSettings');
	$(f).attr('style','height: 100%')
	
	var t = document.createElement('table');
	t.setAttribute('class','vboxSettingsTable');
	$(t).attr('style','height: 100%;')
	
	var tr = document.createElement('tr');
	
	var td = document.createElement('td');
	td.setAttribute('id','vboxSettingsMenu');
	if(panes.length == 1) td.setAttribute('style','display: none');
	var ul = document.createElement('ul');
	ul.setAttribute('id','vboxSettingsMenuList');
	ul.setAttribute('class','vboxHover');
	td.appendChild(ul);
	tr.appendChild(td);
	
	var td = document.createElement('td');
	td.setAttribute('id','vboxSettingsPane');
	
	var d1 = document.createElement('div')
	d1.setAttribute('id','vboxSettingsTitle');
	if(panes.length == 1) d1.setAttribute('style','display: none');
	td.appendChild(d1);
	
	var d1 = document.createElement('div');
	d1.setAttribute('id','vboxSettingsList');
	td.appendChild(d1);
	tr.appendChild(td);
	
	t.appendChild(tr);
	f.appendChild(t);
	d.appendChild(f);
	
	$('#vboxIndex').append(d);

	/* Load panes and data */
	var loader = new vboxLoader();
	
	/* Load Data */
	for(var i = 0; i < data.length; i++) {
		loader.add(data[i].fn,data[i].callback,(data[i].args ? data[i].args : undefined));
	}

	/* Load settings panes */
	for(var i = 0; i < panes.length; i++) {
		
		if(panes[i].disabled) continue;
		
		var li = document.createElement('li');
		$(li).html('<div><img src="images/vbox/'+panes[i].icon+'_16px.png" /></div> <div>'+trans(panes[i].label)+'</div>').data(panes[i]).click(function(){
			
			$('#vboxSettingsTitle').html(trans($(this).data('label')));
			
			$(this).siblings().addClass('vboxListItem').removeClass('vboxListItemSelected');
			
			$(this).addClass('vboxListItemSelected');

			// jquery apply this css to everything with class .settingsPa..
			$('#vboxSettingsDialog .vboxSettingsPaneSection').css({'display':'none'});
			
			// Show selected pane
			$('#vboxSettingsPane-' + $(this).data('name')).css({'display':''});
			
			// Opera hidden select box bug
			////////////////////////////////
			if($.browser.opera) {
				$('#vboxSettingsPane-' + $(this).data('name')).find('select').trigger('show');
			}

		}).hover(function(){$(this).addClass('vboxHover');},function(){$(this).removeClass('vboxHover');});
		
		$('#vboxSettingsMenuList').append(li);
		
		var div = document.createElement('div');
		div.setAttribute('id','vboxSettingsPane-'+panes[i].name);
		div.setAttribute('style','display: none; height: 100%;');
		div.setAttribute('class','vboxSettingsPaneSection ui-corner-all ' + (panes[i].tabbed ? 'vboxTabbed' : 'vboxNonTabbed'));
		$('#vboxSettingsList').append(div);
		
		loader.addFile('panes/settings'+panes[i].name+'.html',function(f,i){
			$('#vboxSettingsPane-'+i.setting).append(f);
		},{'setting':panes[i].name});
		
	}

	loader.onLoad = function(){
		
		/* Init UI Items */
		for(var i = 0; i < panes.length; i++) {
			vboxSetLangContext('vbox'+panes[i].name.charAt(0).toUpperCase() + panes[i].name.slice(1));
			vboxInitDisplay($('#vboxSettingsPane-'+panes[i].name));
			vboxUnsetLangContext();
			if(panes[i].tabbed) $('#vboxSettingsPane-'+panes[i].name).tabs();
		}
		
		// Opera hidden select box bug
		////////////////////////////////
		if($.browser.opera) {
			$('#vboxSettingsPane').find('select').bind('change',function(){
				$(this).data('vboxSelected',$(this).val());
			}).bind('show',function(){
				$(this).val($(this).data('vboxSelected'));
			}).each(function(){
				$(this).data('vboxSelected',$(this).val());
			});
		}

		var buttons = { };
		buttons[trans('OK')] = function() {
			
			// Opera hidden select bug
			if($.browser.opera) {
				$('#vboxSettingsPane').find('select').each(function(){
					$(this).val($(this).data('vboxSelected'));
				});
			}
			
			$(this).trigger('save');
			onsave($(this));
			$(this).trigger('close').empty().remove();
			$(document).trigger('click');
		};
		buttons[trans('Cancel')] = function() {
			$('#vboxSettingsDialog').trigger('close').empty().remove();
			$(document).trigger('click');
		};

	    $('#vboxSettingsDialog').dialog({'closeOnEscape':false,'width':(panes.length > 1 ? 900 : 600),'height':(panes.length > 1 ? 500 : 450),'buttons':buttons,'modal':true,'autoOpen':true,'stack':true,'dialogClass':'vboxSettingsDialog vboxDialogContent','title':(icon ? '<img src="images/vbox/'+icon+'_16px.png" class="vboxDialogTitleIcon" /> ' : '') + title});			

	    /* Select first or passed menu item */
	    var i = 0;
	    var offset = 0;
	    var tab = undefined;
	    if(typeof pane == "string") {
	    	var section = pane.split(':');
	    	if(section[1]) tab = section[1];
	    	for(i = 0; i < panes.length; i++) {
	    		if(panes[i].disabled) offset++;
	    		if(panes[i].name == section[0]) break;
	    	}
	    }
	    i-=offset;
	    if(i >= panes.length) i = 0;
	    $('#vboxSettingsMenuList').children('li:eq('+i+')').first().click().each(function(){
	    	if(tab !== undefined) {
	    		$('#vboxSettingsPane-'+$(this).data('name')).tabs('select', parseInt(tab));
	    	}
	    	
	    });
	    
	    /* Only 1 pane? */
	    if(panes.length == 1) {
	    	$('#vboxSettingsDialog table.vboxSettingsTable').css('width','100%');
	    	$('#vboxSettingsDialog').dialog('option','title',(icon ? '<img src="images/vbox/'+icon+'_16px.png" class="vboxDialogTitleIcon" /> ' : '') + title + ' :: ' + trans(panes[0].label));
	    }
	    
	    $('#vboxSettingsDialog').trigger('show');
		
		
	};
	
	loader.run();

}


