define(function(require) {
    'use strict';

    const _ = require('underscore');
    const $ = require('jquery');
    const __ = require('orotranslation/js/translator');
    const mediator = require('oroui/js/mediator');
    const BaseComponent = require('oroui/js/app/components/base/component');
    const ErrorHolderView = require('../views/inline-editing/error-holder-view');
    const overlayTool = require('oroui/js/tools/overlay');

    const CellPopupEditorComponent = BaseComponent.extend({
        /**
         * Key codes
         */
        TAB_KEY_CODE: 9,
        ENTER_KEY_CODE: 13,
        ESCAPE_KEY_CODE: 27,
        ARROW_LEFT_KEY_CODE: 37,
        ARROW_TOP_KEY_CODE: 38,
        ARROW_RIGHT_KEY_CODE: 39,
        ARROW_BOTTOM_KEY_CODE: 40,

        /**
         * If true interface should not respond to user actions.
         * Useful for grid page switching support
         */
        lockUserActions: false,

        OVERLAY_TOOL_DEFAULTS: {
            zIndex: 1,
            position: {
                my: 'left top',
                at: 'left top',
                collision: 'flipfit'
            }
        },

        listen: {
            saveAction: 'saveCurrentCell',
            cancelAction: 'cancelEditing',
            saveAndExitAction: 'saveCurrentCell',
            saveAndEditNextAction: 'saveCurrentCellAndEditNext',
            cancelAndEditNextAction: 'editNextCell',
            saveAndEditPrevAction: 'saveCurrentCellAndEditPrev',
            cancelAndEditPrevAction: 'editPrevCell',
            saveAndEditNextRowAction: 'saveCurrentCellAndEditNextRow',
            cancelAndEditNextRowAction: 'editNextRowCell',
            saveAndEditPrevRowAction: 'saveCurrentCellAndEditPrevRow',
            cancelAndEditPrevRowAction: 'editPrevRowCell'
        },

        /**
         * @inheritdoc
         */
        constructor: function CellPopupEditorComponent(options) {
            CellPopupEditorComponent.__super__.constructor.call(this, options);
        },

        /**
         * @inheritdoc
         */
        initialize: function(options) {
            this.options = options || {};
            if (!this.options.plugin) {
                throw new Error('Option "plugin" is required');
            }
            if (!this.options.cell) {
                throw new Error('Option "cell" is required');
            }
            if (!this.options.view) {
                throw new Error('Option "view" is required');
            }
            if (!this.options.save_api_accessor) {
                throw new Error('Option "save_api_accessor" is required');
            }
            this.errorHolderView = new ErrorHolderView({
                el: this.options.cell.$el[0],
                within: this.options.plugin.main.$('.other-scroll-container')
            });
            this.listenTo(this.options.plugin.main, 'scroll', function() {
                this.errorHolderView.updatePosition();
            });
            this.listenTo(this.options.plugin, 'lockUserActions', function(value) {
                this.lockUserActions = value;
            });
            this.listenTo(this.options.cell, 'dispose', () => this.dispose());
            CellPopupEditorComponent.__super__.initialize.call(this, options);
            this.enterEditMode();
        },

        createView: function() {
            const View = this.options.view;
            const cell = this.options.cell;
            const viewOptions = _.extend({}, this.options.viewOptions, this.getRestrictedOptions(), {
                autoRender: true,
                model: cell.model,
                fieldName: cell.column.get('name'),
                metadata: cell.column.get('metadata'),
                cell
            });
            if (this.formState) {
                this.updateModel(cell.model, this.oldState);
                this.errorHolderView.render();
                this.options.plugin.main.trigger('content:update');
                viewOptions.value = this.formState;
            }
            const viewInstance = this.view = new View(viewOptions);

            viewInstance.$el.addClass('inline-editor-wrapper');

            const position = {
                of: cell.$el,
                within: cell.$el.closest('tbody')
            };

            const isAlignedRight = cell.el && getComputedStyle(cell.el).textAlign === 'right';

            if (isAlignedRight) {
                $.extend(position, {
                    at: 'right top',
                    my: 'right top'
                });
            }

            const overlayOptions = $.extend(true, {}, this.OVERLAY_TOOL_DEFAULTS, {
                insertInto: cell.$el,
                position: position
            });

            this.resizeToCell(viewInstance, cell);
            const overlay = overlayTool.createOverlay(viewInstance.$el, overlayOptions);
            viewInstance.trigger('change:visibility');

            this.listenTo(viewInstance, {
                dispose: function() {
                    overlay.remove();
                },
                change: function() {
                    cell.$el.toggleClass('has-error', !viewInstance.isValid());
                    this.errorHolderView.updatePosition();
                },
                keydown: this.onKeyDown,
                focus: function() {
                    overlay.focus();
                    this.errorHolderView.updatePosition();
                },
                blur: function() {
                    overlay.blur();
                    if (viewInstance.isValid()) {
                        this.saveCurrentCell();
                    } else {
                        this.options.cell.$el.toggleClass('has-error', !this.view.isValid());
                        this.formState = this.view.getFormState();
                        const modelUpdateData = this.view.getModelUpdateData();
                        this.oldState = _.pick(this.options.cell.model.toJSON(), _.keys(modelUpdateData));
                        this.exitEditMode(); // have to exit first, before model is updated, to dispose view properly
                        this.updateModel(this.options.cell.model, modelUpdateData);
                        this.errorHolderView.render();
                        this.options.plugin.main.trigger('content:update');
                        this.errorHolderView.updatePosition();
                    }
                }
            });
            viewInstance.trigger('change');
            this.errorHolderView.render();
        },

        getRestrictedOptions: function() {
            const entityRestrictions = this.options.cell.model.get('entity_restrictions');
            const applicableRestrictions = _.filter(entityRestrictions, function(restriction) {
                return restriction.field === this.options.cell.column.get('name');
            }, this);

            const restrictedOptions = {};
            _.each(applicableRestrictions, function(restriction) {
                if (restriction.mode === 'disallow') {
                    restrictedOptions.choices = _.omit(
                        this.options.viewOptions.choices,
                        restriction.values
                    );
                } else if (restriction.mode === 'allow') {
                    restrictedOptions.choices = _.pick(
                        this.options.viewOptions.choices,
                        restriction.values
                    );
                }
            }, this);

            return restrictedOptions;
        },

        /**
         * Resizes editor to cell width
         */
        resizeToCell: function(view, cell) {
            view.$el.width(cell.$el.outerWidth());
        },

        /**
         * Saves current cell and returns flag if was saved successfully or promise object
         *
         * @return {boolean|Promise}
         */
        saveCurrentCell: function() {
            if (!this.view.isChanged()) {
                this.exitEditMode(true);
                return true;
            }
            if (!this.view.isValid()) {
                return false;
            }

            const {cell, plugin} = this.options;
            let serverUpdateData = this.getServerUpdateData();
            this.applyDivisor(serverUpdateData, false);
            this.formState = this.view.getFormState();
            const modelUpdateData = this.view.getModelUpdateData();
            cell.$el.addClass('loading');
            this.oldState = _.pick(cell.model.toJSON(), _.keys(modelUpdateData));
            this.exitEditMode(); // have to exit first, before model is updated, to dispose view properly

            this.updateModel(cell.model, modelUpdateData);
            this.errorHolderView.render();
            plugin.main.trigger('content:update');
            if (this.options.save_api_accessor.initialOptions.field_name) {
                const keys = _.keys(serverUpdateData);
                if (keys.length > 1) {
                    throw new Error('Only single field editors are supported with field_name option');
                }
                const newData = {};
                newData[this.options.save_api_accessor.initialOptions.field_name] = serverUpdateData[keys[0]];
                serverUpdateData = newData;
            }
            let savePromise = this.options.save_api_accessor.send(cell.model.toJSON(), serverUpdateData, {}, {
                processingMessage: __('oro.form.inlineEditing.saving_progress'),
                preventWindowUnload: __('oro.form.inlineEditing.inline_edits'),
                errorHandlerMessage: false
            });
            if (this.constructor.processSavePromise) {
                savePromise = this.constructor.processSavePromise(savePromise, cell.column.get('metadata'));
            }
            if (this.options.view.processSavePromise) {
                savePromise = this.options.view.processSavePromise(savePromise, cell.column.get('metadata'));
            }
            savePromise.done(this.onSaveSuccess.bind(this))
                .fail(this.onSaveError.bind(this))
                .always(() => {
                    plugin.main.trigger('content:update');
                    cell.$el.removeClass('loading');
                });
            return savePromise;
        },

        updateModel: function(model, updateData) {
            // assume "undefined" as delete value request
            for (const key in updateData) {
                if (updateData.hasOwnProperty(key)) {
                    if (updateData[key] === void 0) {
                        model.unset(key);
                        delete updateData[key];
                    }
                }
            }
            model.set(updateData);
        },

        /**
         * Shows editor view (create first if it did not exist)
         */
        enterEditMode: function() {
            this.options.grid.trigger('grid-cell:enter-edit-mode', this.options.cell);
            if (!this.view) {
                this.options.cell.$el.removeClass('view-mode save-fail');
                this.options.cell.$el.addClass('edit-mode');
                this.createView(this.options);
                // rethrow view events on component
                this.listenTo(this.view, 'all', function(eventName, ...args) {
                    if (eventName !== 'dispose') {
                        this.trigger(eventName, ...args);
                    }
                }, this);
            }
        },

        /**
         * Hides editor view and removes listeners
         *
         * @param {boolean=} withDispose if passed true disposes the component
         */
        exitEditMode: function(withDispose) {
            if (this.view) {
                this.errorHolderView.parseValidatorErrors(this.view.validator.errorList);
                if (!this.options.cell.disposed) {
                    this.options.cell.$el.removeClass('edit-mode').addClass('view-mode');
                }
                this.view.dispose();
                this.stopListening(this.view);
                delete this.view;
            }

            this.options.grid.trigger('grid-cell:exit-edit-mode', this.options.cell);

            if (withDispose) {
                this.dispose();
            }
        },

        toggleHeaderCellHighlight: function(cell, state) {
            const columnIndex = this.options.plugin.main.columns.indexOf(cell.column);
            const headerCell = this.options.plugin.main.findHeaderCellByIndex(columnIndex);
            if (headerCell) {
                headerCell.$el.toggleClass('header-cell-highlight', state);
            }
        },

        revertChanges: function() {
            if (!this.options.cell.disposed && this.oldState) {
                this.options.cell.model.set(this.oldState);
                delete this.oldState;
                this.options.plugin.main.trigger('content:update');
            }
        },

        cancelEditing: function() {
            this.revertChanges();
            this.exitEditMode(true);
        },

        editNextCell: function() {
            this.exitAndNavigate('editNextCell');
        },

        editNextRowCell: function() {
            this.exitAndNavigate('editNextRowCell');
        },

        editPrevCell: function() {
            this.exitAndNavigate('editPrevCell');
        },

        editPrevRowCell: function() {
            this.exitAndNavigate('editPrevRowCell');
        },

        exitAndNavigate: function(method) {
            const plugin = this.options.plugin;
            const cell = this.options.cell;
            plugin[method](cell);
        },

        saveCurrentCellAndEditNext: function() {
            this.saveAndNavigate('editNextCell');
        },

        saveCurrentCellAndEditPrev: function() {
            this.saveAndNavigate('editPrevCell');
        },

        saveCurrentCellAndEditNextRow: function() {
            this.saveAndNavigate('editNextRowCell');
        },

        saveCurrentCellAndEditPrevRow: function() {
            this.saveAndNavigate('editPrevRowCell');
        },

        saveAndNavigate: function(method) {
            const plugin = this.options.plugin;
            const cell = this.options.cell;
            if (this.isNavigationAvailable()) {
                plugin[method](cell);
            }
        },

        /**
         * Keydown handler for the editor view
         *
         * @param {$.Event} e
         */
        onKeyDown: function(e) {
            this.onGenericTabKeydown(e);
            this.onGenericEnterKeydown(e);
            this.onGenericEscapeKeydown(e);
            this.onGenericArrowKeydown(e);
        },

        /**
         * Generic keydown handler, which handles ENTER
         *
         * @param {$.Event} e
         */
        onGenericEnterKeydown: function(e) {
            if (this.disposed) {
                return;
            }
            const plugin = this.options.plugin;
            const cell = this.options.cell;
            if (e.keyCode === this.ENTER_KEY_CODE && !e.ctrlKey && this.isNavigationAvailable()) {
                if (e.shiftKey) {
                    plugin.editPrevRowCell(cell);
                } else {
                    plugin.editNextRowCell(cell);
                }
                e.preventDefault();
            }
        },

        /**
         * Generic keydown handler, which handles TAB
         *
         * @param {$.Event} e
         */
        onGenericTabKeydown: function(e) {
            if (this.disposed) {
                return;
            }
            const plugin = this.options.plugin;
            const cell = this.options.cell;
            if (e.keyCode === this.TAB_KEY_CODE && this.isNavigationAvailable()) {
                if (e.shiftKey) {
                    plugin.editPrevCell(cell);
                } else {
                    plugin.editNextCell(cell);
                }
                e.preventDefault();
            }
        },

        /**
         * Generic keydown handler, which handles ESCAPE
         *
         * @param {$.Event} e
         */
        onGenericEscapeKeydown: function(e) {
            if (e.keyCode === this.ESCAPE_KEY_CODE) {
                if (!this.lockUserActions) {
                    this.revertChanges();
                    this.exitEditMode(true);
                }
                e.preventDefault();
            }
        },

        /**
         * Generic keydown handler, which handles ARROWS
         *
         * @param {$.Event} e
         */
        onGenericArrowKeydown: function(e) {
            if (this.disposed) {
                return;
            }
            const plugin = this.options.plugin;
            const cell = this.options.cell;
            if (e.altKey && this.isNavigationAvailable()) {
                switch (e.keyCode) {
                    case this.ARROW_LEFT_KEY_CODE:
                        plugin.editPrevCell(cell);
                        e.preventDefault();
                        break;
                    case this.ARROW_RIGHT_KEY_CODE:
                        plugin.editNextCell(cell);
                        e.preventDefault();
                        break;
                    case this.ARROW_TOP_KEY_CODE:
                        plugin.editPrevRowCell(cell);
                        e.preventDefault();
                        break;
                    case this.ARROW_BOTTOM_KEY_CODE:
                        plugin.editNextRowCell(cell);
                        e.preventDefault();
                        break;
                }
            }
        },

        isNavigationAvailable: function() {
            return !this.lockUserActions && (!this.view || this.view.isValid());
        },

        isChanged: function() {
            return this.view && this.view.isChanged() || this.oldState;
        },

        onSaveSuccess: function(response) {
            if (!this.options.cell.disposed && this.options.cell.$el) {
                if (response) {
                    if (response.hasOwnProperty('fields') ||
                        /*
                         * Make cell work with responses sending changed values directly
                         * to not make bc break
                         */
                        _.every(_.keys(response), function(property) {
                            return this.options.cell.model.attributes.hasOwnProperty(property);
                        }, this)
                    ) {
                        const fields = response.hasOwnProperty('fields') ? response.fields : response;
                        this.applyDivisor(fields, true);
                        const routeParamsRenameMap = _.invert(this.options.save_api_accessor.routeParametersRenameMap);
                        _.each(fields, function(item, i) {
                            const propName = routeParamsRenameMap.hasOwnProperty(i) ? routeParamsRenameMap[i] : i;
                            if (this.options.cell.model.get(propName) !== void 0) {
                                this.options.cell.model.set(propName, item);
                            }
                        }, this);
                    } else if (response.hasOwnProperty('httpMethod') && response.httpMethod === 'DELETE') {
                        _.each(this.options.save_api_accessor.routeParametersRenameMap, function(v, property) {
                            this.options.cell.model.set(property, '');
                        }, this);
                    }
                }
                this.options.cell.$el
                    .removeClass('save-fail')
                    .addClassTemporarily('save-success', 2000);
            }
            mediator.execute('showFlashMessage', 'success', __('oro.form.inlineEditing.successMessage'));
            delete this.oldState;
            delete this.formState;
            this.errorHolderView.setErrorMessages({});
            this.exitEditMode(true);
        },

        onSaveError: function(jqXHR) {
            const errorCode = 'responseJSON' in jqXHR && 'code' in jqXHR.responseJSON
                ? jqXHR.responseJSON.code
                : jqXHR.status;

            const errors = [];
            let fieldLabel;

            if (errorCode === 400) {
                this.onValidationError(jqXHR);
                return;
            }

            if (!this.options.cell.disposed) {
                fieldLabel = this.options.cell.column.get('label');
                this.options.cell.$el.addClass('save-fail');
            }

            switch (errorCode) {
                case 403:
                    errors.push(__('oro.datagrid.inline_editing.message.save_field.permission_denied',
                        {fieldLabel: fieldLabel}));
                    break;
                case 500:
                    if (jqXHR.responseJSON.message) {
                        errors.push(__(jqXHR.responseJSON.message));
                    } else {
                        errors.push(__('oro.ui.unexpected_error'));
                    }
                    break;
                default:
                    errors.push(__('oro.ui.unexpected_error'));
            }

            this.revertChanges();
            this.exitEditMode(true);

            _.each(errors, function(value) {
                mediator.execute('showMessage', 'error', value);
            });
        },

        onValidationError: function(jqXHR) {
            let fieldErrors;
            let backendErrors;
            const responseErrors = _.result(jqXHR.responseJSON, 'errors');
            if (responseErrors) {
                _.each(responseErrors.children, function(item) {
                    if (_.isArray(item.errors)) {
                        mediator.execute('showMessage', 'error', item.errors[0]);
                    }
                });
                if (_.isArray(responseErrors.errors)) {
                    mediator.execute('showMessage', 'error', __(responseErrors.errors[0]));
                }
                fieldErrors = _.result(responseErrors.children, this.options.cell.column.get('name'));
                if (!fieldErrors && this.options.viewOptions !== 'undefined' &&
                    this.options.viewOptions.value_field_name !== 'undefined'
                ) {
                    fieldErrors = _.result(responseErrors.children, this.options.viewOptions.value_field_name);
                }

                if (fieldErrors && _.isArray(fieldErrors.errors)) {
                    backendErrors = {value: __(fieldErrors.errors[0])};
                } else if (_.isArray(responseErrors.errors)) {
                    backendErrors = {value: __(responseErrors.errors[0])};
                }
                this.errorHolderView.setErrorMessages(backendErrors);
            } else if (_.isArray(jqXHR.responseJSON)) {
                const allErrors = _.chain(jqXHR.responseJSON)
                    .map(_.property('detail'))
                    .filter()
                    .value();

                if (this.disposed || this.options.cell.disposed) {
                    _.each(allErrors, _.partial(mediator.execute, 'showMessage', 'error'));
                } else {
                    const error = _.first(allErrors);
                    if (error) {
                        this.errorHolderView.setErrorMessages({value: error});
                    }
                }
            }
        },

        /**
         * @return {Object}
         */
        getServerUpdateData: function() {
            return this.view.getServerUpdateData();
        },

        applyDivisor: function(fields, toResponse) {
            const metadata = this.options.cell.column.attributes.metadata;
            const fieldName = metadata.name;
            if (_.has(metadata, 'divisor')) {
                if (!isNaN(fields[fieldName])) {
                    fields[fieldName] = toResponse
                        ? fields[fieldName] / metadata.divisor
                        : fields[fieldName] * metadata.divisor;
                }
            }
        }
    });

    return CellPopupEditorComponent;
});
