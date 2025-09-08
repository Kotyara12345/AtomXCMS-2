// drunya.lib.js - Modernized version
class AtomXAdmin {
    constructor() {
        this.wRight = 0;
        this.wLeft = 0;
        this.wStep = 10;
        this.winTimeout = 50;
        this.openedWindows = [];
        this.menuItemOver = false;
    }

    toggle(id) {
        const el = $(`#${id}`);
        el.is(':visible') ? el.hide() : el.show();
    }

    toggleByClass(className) {
        const el = $(`.${className}`);
        el.is(':visible') ? el.hide() : el.show();
    }

    hideAll(className) {
        $(`.${className}`).hide();
    }

    // Autocompleter with sanitization
    findUsers(url, id) {
        let output = '';
        
        $.get(url, {}, (response) => {
            try {
                const users = JSON.parse(response);
                
                users.forEach((user) => {
                    const safeName = this.escapeHtml(user.name);
                    output += `<li><a href="../admin/users_rules.php?new_sp=${user.id}">${safeName}</a> (${user.id})</li>`;
                });
                
                $(`#${id}`).html(`<ul>${output}</ul>`);
            } catch (error) {
                console.error('Error parsing user data:', error);
            }
        }).fail(() => {
            console.error('Failed to fetch users');
        });
    }

    findUsersForForums(url, id, toUrl) {
        let output = '';
        
        $.get(url, {}, (response) => {
            try {
                const users = JSON.parse(response);
                
                users.forEach((user) => {
                    const safeName = this.escapeHtml(user.name);
                    let safeUrl = toUrl.replace(/%id/g, user.id)
                                       .replace(/%name/g, encodeURIComponent(user.name));
                    
                    output += `<li><a href="${safeUrl}">${safeName}</a> (${user.id})</li>`;
                });
                
                $(`#${id}`).html(`<ul>${output}</ul>`);
            } catch (error) {
                console.error('Error parsing user data:', error);
            }
        }).fail(() => {
            console.error('Failed to fetch users');
        });
    }

    initMultiFileUploadHandler(module, callback) {
        if (typeof callback === 'function') {
            this.parseResponse = callback;
        }

        const progressHandlingFunction = (e) => {
            if (e.lengthComputable) {
                $('progress').attr({ value: e.loaded, max: e.total });
            }
        };

        $('#preloader').hide();
        $('#attach').on('change', () => {
            const data = new FormData();
            $('#attach')[0].files.forEach((file, i) => {
                data.append(`attach${i + 1}`, file);
            });

            $.ajax({
                url: `/${module}/upload_attaches/`,
                type: 'POST',
                xhr: function() {
                    const myXhr = $.ajaxSettings.xhr();
                    if (myXhr.upload) {
                        myXhr.upload.addEventListener('progress', progressHandlingFunction, false);
                    }
                    return myXhr;
                },
                data: data,
                cache: false,
                contentType: false,
                dataType: 'json',
                processData: false,
                beforeSend: () => {
                    $('progress').show().attr({ value: '0', max: '10000' });
                    if (!$('#attaches-info').is(':visible')) {
                        $('#attaches-info').show();
                    }
                },
                success: (data) => {
                    this.parseResponse(module, data);
                    $('progress').hide();
                },
                error: () => {
                    $('#attaches-info').html('Some error during the files upload!');
                }
            });
        });
    }

    // Utility methods
    escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

    resizeWrapper(id) {
        const nheight = $(id).height();
        const wrapheight = $('#content-wrapper').height();
        
        if (nheight > wrapheight) {
            $('#content-wrapper').height(nheight);
        }
    }

    selectAclTab(id) {
        $('div.acl-perms-collection').each(function() {
            $(this).hide();
        });
        
        $(`div#aclset${id}`).show();
    }

    openPopup(id) {
        this.resizeWrapper($(`#${id}`));
        const dataTop = parseInt($(`#${id}`).data('top') || 0);
        const newTop = window.scrollY + dataTop;
        
        $(`#${id}`).css({
            'top': newTop
        }).fadeIn(1000);
        
        if (!$('div#overlay').is(':visible')) {
            $('div#overlay').fadeIn();
        }
    }

    closePopup(id) {
        $(`#${id}`).fadeOut(300, () => {
            if (!$('div.popup').is(':visible') && $('div#overlay').is(':visible')) {
                $('#overlay').fadeOut('fast', () => {
                    $('#overlay').hide();
                });
            }
        });
    }

    wiOpen(pref) {
        $(`#${pref}_dWin`).fadeIn(1000);
    }

    hideWin(pref) {
        $(`#${pref}_dWin`).fadeOut(500);
    }

    addWin(prefix) {
        $(`#${prefix}_add`).show();
        $(`#${prefix}_view`).hide();
    }

    subMenu(id) {
        this.menuItemOver = true;
        this.hideAllMenus();

        if (!$(`#${id}`).is(':visible')) {
            $(`#${id}`).slideDown();
        } else {
            $(`#${id}`).slideUp();
        }
    }

    save(prefix) {
        const inp = $(`#${prefix}_inp`).val().trim();
        const idSec = prefix === 'cat' ? $(`#${prefix}_secId`).val() : '';
        
        if (!inp || inp.length < 2) {
            alert('Слишком короткое название');
            return;
        }

        $.post('load_cat.php?ac=add', { 
            title: inp, 
            type: prefix, 
            id_sec: idSec 
        }, () => { 
            window.location.href = ''; 
        });
    }

    confirmAction() {
        return confirm('Вы уверены?');
    }

    showHelpWin(text, title) {
        const safeText = this.escapeHtml(text);
        const safeTitle = this.escapeHtml(title);
        
        const helpWin = $(`
            <div class="popup" id="help-window" style="display:block;">
                <div class="top">
                    <div class="title">${safeTitle}</div>
                    <div class="close" onClick="atomX.closeHelpPopup('help-window')"></div>
                </div>
                <div class="items text">
                    ${safeText}
                </div>
            </div>
        `);
        
        $('#content-wrapper').append(helpWin);
    }

    closeHelpPopup(id) {
        $(`#${id}`).fadeOut(400, function() {
            $(this).remove();
        });
    }

    // Menu functionality
    drunyaMenu(params) {
        let content = '<ul>';
        
        Object.keys(params).forEach((key) => {
            const param = params[key];
            content += `
                <li onClick="atomX.subMenu('topsub${key}');">
                    <a href="#">${this.escapeHtml(param[0])}</a>
                    <div id="topsub${key}" class="sub">
                        <div class="shadow"><ul>
            `;
            
            param[1].forEach((line) => {
                if (line === 'sep') {
                    content += '<li class="top-menu-sep"></li>';
                } else {
                    content += `<li>${line}</li>`;
                }
            });
            
            content += '</ul></div></div></li>';
        });
        
        $('#topmenu').html(content + '</ul>');
    }

    hideAllMenus() {
        $('#topmenu > ul > li .sub').each(function() {
            $(this).slideUp('fast');
        });
        
        $('.side-menu > ul > li .sub').each(function() {
            $(this).slideUp('fast');
        });
    }

    showSubMenu(id) {
        this.hideAllMenus();
        $(`#${id}`).show();
    }

    showScreenshot(path) {
        const img = $('#screenshot');
        if (img.length) {
            img.attr('src', path);
        }
    }
}

// FpsLib functionality as static class
class FpsLib {
    static showLoader() {
        $('#ajax-loader').show();
    }
    
    static hideLoader() {
        $('#ajax-loader').hide();
    }
}

// Global event handlers with proper scoping
document.addEventListener('click', () => {
    if (!atomX.menuItemOver) {
        atomX.hideAllMenus();
    }
    atomX.menuItemOver = false;
});

// Initialize global instance
const atomX = new AtomXAdmin();

// Export for module systems if needed
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { AtomXAdmin, FpsLib };
}
