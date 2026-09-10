/**
 * AIMF Secure Contact Form — Form Builder JavaScript
 *
 * Drag-and-drop form builder with field configuration.
 * Vanilla JS — no jQuery dependency.
 */
(function () {
    'use strict';

    // Only run on the edit page.
    var editWrap = document.querySelector('.ascf-edit-wrap');
    if (!editWrap) {
        // We're on the forms list page — wire up delete/duplicate buttons.
        initListPage();
        return;
    }

    var config = window.ascf_builder || {};
    var i18n = config.i18n || {};
    var fieldTypes = config.fieldTypes || {};

    // Load form data from the embedded JSON.
    var formDataScript = document.getElementById('ascf-form-data');
    var formData = formDataScript ? JSON.parse(formDataScript.textContent) : { id: 0, title: '', fields: [], settings: {} };

    // DOM references.
    var fieldsContainer = document.getElementById('ascf-fields-container');
    var settingsPanel = document.getElementById('ascf-field-settings');
    var titleInput = document.getElementById('ascf-form-title');
    var saveBtn = document.getElementById('ascf-save-form');
    var saveStatus = document.getElementById('ascf-save-status');
    var copyBtn = document.getElementById('ascf-copy-shortcode');
    var shortcodeInput = document.getElementById('ascf-shortcode-input');

    var selectedFieldIndex = -1;

    /**
     * Generate a unique field ID.
     */
    function genFieldId() {
        return 'fld_' + Math.random().toString(36).substr(2, 10);
    }

    /**
     * Create a new field definition with defaults.
     */
    function createField(type) {
        var field = {
            id: genFieldId(),
            type: type,
            label: fieldTypes[type] ? fieldTypes[type].label : 'Text',
            required: false,
            placeholder: '',
            default_value: '',
            description: '',
            css_class: '',
        };
        if (type === 'select' || type === 'radio' || type === 'checkbox') {
            field.options = ['Option 1', 'Option 2'];
        }
        if (type === 'number') {
            field.min = '';
            field.max = '';
        }
        return field;
    }

    /**
     * Render the fields list in the canvas.
     */
    function renderFields() {
        fieldsContainer.innerHTML = '';

        if (!formData.fields || formData.fields.length === 0) {
            fieldsContainer.innerHTML = '<p class="ascf-no-fields">' + (i18n.noFields || 'No fields yet.') + '</p>';
            return;
        }

        formData.fields.forEach(function (field, index) {
            var el = document.createElement('div');
            el.className = 'ascf-builder-field' + (index === selectedFieldIndex ? ' selected' : '');
            el.dataset.index = index;

            var typeLabel = fieldTypes[field.type] ? fieldTypes[field.type].label : field.type;
            var reqBadge = field.required ? '<span class="ascf-badge-required">Required</span>' : '';

            el.innerHTML =
                '<div class="ascf-field-handle">⋮⋮</div>' +
                '<div class="ascf-field-info">' +
                    '<span class="ascf-field-type-label">' + escHtml(typeLabel) + '</span>' +
                    '<span class="ascf-field-label-text">' + escHtml(field.label || '(no label)') + '</span>' +
                    reqBadge +
                '</div>' +
                '<div class="ascf-field-controls">' +
                    '<button type="button" class="ascf-btn-move-up" title="Move up">↑</button>' +
                    '<button type="button" class="ascf-btn-move-down" title="Move down">↓</button>' +
                    '<button type="button" class="ascf-btn-remove" title="Remove">✕</button>' +
                '</div>';

            // Click to select.
            el.addEventListener('click', function (e) {
                if (e.target.closest('.ascf-btn-remove') || e.target.closest('.ascf-btn-move-up') || e.target.closest('.ascf-btn-move-down')) {
                    return;
                }
                selectField(index);
            });

            // Remove button.
            el.querySelector('.ascf-btn-remove').addEventListener('click', function (e) {
                e.stopPropagation();
                if (confirm(i18n.confirmRemoveField || 'Remove this field?')) {
                    formData.fields.splice(index, 1);
                    if (selectedFieldIndex === index) {
                        selectedFieldIndex = -1;
                        renderSettings();
                    }
                    renderFields();
                }
            });

            // Move up.
            el.querySelector('.ascf-btn-move-up').addEventListener('click', function (e) {
                e.stopPropagation();
                if (index > 0) {
                    var tmp = formData.fields[index - 1];
                    formData.fields[index - 1] = formData.fields[index];
                    formData.fields[index] = tmp;
                    if (selectedFieldIndex === index) selectedFieldIndex = index - 1;
                    else if (selectedFieldIndex === index - 1) selectedFieldIndex = index;
                    renderFields();
                    renderSettings();
                }
            });

            // Move down.
            el.querySelector('.ascf-btn-move-down').addEventListener('click', function (e) {
                e.stopPropagation();
                if (index < formData.fields.length - 1) {
                    var tmp = formData.fields[index + 1];
                    formData.fields[index + 1] = formData.fields[index];
                    formData.fields[index] = tmp;
                    if (selectedFieldIndex === index) selectedFieldIndex = index + 1;
                    else if (selectedFieldIndex === index + 1) selectedFieldIndex = index;
                    renderFields();
                    renderSettings();
                }
            });

            fieldsContainer.appendChild(el);
        });
    }

    /**
     * Select a field and render its settings.
     */
    function selectField(index) {
        selectedFieldIndex = index;
        renderFields();
        renderSettings();
    }

    /**
     * Render the settings panel for the selected field.
     */
    function renderSettings() {
        if (selectedFieldIndex < 0 || !formData.fields[selectedFieldIndex]) {
            settingsPanel.innerHTML =
                '<h3>' + escHtml(i18n.settings || 'Settings') + '</h3>' +
                '<p class="ascf-settings-empty">' + escHtml(i18n.noFields || 'Click a field to edit its settings.') + '</p>';
            renderFormSettings();
            return;
        }

        var field = formData.fields[selectedFieldIndex];
        var typeLabel = fieldTypes[field.type] ? fieldTypes[field.type].label : field.type;
        var hasOptions = field.type === 'select' || field.type === 'radio' || field.type === 'checkbox';
        var isNumber = field.type === 'number';

        var html = '<h3>' + escHtml(typeLabel) + ' ' + escHtml(i18n.settings || 'Settings') + '</h3>';

        html += '<div class="ascf-setting-row">';
        html += '<label>' + escHtml(i18n.fieldLabel || 'Label') + '</label>';
        html += '<input type="text" data-key="label" value="' + escAttr(field.label || '') + '" />';
        html += '</div>';

        if (field.type !== 'checkbox' && field.type !== 'radio') {
            html += '<div class="ascf-setting-row">';
            html += '<label>' + escHtml(i18n.placeholder || 'Placeholder') + '</label>';
            html += '<input type="text" data-key="placeholder" value="' + escAttr(field.placeholder || '') + '" />';
            html += '</div>';
        }

        html += '<div class="ascf-setting-row">';
        html += '<label>' + escHtml(i18n.defaultValue || 'Default Value') + '</label>';
        html += '<input type="text" data-key="default_value" value="' + escAttr(field.default_value || '') + '" />';
        html += '</div>';

        html += '<div class="ascf-setting-row">';
        html += '<label>' + escHtml(i18n.description || 'Description') + '</label>';
        html += '<input type="text" data-key="description" value="' + escAttr(field.description || '') + '" />';
        html += '</div>';

        if (hasOptions) {
            html += '<div class="ascf-setting-row">';
            html += '<label>' + escHtml(i18n.options || 'Options (one per line)') + '</label>';
            var opts = (field.options || []).join('\n');
            html += '<textarea data-key="options" rows="5">' + escHtml(opts) + '</textarea>';
            html += '</div>';
        }

        if (isNumber) {
            html += '<div class="ascf-setting-row ascf-setting-inline">';
            html += '<div><label>' + escHtml(i18n.min || 'Min') + '</label><input type="number" data-key="min" value="' + escAttr(field.min || '') + '" /></div>';
            html += '<div><label>' + escHtml(i18n.max || 'Max') + '</label><input type="number" data-key="max" value="' + escAttr(field.max || '') + '" /></div>';
            html += '</div>';
        }

        html += '<div class="ascf-setting-row">';
        html += '<label>' + escHtml(i18n.cssClass || 'CSS Class') + '</label>';
        html += '<input type="text" data-key="css_class" value="' + escAttr(field.css_class || '') + '" />';
        html += '</div>';

        html += '<div class="ascf-setting-row ascf-setting-check">';
        html += '<label><input type="checkbox" data-key="required" ' + (field.required ? 'checked' : '') + ' /> ' + escHtml(i18n.required || 'Required') + '</label>';
        html += '</div>';

        settingsPanel.innerHTML = html;

        // Wire up setting inputs.
        var inputs = settingsPanel.querySelectorAll('[data-key]');
        inputs.forEach(function (input) {
            input.addEventListener('input', function () {
                var key = input.dataset.key;
                if (key === 'required') {
                    formData.fields[selectedFieldIndex].required = input.checked;
                } else if (key === 'options') {
                    var lines = input.value.split('\n').map(function (l) { return l.trim(); }).filter(function (l) { return l.length > 0; });
                    formData.fields[selectedFieldIndex].options = lines;
                } else {
                    formData.fields[selectedFieldIndex][key] = input.value;
                }
                renderFields();
            });
        });

        renderFormSettings();
    }

    /**
     * Render the form-level settings at the bottom of the settings panel.
     */
    function renderFormSettings() {
        var s = formData.settings || {};
        var html = '<div class="ascf-form-settings-section">';
        html += '<h3>' + escHtml(i18n.settings || 'Form Settings') + '</h3>';

        html += '<div class="ascf-setting-row">';
        html += '<label>' + escHtml(i18n.notificationEmail || 'Notification Email') + '</label>';
        html += '<input type="email" data-form-key="notification_email" value="' + escAttr(s.notification_email || '') + '" />';
        html += '</div>';

        html += '<div class="ascf-setting-row">';
        html += '<label>' + escHtml(i18n.successMessage || 'Success Message') + '</label>';
        html += '<input type="text" data-form-key="success_message" value="' + escAttr(s.success_message || '') + '" />';
        html += '</div>';

        html += '<div class="ascf-setting-row">';
        html += '<label>' + escHtml(i18n.submitButtonText || 'Submit Button Text') + '</label>';
        html += '<input type="text" data-form-key="submit_button_text" value="' + escAttr(s.submit_button_text || '') + '" />';
        html += '</div>';

        html += '</div>';

        settingsPanel.insertAdjacentHTML('beforeend', html);

        // Wire up form settings inputs.
        settingsPanel.querySelectorAll('[data-form-key]').forEach(function (input) {
            input.addEventListener('input', function () {
                var key = input.dataset.formKey;
                if (!formData.settings) formData.settings = {};
                formData.settings[key] = input.value;
            });
        });
    }

    /**
     * Save the form via AJAX.
     */
    function saveForm() {
        saveBtn.disabled = true;
        saveStatus.textContent = i18n.saving || 'Saving...';
        saveStatus.className = 'ascf-save-status saving';

        formData.title = titleInput.value;

        var fd = new FormData();
        fd.set('action', 'ascf_save_form');
        fd.set('nonce', config.nonce);
        fd.set('form_data', JSON.stringify(formData));

        fetch(config.ajaxUrl, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                saveBtn.disabled = false;
                if (data.success) {
                    saveStatus.textContent = i18n.saved || 'Saved!';
                    saveStatus.className = 'ascf-save-status saved';
                    // Update form ID if it was new.
                    if (data.data && data.data.id) {
                        formData.id = data.data.id;
                    }
                    setTimeout(function () {
                        saveStatus.textContent = '';
                        saveStatus.className = 'ascf-save-status';
                    }, 3000);
                } else {
                    saveStatus.textContent = (data.data && data.data.message) || (i18n.saveError || 'Error');
                    saveStatus.className = 'ascf-save-status error';
                }
            })
            .catch(function () {
                saveBtn.disabled = false;
                saveStatus.textContent = i18n.saveError || 'Error';
                saveStatus.className = 'ascf-save-status error';
            });
    }

    /**
     * Copy shortcode to clipboard.
     */
    function copyShortcode() {
        if (shortcodeInput) {
            shortcodeInput.select();
            document.execCommand('copy');
            copyBtn.textContent = i18n.copied || 'Copied!';
            setTimeout(function () {
                copyBtn.textContent = i18n.copyShortcode || 'Copy';
            }, 2000);
        }
    }

    /**
     * HTML escape helpers.
     */
    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }
    function escAttr(s) {
        return String(s).replace(/"/g, '"').replace(/</g, '<').replace(/>/g, '>');
    }

    // --- Wire up events ---

    // Add field type buttons.
    document.querySelectorAll('.ascf-field-type-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var type = btn.dataset.type;
            var field = createField(type);
            formData.fields.push(field);
            selectedFieldIndex = formData.fields.length - 1;
            renderFields();
            renderSettings();
        });
    });

    // Save button.
    if (saveBtn) saveBtn.addEventListener('click', saveForm);

    // Copy shortcode.
    if (copyBtn) copyBtn.addEventListener('click', copyShortcode);

    // Title input updates formData on blur.
    if (titleInput) {
        titleInput.addEventListener('input', function () {
            formData.title = titleInput.value;
        });
    }

    // Initial render.
    renderFields();
    renderSettings();

    // --- Forms list page handlers ---

    function initListPage() {
        // Delete buttons.
        document.querySelectorAll('.ascf-delete-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!confirm(i18n.confirmDelete || 'Delete this form?')) return;
                var formId = btn.dataset.formId;
                var fd = new FormData();
                fd.set('action', 'ascf_delete_form');
                fd.set('nonce', config.nonce);
                fd.set('form_id', formId);
                fetch(config.ajaxUrl, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.success) {
                            btn.closest('tr').style.opacity = '0';
                            setTimeout(function () { btn.closest('tr').remove(); }, 300);
                        } else {
                            alert((data.data && data.data.message) || 'Error');
                        }
                    });
            });
        });

        // Duplicate buttons.
        document.querySelectorAll('.ascf-duplicate-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var formId = btn.dataset.formId;
                var fd = new FormData();
                fd.set('action', 'ascf_duplicate_form');
                fd.set('nonce', config.nonce);
                fd.set('form_id', formId);
                fetch(config.ajaxUrl, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.success && data.data.editUrl) {
                            window.location.href = data.data.editUrl;
                        } else {
                            alert((data.data && data.data.message) || 'Error');
                        }
                    });
            });
        });
    }
})();
