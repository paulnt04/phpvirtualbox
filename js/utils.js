/*
 * $Id$
 * Copyright (C) 2011 Ian Moore (imoore76 at yahoo dot com)
 * 		except function strnatcasecmp
 */

/*
 * 
 * Prevent ESC key from stopping background AJAX requests
 * 
 */
$(document).ready(function(){
        $(window).keydown(function(i){if(i.keyCode&&i.keyCode===27){
                i.preventDefault()
                try {
                        var flash = RDPWebClient.getFlashById("FlashRDP");
                        flash.keyboardSendScancodes('01');
                } catch (e) {
                        //alert(e.message);
                }
        }});
        $(document).keydown(function(i){if(i.keyCode&&i.keyCode===27){
                i.preventDefault()
                try {
                        var flash = RDPWebClient.getFlashById("FlashRDP");
                        flash.keyboardSendScancodes('01');
                } catch (e) {
                        //alert(e.message);
                }
        }});
});

/*
 * jquery.post/json wrapper
 * 
 * Performs ajax request, alert()'s returned errors,
 * and stores any data that should persist for this
 * browser session 
 */
function vboxAjaxRequest(fn,params,callback,xtra) {

	// Fatal error previously occurred
	if($('#vboxIndex').data('vboxFatalError')) return;

	params['fn'] = fn;
	
	vboxAjaxPersist = ($('#vboxIndex').data('vboxAjaxPersist') ? $('#vboxIndex').data('vboxAjaxPersist') : []);
	for(var i in vboxAjaxPersist) params[i] = vboxAjaxPersist[i];
	
	return jQuery.post('lib/ajax.php', params,
		function(d) {

			// Fatal error previously occurred
			if($('#vboxIndex').data('vboxFatalError')) return;

			if(d) {
				if(d.errors.length > 0) {
					for(var i = 0; i < d.errors.length; i++) {
						
						vboxAjaxError(d.errors[i]);
						
						// Handle fatal error
						if($('#vboxIndex').data('vboxConfig') &&
								$('#vboxIndex').data('vboxConfig').PHPVB_ERRNO_FATAL &&
								d.errors[i].errno == $('#vboxIndex').data('vboxConfig').PHPVB_ERRNO_FATAL) {
							
							$('#vboxIndex').data('vboxFatalError',1);
							$('#vboxIndex').css({'display':'none'});
							
							var s = '';
							// Multiple Servers check
							if($('#vboxIndex').data('vboxConfig').servers.length) {
								var servers = $('#vboxIndex').data('vboxConfig').servers;
								for(var i = 0; i < servers.length; i++) {
									servers[i] = "<a href='?server="+servers[i].name+"'>"+$('<div />').html(servers[i].name).text()+"</a>";
								}
								s = '<div style="display: block">'+trans('Server List')+': '+servers.join(', ')+'</div>';
							}
							vboxAlert('<p>'+trans('Fatal error')+'</p>'+s,{'width':'50%'});
						}
					}
				}
				$('#vboxIndex').data('vboxAjaxPersist',d.persist);
				callback(d.data,xtra);
			} else {
				callback(d,xtra);
			}
		},
		"json");
	
}
function vboxGetScript(file,callback,cparams) {
	return jQuery.getScript(file,function(f){callback(f,cparams);});
}

function vboxGetFile(file,callback,cparams) {
	return jQuery.get(file,function(f){callback(f,cparams);});
}

/*
 * The below 2 functions have been taken from vboxweb
(http://code.google.com/p/vboxweb/), distributed
 * under the MIT License. See the vboxweb website
 * actual for copyright date and holders.
 * 
 */
	function vboxGuestOSTypeIcon(osTypeId)
    {
        var strIcon = "os_other.png";
        switch (osTypeId)
        {
            case "Other":           strIcon = "os_other.png"; break;
            case "DOS":             strIcon = "os_dos.png"; break;
            case "Netware":         strIcon = "os_netware.png"; break;
            case "L4":              strIcon = "os_l4.png"; break;
            case "Windows31":       strIcon = "os_win31.png"; break;
            case "Windows95":       strIcon = "os_win95.png"; break;
            case "Windows98":       strIcon = "os_win98.png"; break;
            case "WindowsMe":       strIcon = "os_winme.png"; break;
            case "WindowsNT4":      strIcon = "os_winnt4.png"; break;
            case "Windows2000":     strIcon = "os_win2k.png"; break;
            case "WindowsXP":       strIcon = "os_winxp.png"; break;
            case "WindowsXP_64":    strIcon = "os_winxp_64.png"; break;
            case "Windows2003":     strIcon = "os_win2k3.png"; break;
            case "Windows2003_64":  strIcon = "os_win2k3_64.png"; break;
            case "WindowsVista":    strIcon = "os_winvista.png"; break;
            case "WindowsVista_64": strIcon = "os_winvista_64.png"; break;
            case "Windows2008":     strIcon = "os_win2k8.png"; break;
            case "Windows2008_64":  strIcon = "os_win2k8_64.png"; break;
            case "Windows7":        strIcon = "os_win7.png"; break;
            case "Windows7_64":     strIcon = "os_win7_64.png"; break;
            case "WindowsNT":       strIcon = "os_win_other.png"; break;
            case "OS2Warp3":        strIcon = "os_os2warp3.png"; break;
            case "OS2Warp4":        strIcon = "os_os2warp4.png"; break;
            case "OS2Warp45":       strIcon = "os_os2warp45.png"; break;
            case "OS2eCS":          strIcon = "os_os2ecs.png"; break;
            case "OS2":             strIcon = "os_os2_other.png"; break;
            case "Linux22":         strIcon = "os_linux22.png"; break;
            case "Linux24":         strIcon = "os_linux24.png"; break;
            case "Linux24_64":      strIcon = "os_linux24_64.png"; break;
            case "Linux26":         strIcon = "os_linux26.png"; break;
            case "Linux26_64":      strIcon = "os_linux26_64.png"; break;
            case "ArchLinux":       strIcon = "os_archlinux.png"; break;
            case "ArchLinux_64":    strIcon = "os_archlinux_64.png"; break;
            case "Debian":          strIcon = "os_debian.png"; break;
            case "Debian_64":       strIcon = "os_debian_64.png"; break;
            case "OpenSUSE":        strIcon = "os_opensuse.png"; break;
            case "OpenSUSE_64":     strIcon = "os_opensuse_64.png"; break;
            case "Fedora":          strIcon = "os_fedora.png"; break;
            case "Fedora_64":       strIcon = "os_fedora_64.png"; break;
            case "Gentoo":          strIcon = "os_gentoo.png"; break;
            case "Gentoo_64":       strIcon = "os_gentoo_64.png"; break;
            case "Mandriva":        strIcon = "os_mandriva.png"; break;
            case "Mandriva_64":     strIcon = "os_mandriva_64.png"; break;
            case "RedHat":          strIcon = "os_redhat.png"; break;
            case "RedHat_64":       strIcon = "os_redhat_64.png"; break;
            case "Turbolinux":      strIcon = "os_turbolinux.png"; break;
            case "Ubuntu":          strIcon = "os_ubuntu.png"; break;
            case "Ubuntu_64":       strIcon = "os_ubuntu_64.png"; break;
            case "Xandros":         strIcon = "os_xandros.png"; break;
            case "Xandros_64":      strIcon = "os_xandros_64.png"; break;
            case "Linux":           strIcon = "os_linux_other.png"; break;
            case "FreeBSD":         strIcon = "os_freebsd.png"; break;
            case "FreeBSD_64":      strIcon = "os_freebsd_64.png"; break;
            case "OpenBSD":         strIcon = "os_openbsd.png"; break;
            case "OpenBSD_64":      strIcon = "os_openbsd_64.png"; break;
            case "NetBSD":          strIcon = "os_netbsd.png"; break;
            case "NetBSD_64":       strIcon = "os_netbsd_64.png"; break;
            case "Solaris":         strIcon = "os_solaris.png"; break;
            case "Solaris_64":      strIcon = "os_solaris_64.png"; break;
            case "OpenSolaris":     strIcon = "os_opensolaris.png"; break;
            case "OpenSolaris_64":  strIcon = "os_opensolaris_64.png"; break;
            case "QNX":             strIcon = "os_qnx.png"; break;
            case 'MacOS':			strIcon = "os_macosx.png"; break;
            case 'MacOS_64':			strIcon = "os_macosx_64.png"; break;
            case 'Oracle':			strIcon = "os_oracle.png"; break;
            case 'Oracle_64':			strIcon = "os_oracle_64.png"; break;
            case "VirtualBox_Host":	strIcon = "os_virtualbox.png"; break;

            default:
                break;
        }
        return strIcon;
}

    
function vboxMachineStateIcon(state)
   {
        var strIcon = "state_powered_off_16px.png";
        var strNoIcon = "state_running_16px.png";

        switch (state)
        {
            case "PoweredOff": strIcon = "state_powered_off_16px.png"; break;
            case "Saved": strIcon = "state_saved_16px.png"; break;
            case "Teleported": strIcon = strNoIcon; break;
            case "LiveSnapshotting": strIcon = "online_snapshot_16px.png"; break;
            case "Aborted": strIcon = "state_aborted_16px.png"; break;
            case "Running": strIcon = "state_running_16px.png"; break;
            case "Paused": strIcon = "state_paused_16px.png"; break;
            case "Stuck": strIcon = "state_stuck_16px.png"; break;
            case "Teleporting": strIcon = strNoIcon; break;
            case "Starting": strIcon = strNoIcon; break;
            case "Stopping": strIcon = strNoIcon; break;
            case "Saving": strIcon = "state_discarding_16px.png"; break;
            case "Restoring": strIcon = "settings_16px.png"; break;
            case "TeleportingPausedVM": strIcon = strNoIcon; break;
            case "TeleportingIn": strIcon = strNoIcon; break;
            case "RestoringSnapshot": strIcon = "discard_cur_state_16px.png"; break;
            case "DeletingSnapshot": strIcon = "state_discarding_16px.png"; break;
            case "SettingUp": strIcon = strNoIcon; break;
            case "Hosting" : strIcon = "settings_16px.png"; break;
            case "Inaccessible": strIcon = "state_aborted_16px.png"; break;
            default:
                break;
        }
        
        return strIcon;

}
/* File or Folder browser */
function browseFolder(root,fn) {
	vboxFileBrowser(root,fn,true);
}
function vboxFileBrowser(root,fn,foldersonly) {

	var buttons = { };
	buttons[trans('OK')] = function(f) {
		if(typeof f != 'string') {
			f = $('#vboxBrowseFolderList').find('.vboxListItemSelected').first().attr('name');
		}
		$('#vboxBrowseFolder').remove();
		fn(f);
	};
	buttons[trans('Cancel')] = function() { fn(null); $('#vboxBrowseFolder').remove(); };

	var d1 = document.createElement('div');
	d1.setAttribute('id','vboxBrowseFolder');
	d1.setAttribute('class','vboxDialogContent');
	d1.setAttribute('style','display: none');
	
	var d2 = document.createElement('div');
	d2.setAttribute('id','vboxBrowseFolderList');
	$(d2).fileTree({ 'root': (root ? root : '/'),'dirsOnly':(foldersonly ? 1 : 0) },function(f){
    	buttons[trans('OK')](f);
    });
	
	$(d1).append(d2);
	
	$('#vboxIndex').append(d1);
	

    $(d1).dialog({'closeOnEscape':false,'width':400,'height':600,'buttons':buttons,'modal':true,'autoOpen':true,'stack':true,'dialogClass':'vboxDialogContent','title':trans((foldersonly ? 'Select Folder' : 'Select File'))});			

}

/* Byte / MByte -> Human readable conversion */
function vboxMbytesConvert(mb) {return vboxBytesConvert(parseFloat(mb) * 1024 * 1024);}
function vboxBytesConvert(bytes) {
	var ext = new Array('B','KB','MB','GB','TB');
	var unitCount;
	for(unitCount=0; bytes >= 1024 && unitCount < ext.length; unitCount++) bytes = parseFloat(parseFloat(bytes)/1024);
	return Math.round(parseFloat(bytes)*Math.pow(10,2))/Math.pow(10,2) + " " + trans(ext[unitCount]);
}

function vboxConvertMbytes(str) {
	str = str.replace('  ',' ');
	str = str.split(' ',2);
	if(!str[1]) str[1] = trans('MB');
	var ext = new Array(trans('B'),trans('KB'),trans('MB'),trans('GB'),trans('TB'));
	var index = jQuery.inArray(str[1],ext);
	if(index == -1) index = 2;
	switch(index) {
		case 0:
			return ((str[0] / 1024) / 1024);
			break;
		case 1:
			return (str[0] / 1024);
			break;
		case 3:
			return (str[0] * 1024);
			break;
		case 4:
			return (str[0] * 1024 * 1024);
			break;
		default:
			return (str[0]); 
	}
	
}

/* Medium Helpers */
function vboxMediumAttachedTo(m) {
	var s = new Array();
	if(!m.attachedTo) return '<i>'+trans('Not Attached')+'</i>';
	for(var i = 0; i < m.attachedTo.length; i++) {
		s[s.length] = m.attachedTo[i].machine + (m.attachedTo[i].snapshots.length ? ' (' + m.attachedTo[i].snapshots.join(trans('LIST_SEP')) + ')' : '');
	}
	return s.join(trans('LIST_SEP'));
}

function vboxMediumType(m) {
	if(!m || !m.type) return trans('Normal');
	if(m.type == 'Normal' && m.parent) return trans('Differencing');
	return trans(m.type);
}
/*
 * 
 * Error message dialog from ajax request
 * 
 */
function vboxAjaxError(e) {

	var div = document.createElement('div');
	div.setAttribute('class','vboxDialogContent vboxAjaxError');
	
	div.innerHTML = '<img src="images/50px-Warning_icon.svg.png" style="float: left; padding: 10px;" /><p>'+e.error+'</p>';
	
	var p = document.createElement('p');
	p.setAttribute('style','text-align: center');
	
	var showlink = document.createElement('a');
	showlink.setAttribute('href','#');
	showlink.innerHTML = trans('Details');
	$(showlink).click(function(){
		$(this).parent().parent().dialog('option',{'height':400,'position':'center'});
		$(this).parent().siblings(".vboxAjaxErrorDetails").css({"display":""});
		$(this).parent().css({'padding':'0px','margin':'0px'});
		$(this).parent().siblings(".vboxAjaxErrorDetails").siblings().remove();
		return false;
	});
	
	$(p).append(showlink);
	$(div).append(p);
	
	var ddet = document.createElement('div');
	ddet.setAttribute('style','display: none; height: 100%; width: auto;');
	ddet.setAttribute('class','vboxAjaxErrorDetails');
	var frm = document.createElement('form');
	var txt = document.createElement('textarea');
	txt.setAttribute('spellcheck','false');
	txt.setAttribute('wrap','off');
	$(txt).val(e.details);
	$(txt).attr('readonly','true');
	$(frm).append(txt);
	$(ddet).append(frm);
	
	$(div).append(ddet);
	
	$('#vboxIndex').append(div);

	var buttons = { };
	buttons[trans('OK')] = function(f) {$(this).remove();};

    $(div).dialog({'closeOnEscape':false,'width':400,'height':'auto','buttons':buttons,'modal':true,'autoOpen':true,'stack':true,'dialogClass':'vboxDialogContent','title':'phpVirtualBox'});			
	
}
/*
 * Alert Dialog
 */
function vboxAlert(msg,xtraOpts) {

	var div = document.createElement('div');
	div.setAttribute('class','vboxDialogContent');
	
	div.innerHTML = '<img src="images/50px-Warning_icon.svg.png" style="float: left; padding: 10px;" />';
	$(div).append(msg);
	$('#vboxIndex').append(div);

	var buttons = { };
	buttons[trans('OK')] = function(f) {$(this).remove();};
	
	var dialogOpts = {'closeOnEscape':false,'width':'50%','height':'auto','buttons':buttons,'modal':true,'autoOpen':true,'stack':true,'dialogClass':'vboxDialogContent','title':'phpVirtualBox'};

	if(typeof xtraOpts == "object") {
		for(var i in xtraOpts) {
			if(typeof i != "string") continue;
			dialogOpts[i] = xtraOpts[i];
		}
	}

    $(div).dialog(dialogOpts);			

}
/*
 * Confirmation dialog
 */
// question, button text, callback function
function vboxConfirm(q,buttons) {


	var div = document.createElement('div');
	div.setAttribute('class','vboxDialogContent');
	div.setAttribute('style','display: none; width: 500px;');
	
	div.innerHTML = '<img src="images/50px-Question_icon.svg.png" style="float: left; padding: 10px;" />'+q;
	$('#vboxIndex').append(div);

	buttons[trans('Cancel')] = function() { $(this).remove(); };

    $(div).dialog({'closeOnEscape':false,'width':500,'height':'auto','buttons':buttons,'modal':true,'autoOpen':true,'stack':true,'dialogClass':'vboxDialogContent','title':'phpVirtualBox'});			
	
    return $(div);
}

/*
 * Common UI setup functions
 */
function vboxInitDisplay(root) {
	
	if(typeof root == 'string')
		root = $('#'+root);
	
	/* 
	 * Sliders 
	 */
	
	$(root).find('div.slider').each(function(){
	
		var frm = $(this).data('form');
		if($(this).data('display')) {
			var fn = $(this).data('display');
			$(this).slider('option','slide',function(event,ui){document.forms[frm].elements[event.target.id + 'Value'].value = fn(ui.value);});
			$(this).slider('option','change',function(event,ui){document.forms[frm].elements[event.target.id + 'Value'].value = fn(ui.value);});			
		} else {
			$(this).slider('option','slide',function(event,ui){document.forms[frm].elements[event.target.id + 'Value'].value = ui.value;});
			$(this).slider('option','change',function(event,ui){document.forms[frm].elements[event.target.id + 'Value'].value = ui.value;});
		}
		
		// Slider scale (ticks)
		$(this).children("div.sliderScale").each(function(){
	
			var min = $(this).parent().slider('option','min');
			var max = $(this).parent().slider('option','max');
			
			var diff = Math.min((max - min),50);
			var tdw = Math.round(100 / diff);
			
			var tbl = document.createElement('table');
			tbl.setAttribute('class','sliderScale');
			var tr = document.createElement('tr');
	
			for(var a = 0; a < diff; a++) {
				var td = document.createElement('td');
				td.setAttribute('style','width: ' + tdw + '%');
				tr.appendChild(td);
			}
			tbl.appendChild(tr);
			$(this).append(tbl);
			
		});
	
		// Min / Max labels
		$(this).parentsUntil('table').parent().find('.vboxSliderMin').html($(this).slider('option','min'));
		$(this).parentsUntil('table').parent().find('.vboxSliderMax').html($(this).slider('option','max'));
	
		// Trigger change
		$(this).slider('value',$(this).slider('value'));
	});

	
	/*
	 * Translations
	 */
	$(root).find(".translate").html(function(i,h){return trans(h);}).removeClass('translate');


	/*
	 * Setup Tabs
	 */
	$(root).find(".vboxTabbed").tabs();
	
	
	/* Image buttons */
	if(!jQuery.browser.msie) {
		
		$(root).find('input.vboxImgButton').bind('mousedown',function(){
	
			var xy = $(this).css('backgroundPosition').split(' ');
	
			if(!$(this).data('startX')) $(this).data('startX', parseInt(xy[0]));
			if(!$(this).data('startY')) $(this).data('startY', parseInt(xy[1]));
	
			$(this).css('backgroundPosition',(parseInt($(this).data('startX'))+1)+'px '+(parseInt($(this).data('startY'))+1)+'px'); 
			
			var btn = this;
			$(document).one('mouseup',function(){
				$(btn).css('backgroundPosition',$(btn).data('startX')+'px '+$(btn).data('startY')+'px');
			});
				
		});
		
	}
	
	/*
	 * 
	 * Enable / disable sections (Remote Display, Audio, Network Adapters, usb)
	 * 
	 */
	
	$(root).find('input.vboxEnablerCheckbox').click(function(e,first) {
	
			var roottbl = $(this).parentsUntil('table');
			
			$(roottbl).find('input:not(.vboxEnablerCheckbox)').attr('disabled',(this.checked ? '' : 'disabled'));
			$(roottbl).find('select').attr('disabled',(this.checked ? '' : 'disabled'));
			(this.checked ? $(roottbl).find('th').removeClass('vboxDisabled') : $(roottbl).find('th').addClass('vboxDisabled'));
			(this.checked ? $(roottbl).find('.vboxEnablerListen').removeClass('vboxDisabled') : $(roottbl).find('.vboxEnablerListen').addClass('vboxDisabled'));
	
			// Find any enabler / disabler listeners
			$(roottbl).find('.vboxEnablerTrigger').trigger(this.checked ? 'enable' : 'disable');
			
			// Don't check / uncheck if not actually clicked
			if(first) e.preventDefault();
	
	}).trigger('click',true);
	
	
	/*
	 * Tooltips
	 */
	$(root).find('.vboxToolbarSmallButton').tipped({'source':'title','mode':'hover'});
	
	
	/*
	 * File / Folder browsers
	 */
	if($('#vboxIndex').data('vboxConfig').browserDisable) {
		$(root).find('table td.vboxFileFolderInput input.vboxImgButton').hide();
	}


}

/* Color VISIBLE children of parent elm */
function vboxColorRows(elm) {
	var odd = 0;
	$(elm).children().each(function(i){
		if($(this).css('display') == 'none') return;
		(odd++ % 2 ? $(this).addClass('vboxOddRow') : $(this).removeClass('vboxOddRow'));
	});
}

/*
 * Div sized to parent with overflow hidden
 */
function vboxDivOverflowHidden(p) {
	var w = $(p).innerWidth();
	w -= parseInt($(p).css('padding-right'));
	w -= parseInt($(p).css('padding-left'));
	var d = document.createElement('div');
	$(d).css({'width':(w-4)+'px','overflow':'hidden','padding':'0px','margin':'0px','border':'0px'});
	return d;
}
/*
 * 
 * Progress dialog and supporting functions
 * 
 */
function vboxProgress(pid,callback,args) {
	
	var div = document.createElement('div');
	div.setAttribute('id','vboxProgressDialog');
	div.setAttribute('title','phpVirtualBox');
	div.setAttribute('style','text-align: center');
	
	var divp = document.createElement('div');
	divp.setAttribute('id','vboxProgressBar');
	div.appendChild(divp);
	
	var divpt = document.createElement('div');
	divpt.setAttribute('id','vboxProgressText');
	divpt.innerHTML = '<img src="images/spinner.gif" />';
	div.appendChild(divpt);

	// Cancel button
	var cdiv = document.createElement('div');
	$(cdiv).attr('id','vboxProgressCancel')
	$(cdiv).css({'display':'none','padding':'8px'});

	var b = document.createElement('input');
	$(b).attr('type','button');
	$(b).val(trans('Cancel'));
	$(b).data('pid', pid);
	$(b).click(function(){
		this.disabled = 'disabled';
		vboxAjaxRequest('cancelProgress',{'progress':$(this).data('pid')},function(d){return;});
	});
	$(cdiv).append(b);
	$(div).append(cdiv);
	
	$('#vboxIndex').append(div);
	
	$("#vboxProgressDialog").data({'callback':callback,'args':args});

	$("#vboxProgressBar").progressbar({ value: 1 });
	$("#vboxProgressDialog").dialog({'closeOnEscape':false,'modal':true,'resizable':false,'draggable':false,'closeOnEscape':false,'buttons':{}});
	
	// Don't unload while progress operation is .. in progress
	window.onbeforeunload = vboxOpInProgress;
	
	vboxAjaxRequest('getProgress',{'progress':pid},vboxProgressUpdate,{'pid':pid});
}
// OnUnload warning
function vboxOpInProgress() { return trans('Operation in progress');}

function vboxProgressUpdate(d,e) {
	
	// check for completed progress
	if(!d || !d['progress'] || d['info']['completed'] || d['info']['canceled']) {
		var args = $("#vboxProgressDialog").data('args');
		if(d['info']['canceled']) vboxAlert(trans('Operation Canceled'));
		$("#vboxProgressDialog").data('callback')(d,args);
		$("#vboxProgressDialog").remove();
		window.onbeforeunload = null;
		return;
	}

	// update percent
	$("#vboxProgressBar").progressbar({ value: d.info.percent });
	$("#vboxProgressText").html(d.info.percent+'%<br />'+d.info.description);
	
	// Cancelable?
	if(d.info.cancelable) {
		$('#vboxProgressCancel').show();
	}
	
	window.setTimeout("vboxAjaxRequest('getProgress',{'progress':'"+e.pid+"'},vboxProgressUpdate,{'pid':'"+e.pid+"'})", 3000);
	
}

/* Position element to mouse event */
function vboxPositionEvent(elm,e) {
	
	var d = {}, posX, posY;
	
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

	$(elm).css({'left':0,'top':0});

	(e.pageX) ? x = e.pageX : x = e.clientX + d.scrollLeft;
	(e.pageY) ? y = e.pageY : y = e.clientY + d.scrollTop;
	
	//adjust to ensure element is inside viewable screen
	var right = x + $(elm).outerWidth();
	var bottom = y + $(elm).outerHeight();
	
	var windowWidth = $(window).width() + $(window).scrollLeft()-5;
	var windowHeight = $(window).height() + $(window).scrollTop()-5;
	
	x = (right > windowWidth) ? x - (right - windowWidth) : x;
	y = (bottom > windowHeight) ? y - (bottom - windowHeight) : y;
	
	$(elm).css({ top: y, left: x });
}

/*
 * keycode input validation functions 
 */
function vboxValidateNum(k) {
	return ((k >= 96 && k <= 105)||(k >= 48 && k <= 57))
}
function vboxValidateIP(k) {
	return (vboxValidateNum(k) || k == 190); 
}
/* ctrl keyboard chars */
function vboxValidateCtrl(k) {
	switch(k) {
		case 8: // backspace
		case 37: // left | right
		case 39:
		case 27: // esc
		case 16: // shift
		case 17: // ctrl
		case 35: // end
		case 36: // home
		case 46: // del
		case 144: // numlock
		case 20: // capslock
		case 18: // alt
			return true;
	}
	return false;
}

/* Parse Cookies */
function vboxParseCookies() {
	if($('#vboxIndex').data('vboxCookiesParsed')) return;
	var cookies = {};
	var c = document.cookie.split('; ');
	for(var i = 0; i < c.length; i++) {
		var nv = c[i].split('=');
		cookies[nv[0]] = nv[1];
	}	
	$('#vboxIndex').data('vboxCookies', cookies);
	$('#vboxIndex').data('vboxCookiesParsed',true);
}

/* Check version against supported versions */
function vboxVersionCheck(ver) {
	
	var supported = {'4':{'0':1}};
	
	// No ver passed?
	if(ver && !supported[ver.major][ver.minor]) {
		
		vboxParseCookies();
		
		if($('#vboxIndex').data('vboxCookies')["vboxIgnoreVersion"+ver.string]) return;
		
		var d = document.createElement('span');
		
		$(d).append(trans('Unsupported version').replace('%s','<b>'+ver.string+'</b>')+'<br /><br />');

		var cb = document.createElement('input');
		cb.setAttribute('type','checkbox');
		cb.setAttribute('class','vboxCheckbox');
		$(cb).click(function(){
			var exp = new Date(2020,12,24);
			document.cookie = "vboxIgnoreVersion"+ver.string+"="+(this.checked ? 1 : 0)+"; expires="+exp.toGMTString()+"; path=/";
		});
		$(d).append(cb);
		$(d).append(' '+trans('Do not show message again'));
		
		vboxAlert(d);
		
	}
}

function strnatcasecmp(str1, str2) {
    // Returns the result of case-insensitive string comparison using 'natural' algorithm  
    // 
    // version: 1004.2314
    // discuss at: http://phpjs.org/functions/strnatcasecmp    // +      original by: Martin Pool
    // + reimplemented by: Pierre-Luc Paour
    // + reimplemented by: Kristof Coomans (SCK-CEN (Belgian Nucleair Research Centre))
    // + reimplemented by: Brett Zamir (http://brett-zamir.me)
    // +      bugfixed by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)    // *     example 1: strnatcasecmp(10, 1);
    // *     returns 1: 1
    // *     example 1: strnatcasecmp('1', '10');
    // *     returns 1: -1
    var a = (str1+'').toLowerCase();    var b = (str2+'').toLowerCase();
 
    var isWhitespaceChar = function (a) {
        return a.charCodeAt(0) <= 32;
    }; 
    var isDigitChar = function (a) {
        var charCode = a.charCodeAt(0);
        return ( charCode >= 48  && charCode <= 57 );
    }; 
    var compareRight = function (a,b) {
        var bias = 0;
        var ia = 0;
        var ib = 0; 
        var ca;
        var cb;
 
        // The longest run of digits wins.  That aside, the greatest        // value wins, but we can't know that it will until we've scanned
        // both numbers to know that they have the same magnitude, so we
        // remember it in BIAS.
        for (;; ia++, ib++) {
            ca = a.charAt(ia);            cb = b.charAt(ib);
 
            if (!isDigitChar(ca) &&
                !isDigitChar(cb)) {
                return bias;            } else if (!isDigitChar(ca)) {
                return -1;
            } else if (!isDigitChar(cb)) {
                return +1;
            } else if (ca < cb) {                if (bias == 0) {
                    bias = -1;
                }
            } else if (ca > cb) {
                if (bias == 0) {                    bias = +1;
                }
            } else if (ca == 0 && cb == 0) {
                return bias;
            }        }
    };
 
    var ia = 0, ib = 0;
    var nza = 0, nzb = 0;    var ca, cb;
    var result;
 
    while (true) {
        // only count the number of zeroes leading the last number compared        nza = nzb = 0;
 
        ca = a.charAt(ia);
        cb = b.charAt(ib);
         // skip over leading spaces or zeros
        while (isWhitespaceChar( ca ) || ca =='0') {
            if (ca == '0') {
                nza++;
            } else {                // only count consecutive zeroes
                nza = 0;
            }
 
            ca = a.charAt(++ia);        }
 
        while (isWhitespaceChar( cb ) || cb == '0') {
            if (cb == '0') {
                nzb++;            } else {
                // only count consecutive zeroes
                nzb = 0;
            }
             cb = b.charAt(++ib);
        }
 
        // process run of digits
        if (isDigitChar(ca) && isDigitChar(cb)) {            if ((result = compareRight(a.substring(ia), b.substring(ib))) != 0) {
                return result;
            }
        }
         if (ca == 0 && cb == 0) {
            // The strings compare the same.  Perhaps the caller
            // will want to call strcmp to break the tie.
            return nza - nzb;
        } 
        if (ca < cb) {
            return -1;
        } else if (ca > cb) {
            return +1;        }
 
        ++ia; ++ib;
    }
}
