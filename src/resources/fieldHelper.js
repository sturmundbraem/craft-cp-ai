// --- Position wand buttons (handles both initial load and AJAX-injected fields) ---
function positionWandButton(btn) {
    if (btn.dataset.positioned === '1') return;     // skip already-placed buttons
    var wrapper = btn.closest('.ai-wand-wrapper');

    var field = wrapper ? wrapper.closest('.field') : btn.closest('.field');
    if (wrapper && field) {
        var heading = null;
        Array.prototype.forEach.call(field.children, function(child) {
            if (!heading && child.classList && child.classList.contains('heading')) {
                heading = child;
            }
        });
        if (heading) {
            var spacer = heading.querySelector('.flex-grow');
            if (spacer) {
                spacer.before(wrapper);
            } else {
                heading.appendChild(wrapper);
            }
            wrapper.classList.add('is-positioned');
        }
    }
    btn.style.display = '';
    btn.dataset.positioned = '1';
}

function positionAllWands(root) {
    (root || document).querySelectorAll('.ai-wand-btn').forEach(positionWandButton);
}

function enabledFlag(value) {
    return value === true || value === 1 || value === '1';
}

function hasAllPlainTextPrompts() {
    if (typeof aiPrompts === 'undefined') return false;
    for (var key in aiPrompts) {
        if (enabledFlag(aiPrompts[key].allPlainText)) {
            return true;
        }
    }
    return false;
}

function addNativeWand(root, selector, handle, label) {
    if (!hasAllPlainTextPrompts()) return;
    (root || document).querySelectorAll(selector).forEach(function(input) {
        var field = input.closest('.field');
        if (!field || field.querySelector('.ai-wand-btn[data-field="' + handle + '"]')) return;

        var wrapper = document.createElement('div');
        wrapper.className = 'ai-wand-wrapper';

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ai-wand-btn btn small icon';
        btn.dataset.icon = 'wand-magic-sparkles';
        btn.dataset.field = handle;
        btn.dataset.label = label;        // ← add this
        btn.dataset.type = 'PlainText';

        wrapper.appendChild(btn);
        field.appendChild(wrapper);
        positionWandButton(btn);
    });
}

// Inject wands next to Craft's native (non-custom-field) text attributes.
// These are rendered by field-layout elements, not Field objects, so the
// server-side EVENT_DEFINE_INPUT_HTML never fires for them.
function addNativeFieldWands(root) {
    addNativeWand(root, 'input[name="title"], input[name$="[title]"]', 'title', 'Title');
    addNativeWand(root, 'textarea[name="alt"], textarea[name$="[alt]"]', 'alt', 'Alternative Text');
}

// Position any wand buttons already in the DOM
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        addNativeFieldWands();
        positionAllWands();
    });
} else {
    addNativeFieldWands();
    positionAllWands();
}

// Watch for new wand buttons added later (Matrix blocks, etc.)
new MutationObserver(function(mutations) {
    mutations.forEach(function(m) {
        m.addedNodes.forEach(function(node) {
            if (node.nodeType !== 1) return;                            // only Element nodes
            if (node.matches && node.matches('.ai-wand-btn')) {
                positionWandButton(node);
            } else if (node.querySelectorAll) {
                addNativeFieldWands(node);
                node.querySelectorAll('.ai-wand-btn').forEach(positionWandButton);
            }
        });
    });
}).observe(document.body, { childList: true, subtree: true });


// Listen for all clicks on the page
// We use event delegation: one listener on the whole document
// instead of adding listeners to each button individually
document.addEventListener('click', function(event) {

    // Check if the clicked element (or its parent) is a wand button
    // .closest() walks up the DOM tree looking for a match
    var btn = event.target.closest('.ai-wand-btn');

    // Only run this code if a wand button was clicked
    if (btn) {

        // Read the field handle and type from the button's data attributes
        // These were set in Plugin.php when the button was created
        var buttonField = btn.dataset.field;   // e.g. "subtitle"
        var buttonType = btn.dataset.type;     // e.g. "PlainText"
        var fieldLabel = btn.dataset.label;   // e.g. "Subtitle" (for display in the menu header)

        // If a dropdown menu is already open, close it and stop
        // This makes the wand button work as a toggle (click to open, click to close)
        var existingMenu = document.querySelector('.ai-prompt-menu');
        if (existingMenu) {
            existingMenu.dispatchEvent(new CustomEvent('ai:close'));
            existingMenu.remove();
            return;
        }

        var prompts = {};
        var matrixField = btn.dataset.matrixField;   // undefined on non-Matrix fields

        var assignedSelf = aiFieldAssignments[buttonField] || [];
        var assignedMatrix = matrixField ? (aiFieldAssignments[matrixField] || []) : [];
        var assignedAll = assignedSelf.concat(assignedMatrix);

        if (assignedAll.length > 0) {
            assignedAll.forEach(function(uid) {
                if (aiPrompts[uid]) {
                    prompts[uid] = aiPrompts[uid];   // dedups by uid since same uid = same key
                }
            });
        } else {
            for (var key in aiPrompts) {
                var p = aiPrompts[key];
                if (buttonType === 'PlainText' && enabledFlag(p.allPlainText)) {
                    prompts[key] = p;
                }
                if (buttonType === 'CKEditor' && enabledFlag(p.allCKEditor)) {
                    prompts[key] = p;
                }
            }
        }

        // Create the dropdown menu container
        var menu = document.createElement('div');
        menu.className = 'ai-prompt-menu';

        var header = document.createElement('div');
        header.className = 'ai-prompt-menu-header';

        var headerTitle = document.createElement('span');
        headerTitle.className = 'ai-prompt-menu-title';
        headerTitle.textContent = fieldLabel;
        header.appendChild(headerTitle);

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'ai-prompt-menu-close';
        closeBtn.textContent = 'x';
        function closeMenu() {
            menu.remove();
            document.removeEventListener('click', closeOnOutsideClick);
        }
        function closeOnOutsideClick(e) {
            if (e.target.closest('.ai-prompt-menu') || e.target.closest('.ai-wand-btn')) return;
            closeMenu();
        }
        setTimeout(function() {
            document.addEventListener('click', closeOnOutsideClick);
        }, 0);

        closeBtn.addEventListener('click', closeMenu);
        header.appendChild(closeBtn);

        closeBtn.addEventListener('click', closeMenu);
        header.appendChild(closeBtn);
        menu.appendChild(header);  
                
        menu.addEventListener('ai:close', function() {
        document.removeEventListener('click', closeOnOutsideClick);
    });

        // Loop through each prompt and create a menu button for it
        // key = the label shown to the user (e.g. "summarize")
        // prompts[key] = the actual prompt text sent to the AI

        var list = document.createElement('div');
        list.className = 'ai-prompt-menu-list';

        for (var promptKey in prompts) {
            var menuItem = document.createElement('button');
            menuItem.type = 'button';
            menuItem.style.display = 'flex';
            menuItem.style.justifyContent = 'space-between';
            menuItem.style.alignItems = 'center';
            menuItem.style.width = '100%';


            var labelText = prompts[promptKey].label;
            if (prompts[promptKey].createDraft === '1') {
                labelText = labelText + ' (Draft)';
            }
            var labelSpan = document.createElement('span');
            labelSpan.textContent = labelText;
            menuItem.appendChild(labelSpan);

            var provider = prompts[promptKey].provider;
            if (provider) {
                var icon = document.createElement('img');
                icon.src = aiIconBase + '/' + provider + '.svg';
                icon.alt = provider;
                icon.style.width = '16px';
                icon.style.height = '16px';
                icon.style.verticalAlign = 'middle';
                icon.style.marginLeft = '6px';
                menuItem.appendChild(icon);
            }

            menuItem.dataset.promptUid = prompts[promptKey].uid || promptKey;
            menuItem.dataset.prompt = prompts[promptKey].text;
            menuItem.dataset.provider = prompts[promptKey].provider;
            menuItem.dataset.createDraft = prompts[promptKey].createDraft;
            list.appendChild(menuItem);
        }

        menu.appendChild(list);
        // Position the dropdown menu right below the wand button
        // getBoundingClientRect() returns the button's position on screen
        var rect = btn.getBoundingClientRect();
        menu.style.position = 'fixed';          // Fixed = positioned relative to the browser window
        menu.style.top = rect.bottom + 'px';    // Below the button
        menu.style.left = rect.left + 'px';     // Aligned to the button's left edge
        document.body.appendChild(menu);         // Add the menu to the page

        var isDragging = false;
        var dragOffsetX = 0;
        var dragOffsetY = 0;

        header.addEventListener('mousedown', function(e) {
            if (e.target === closeBtn) return;
            isDragging = true;
            var menuRect = menu.getBoundingClientRect();
            dragOffsetX = e.clientX - menuRect.left;
            dragOffsetY = e.clientY - menuRect.top;
            e.preventDefault();
        });

        document.addEventListener('mousemove', function(e) {
            if (!isDragging) return;
            menu.style.left = (e.clientX - dragOffsetX) + 'px';
            menu.style.top = (e.clientY - dragOffsetY) + 'px';
        });

        document.addEventListener('mouseup', function() {
            isDragging = false;
        });

            list.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var item = e.target.closest('button');
            if (!item) return;

            var clickedFieldContainer = btn.closest('.field');
            var targetInput = clickedFieldContainer
                ? clickedFieldContainer.querySelector('input[name="title"], input[name$="[title]"], textarea[name="alt"], textarea[name$="[alt]"], input[name*="fields"], textarea[name*="fields"]')
                : null;
            
            var selectedPromptUid = item.dataset.promptUid;
            var selectedPrompt = item.dataset.prompt;
            var selectedProvider = item.dataset.provider;
            var selectedCreateDraft = item.dataset.createDraft;
            var wandForm = btn.closest('form');

            var blockContainer = btn.closest('.matrixblock');

            var elementId, siteId, liveScope;
            if (blockContainer && blockContainer.dataset.id) {
                // Blocks view: use the block's own entry ID + site, and scope live values to the block
                elementId = blockContainer.dataset.id;
                siteId    = blockContainer.dataset.siteId;
                liveScope = blockContainer;
            } else {
                // Top-level field, or Cards-view slideout: use the form's inputs
                var entryIdInput = wandForm.querySelector('input[name="elementId"], input[name$="[elementId]"]');
                var siteIdInput  = wandForm.querySelector('input[name="siteId"], input[name$="[siteId]"]');
                elementId = entryIdInput.value;
                siteId    = siteIdInput.value;
                liveScope = wandForm;
            }

            document.querySelectorAll('.ai-wand-btn').forEach(function(b) { b.disabled = true; });
            
            menu.remove();

            // Show a loading spinner over the target field while we wait for the AI.
            // Find the field container, then float a fixed-position overlay over it.
            var loadingField = btn.closest('.field');
            var overlay = document.createElement('div');
            overlay.className = 'ai-loading-overlay';
            overlay.innerHTML = '<div class="spinner"></div>';
            if (loadingField) {
                var fieldRect = loadingField.getBoundingClientRect();
                overlay.style.position = 'fixed';
                overlay.style.top = fieldRect.top + 'px';
                overlay.style.left = fieldRect.left + 'px';
                overlay.style.width = fieldRect.width + 'px';
                overlay.style.height = fieldRect.height + 'px';
                document.body.appendChild(overlay);
            }
            var liveValues = {};   
            // Grab whatever the user has currently typed (not yet saved to DB)
            liveScope.querySelectorAll(
                'input[name*="[fields]["], textarea[name*="[fields]["], input[name^="fields["], textarea[name^="fields["]'
            ).forEach(function(el) {
                // Skip inputs that belong to a DIFFERENT element scope.
                // Top-level wand   → blockContainer is null, so we exclude anything inside any .matrixblock
                // Block wand       → blockContainer is the block wrapper, so we exclude inputs in other blocks AND top-level inputs
                if (el.closest('.matrixblock') !== blockContainer) return;

                var matches = [...el.name.matchAll(/\[fields\]\[([^\]]+)\]|^fields\[([^\]]+)\]/g)];
                var last = matches[matches.length - 1];
                if (last) liveValues[last[1] || last[2]] = el.value;
            });

            var titleInput = liveScope.querySelector('input[name="title"], input[name$="[title]"]');
            if (titleInput && titleInput.closest('.matrixblock') !== blockContainer) {
                titleInput = null;   // belongs to a different scope
            }
            if (titleInput) liveValues.__title = titleInput.value;

            fetch(Craft.getActionUrl('craft-loki/content/generate'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': Craft.csrfTokenValue,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    entryId: elementId,
                    provider: selectedProvider,
                    siteId: siteId,
                    fieldHandle: buttonField,
                    promptUid: selectedPromptUid,
                    prompt: selectedPrompt,
                    createDraft: selectedCreateDraft,
                    liveValues: liveValues,
                })
            })
            .then(function(response) { 
                if (!response.ok) {
                    throw new Error('Server returned ' + response.status);
                }
                return response.json(); 
            })

            .then(function(data) {
                overlay.remove();
                document.querySelectorAll('.ai-wand-btn').forEach(function(b) { b.disabled = false; });
                if (data.error) {
                    alert('Error: ' + data.error);
                } else if (data.draftUrl) {
                    Craft.cp.displayNotice('Draft created!');
                    window.location.href = data.draftUrl;
                } else {
                    // using the
                    //  captured field - not its handle
                    var fieldEl = targetInput;

                    var editorInstance = fieldEl && fieldEl.ckeditorInstance;
                    if (!editorInstance && clickedFieldContainer) {
                        clickedFieldContainer.querySelectorAll('.ck-editor__editable').forEach(function(el) {
                            if (el.ckeditorInstance && !editorInstance) {
                                editorInstance = el.ckeditorInstance;
                            }
                        });
                    }

                    if (editorInstance) {
                        editorInstance.setData(data.generatedContent);
                    } else {
                        fieldEl.value = data.generatedContent;
                        fieldEl.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                    
                    Craft.cp.displayNotice('Field filled — review and save when ready.');
                }

            })
            .catch(function(error) {
                overlay.remove();
                document.querySelectorAll('.ai-wand-btn').forEach(function(b) { b.disabled = false; });
                alert('Error: ' + error.message);
            });

        });
    }
});
