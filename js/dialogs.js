/*
 * $Id: dialogs.js 683 2010-06-25 20:56:32Z ian $
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

		var vbw = new vboxWizard('wizardImportAppliance',trans('Appliance Import Wizard'),'images/vbox/vmw_ovf_import.png');
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
						// Imported mediums must be refreshed
						var ml = new vboxLoader();
						ml.add('Mediums',function(d){$('#vboxIndex').data('vboxMediums',d);});
						ml.run();
					});
				}
			},{'descriptions':descriptions,'file':file});
			$(dialog).remove();
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

	var vbw = new vboxWizard('wizardExportAppliance',trans('Appliance Export Wizard'),'images/vbox/vmw_ovf_export.png');
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
				vboxProgress(d.progress,function(){return;});
		},{'format':format,'file':file,'vms':vms});
		$(dialog).remove();
		l.run();


	};
	vbw.run();

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
		var vbw = new vboxWizard('wizardNewVM',trans('Create New Virtual Machine'),'images/vbox/vmw_new_welcome.png');
		vbw.steps = 5;
		vbw.onFinish = function(wiz,dialog) {

			// Get parameters
			var disk = document.forms['frmwizardNewVM'].newVMDiskSelect.options[document.forms['frmwizardNewVM'].newVMDiskSelect.selectedIndex].value;
			var name = jQuery.trim(document.forms['frmwizardNewVM'].newVMName.value);
			var ostype = document.forms['frmwizardNewVM'].newVMOSType.options[document.forms['frmwizardNewVM'].newVMOSType.selectedIndex].value;
			var mem = parseInt(document.forms['frmwizardNewVM'].wizardNewVMSizeValue.value);
			if(!document.forms['frmwizardNewVM'].newVMBootDisk.checked) disk = null;

			vboxAjaxRequest('createVM',{'disk':disk,'ostype':ostype,'memory':mem,'name':name},function(){if(callback){callback();}});
			
			$(dialog).remove();

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
		buttons[trans('Refresh')] = function() {
			l = new vboxLoader();
			l.add('VMLogFileNames',function(r){$('#vboxVMLogsDialog').data('logs',r);},{'vm':vm});
			l.onLoad = function(){
				vboxShowLogsInit(vm);
			}
			l.run();
		};
		buttons[trans('Close')] = function(){$(this).remove();};
		$('#vboxVMLogsDialog').dialog({'closeOnEscape':false,'width':800,'height':500,'buttons':buttons,'modal':true,'autoOpen':true,'stack':true,'dialogClass':'vboxDialogContent','title':trans('Logs')});
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

function vboxVMMDialogInit(callback,type,hideDiff,attached) {

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
					vboxAlert(trans('Please select a medium.'));
					return;
				}
				callback($(sel).data());
				$('#vboxVMMDialog').remove();
			}
		}
		buttons[trans('Close')] = function() {
			$('#vboxVMMDialog').remove();
			if(callback) callback(null);
		};
		$("#vboxVMMDialog").dialog({'closeOnEscape':false,'width':800,'height':500,'buttons':buttons,'modal':true,'autoOpen':true,'stack':true,'dialogClass':'vboxDialogContent','title':trans('Virtual Media Manager')});
		vboxVMMInit(hideDiff,attached);
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
	l.onLoad = function() {
		var vbw = new vboxWizard('wizardNewHD',trans('Create New Virtual Disk'),'images/vbox/vmw_new_harddisk.png');
		vbw.steps = 4;
		vbw.suggested = suggested;
		vbw.onFinish = function(wiz,dialog) {

			var file = document.forms['frmwizardNewHD'].elements.wizardNewHDLocation.value;
			var size = vboxConvertMbytes(document.forms['frmwizardNewHD'].elements.wizardNewHDSizeValue.value);
			var type = (document.forms['frmwizardNewHD'].elements.newHardDiskType[1].checked ? 'fixed' : 'dynamic');

			$(dialog).remove();

			var l = new vboxLoader();
			l.mode = 'save';
			l.add('mediumCreateBaseStorage',function(d,e){
				if(d && d.progress) {
					vboxProgress(d.progress,callback,d.id);
				} else {
					callback(d);
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
		buttons[trans('Close')] = function() {$('#vboxGuestNetworkDialog').remove();};
		$(d).dialog({'closeOnEscape':false,'width':500,'height':250,'buttons':buttons,'modal':true,'autoOpen':true,'stack':true,'dialogClass':'vboxDialogContent','title':trans('Guest Network Adapters')});
		
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
	l.add('VMDetails',function(d){$('#vboxIndex').data('vboxMachineData',d);},{'vm':vm});
	l.add('Mediums',function(d){$('#vboxIndex').data('vboxMediums',d);});
	l.addFile('panes/mount.html',function(f){$('#vboxMountDialog').append(f);})
	l.onLoad = function(){
		// defined in panes/mount.html
		vboxMountPostInit(bus,port,device,onmount);
		var buttons = {};
		buttons[trans('Close')] = function() {$('#vboxMountDialog').remove();};
		$(d).dialog({'closeOnEscape':false,'width':'auto','height':170,'buttons':buttons,'modal':true,'autoOpen':true,'stack':true,'dialogClass':'vboxDialogContent','title':trans('Mount')});
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
		{'name':'GlobalNetwork','label':'Network','icon':'nw'}
	);
	
	var data = new Array(
		{'fn':'HostOnlyNetworking','callback':function(d){$('#vboxSettingsDialog').data('vboxHostOnlyNetworking',d);}},
		{'fn':'SystemProperties','callback':function(d){$('#vboxSettingsDialog').data('vboxSystemProperties',d);}}
	);	
	
	vboxSettingsInit(trans('Settings'),panes,data,function(){
		var l = new vboxLoader();
		l.mode = 'save';
		l.add('saveHostOnlyInterfaces',function(){},{'networkInterfaces':$('#vboxSettingsDialog').data('vboxHostOnlyNetworking').networkInterfaces});
		l.add('saveSystemProperties',function(){},{'SystemProperties':$('#vboxSettingsDialog').data('vboxSystemProperties')});
		l.run();
	});
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
		{'name':'USB','label':'USB','icon':'usb','disabled':($('#vboxIndex').data('vboxConfig').version.ose)},
		{'name':'SharedFolders','label':'Shared Folders','icon':'shared_folder'}
			
	);
	
	var data = new Array(
		{'fn':'Mediums','callback':function(d){$('#vboxIndex').data('vboxMediums',d);}},
		{'fn':'HostNetworking','callback':function(d){$('#vboxSettingsDialog').data('vboxHostNetworking',d);}},
		{'fn':'HostDetails','callback':function(d){$('#vboxSettingsDialog').data('vboxHostDetails',d);}},
		{'fn':'VMDetails','callback':function(d){$('#vboxSettingsDialog').data('vboxMachineData',d);},'args':{'vm':vm}},
		{'fn':'HostUSBDevices','callback':function(d){$('#vboxSettingsDialog').data('vboxHostUSBDevices',d);}},
		{'fn':'EnumNetworkAdapterType','callback':function(d){$('#vboxSettingsDialog').data('vboxNetworkAdapterTypes',d);}},
		{'fn':'EnumAudioControllerType','callback':function(d){$('#vboxSettingsDialog').data('vboxAudioControllerTypes',d);}}

	);	

	vboxSettingsInit(trans('Settings'),panes,data,function(){
		var loader = new vboxLoader();
		loader.mode = 'save';
		loader.add('saveVM',function(){if(callback){callback();}},$('#vboxSettingsDialog').data('vboxMachineData'));
		loader.run();
	},pane);
}

		
/*
 * 
 * 	Initialize a settings dialog (generic)
 * 		called by other dialog initializers
 * 
 * 
 */
function vboxSettingsInit(title,panes,data,onsave,pane) {
	
	var d = document.createElement('div');
	d.setAttribute('id','vboxSettingsDialog');
	d.setAttribute('style','display: none');
	
	var f = document.createElement('form');
	f.setAttribute('name','frmVboxSettings');
	
	var t = document.createElement('table');
	t.setAttribute('class','vboxSettingsTable');
	
	var tr = document.createElement('tr');
	
	var td = document.createElement('td');
	td.setAttribute('id','vboxSettingsMenu');
	var ul = document.createElement('ul');
	ul.setAttribute('id','vboxSettingsMenuList');
	td.appendChild(ul);
	tr.appendChild(td);
	
	var td = document.createElement('td');
	td.setAttribute('id','vboxSettingsPane');
	var d1 = document.createElement('div');
	d1.setAttribute('id','vboxSettingsTitle');
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
		li.innerHTML = '<img src="images/vbox/'+panes[i].icon+'_16px.png" /> '+trans(panes[i].label);
		$(li).data(panes[i]);
		$(li).click(function(){
			
			$('#vboxSettingsTitle').html(trans($(this).data('label')));
			
			$(this).siblings().addClass('vboxListItem').removeClass('vboxListItemSelected');
			
			$(this).addClass('vboxListItemSelected');

			// jquery apply this css to everything with class .settingsPa..
			$('#vboxSettingsDialog .vboxSettingsPaneSection').css({'display':'none'});
			
			// Show selected pane
			$('#vboxSettingsPane-' + $(this).data('name')).css('display','block');
			
			// Opera hidden select box bug
			////////////////////////////////
			if($.browser.opera) {
				$('#vboxSettingsPane-' + $(this).data('name')).find('select').trigger('show');
			}

		});
		$('#vboxSettingsMenuList').append(li);
		
		var div = document.createElement('div');
		div.setAttribute('id','vboxSettingsPane-'+panes[i].name);
		div.setAttribute('style','display: none');
		div.setAttribute('class','vboxSettingsPaneSection ui-corner-all ' + (panes[i].tabbed ? 'vboxTabbed' : 'vboxNonTabbed'));
		$('#vboxSettingsList').append(div);
		
		loader.addFile('panes/settings'+panes[i].name+'.html',function(f,i){
			$('#vboxSettingsPane-'+i.setting).append(f);
		},{'setting':panes[i].name});
		
	}

	loader.onLoad = function(){
		
		/* Init UI Items */
		vboxInitDisplay('vboxSettingsDialog');
		
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
			
			$('#vboxSettingsDialog').trigger('save');
			onsave($('#vboxSettingsDialog'));
			$('#vboxSettingsDialog').remove();
		};
		buttons[trans('Cancel')] = function() {
			$('#vboxSettingsDialog').trigger('close')
			$('#vboxSettingsDialog').remove();
		};

	    $('#vboxSettingsDialog').dialog({'closeOnEscape':false,'width':900,'height':500,'buttons':buttons,'modal':true,'autoOpen':true,'stack':true,'dialogClass':'vboxDialogContent','title':title});			

	    /* Select first or passed menu item */
	    var i = 0;
	    if(typeof pane == "string") {
	    	for(i = 0; i < panes.length; i++) {
	    		if(panes[i].name == pane) break;
	    	}
	    }
	    
	    $('#vboxSettingsMenuList').children('li:eq('+i+')').first().click();
	    
		
		
	};
	
	loader.run();

}


